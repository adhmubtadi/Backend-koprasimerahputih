<?php

namespace App\Services;

use App\Events\DashboardUpdated;
use App\Events\SaleCreated;
use App\Events\StockUpdated;
use App\Models\DetailTransaksi;
use App\Models\BranchProductStock;
use App\Models\Kasir;
use App\Models\Produk;
use App\Models\TransaksiPos;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TransactionService
{
    /**
     * @return array{transaksi: TransaksiPos, details: array<int, DetailTransaksi>, warnings: array<int, string>}
     */
    public function checkout(array $data): array
    {
        $warnings = [];
        $details = [];
        $stockUpdates = [];
        $hpp = 0.0;
        $threshold = (int) config('koperasi.stok_warning_threshold', 100);

        DB::beginTransaction();

        try {
            $kasir = Kasir::with('cabang')->find($data['id_kasir'] ?? null);
            if (! $kasir) {
                throw new RuntimeException('Kasir tidak ditemukan.');
            }

            $items = $data['items'] ?? [];
            $subTotal = 0.0;

            if (empty($items)) {
                throw new RuntimeException('Items transaksi tidak boleh kosong.');
            }

            $requestedItems = collect($items)
                ->groupBy('id_produk')
                ->map(fn ($rows, $idProduk) => [
                    'id_produk' => (int) $idProduk,
                    'jumlah' => (int) $rows->sum('jumlah'),
                ])
                ->values();

            $produkQuery = Produk::query()
                ->select(['id_produk', 'id_cabang', 'nama_produk', 'harga_beli', 'harga_jual', 'stok'])
                ->whereIn('id_produk', $requestedItems->pluck('id_produk'));

            $produkById = $produkQuery->get()->keyBy('id_produk');
            $stockByProductId = BranchProductStock::query()
                ->where('id_cabang', $kasir->id_cabang)
                ->whereIn('id_produk', $requestedItems->pluck('id_produk'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id_produk');

            foreach ($requestedItems as $item) {
                $produk = $produkById->get($item['id_produk']);
                $branchStock = $stockByProductId->get($item['id_produk']);

                if (! $produk || ! $branchStock) {
                    throw new RuntimeException('Produk dengan id '.$item['id_produk'].' tidak ditemukan di cabang ini.');
                }

                $jumlah = (int) $item['jumlah'];
                if ($jumlah <= 0) {
                    throw new RuntimeException('Jumlah item harus lebih dari 0.');
                }

                if ($branchStock->stok < $jumlah) {
                    throw new RuntimeException('Stok produk '.$produk->nama_produk.' tidak mencukupi.');
                }

                $branchStock->stok -= $jumlah;
                $branchStock->save();
                $stockUpdates[] = [
                    'id_cabang' => (int) $kasir->id_cabang,
                    'id_produk' => (int) $produk->id_produk,
                    'stok' => (int) $branchStock->stok,
                ];

                if ($branchStock->stok < $threshold) {
                    $warnings[] = 'Stok menipis (< '.$threshold.' pcs): '.$produk->nama_produk;
                }

                $hargaSatuan = (float) $produk->harga_jual;
                $subTotal += $hargaSatuan * $jumlah;
                $hpp += (float) $produk->harga_beli * $jumlah;

                $details[] = [
                    'id_produk' => $produk->id_produk,
                    'jumlah' => $jumlah,
                    'harga_satuan' => $hargaSatuan,
                ];
            }

            $ppn = isset($data['ppn']) ? (float) $data['ppn'] : round($subTotal * 0.11);
            $totalBayar = $subTotal + $ppn;

            $transaksi = TransaksiPos::create([
                'id_kasir' => $kasir->id_kasir,
                'id_anggota' => $data['id_anggota'] ?? null,
                'tanggal_jam' => $data['tanggal_jam'] ?? now(),
                'total_bayar' => $totalBayar,
                'ppn' => $ppn,
            ]);

            $savedDetails = [];
            foreach ($details as $detail) {
                $savedDetails[] = DetailTransaksi::create([
                    'id_transaksi' => $transaksi->id_transaksi,
                    'id_produk' => $detail['id_produk'],
                    'jumlah' => $detail['jumlah'],
                    'harga_satuan' => $detail['harga_satuan'],
                ]);
            }

            $transaksiForJurnal = $transaksi->fresh()->load(['kasir', 'detailTransaksi.produk']);
            app(JurnalService::class)->catatTransaksiPosDanHpp($transaksiForJurnal);

            DB::commit();
            $transaksi->load(['kasir.cabang', 'detailTransaksi.produk']);
            $this->broadcastPosUpdates($stockUpdates, $transaksi, $hpp);

            return [
                'transaksi' => $transaksi,
                'details' => $savedDetails,
                'warnings' => $warnings,
            ];
        } catch (\Throwable $th) {
            DB::rollBack();
            throw $th;
        }
    }

    private function broadcastPosUpdates(array $stockUpdates, TransaksiPos $transaksi, float $hpp): void
    {
        try {
            foreach ($stockUpdates as $stockUpdate) {
                broadcast(new StockUpdated([$stockUpdate]));
            }
            broadcast(new SaleCreated([
                'id_transaksi' => $transaksi->id_transaksi,
                'id_kasir' => $transaksi->id_kasir,
                'id_anggota' => $transaksi->id_anggota,
                'tanggal_jam' => optional($transaksi->tanggal_jam)->toISOString(),
                'total_bayar' => (float) $transaksi->total_bayar,
                'ppn' => (float) $transaksi->ppn,
                'hpp' => $hpp,
                'kasir' => [
                    'id_kasir' => $transaksi->kasir?->id_kasir,
                    'nama_kasir' => $transaksi->kasir?->nama_kasir,
                    'cabang' => [
                        'id_cabang' => $transaksi->kasir?->cabang?->id_cabang,
                        'nama_cabang' => $transaksi->kasir?->cabang?->nama_cabang,
                    ],
                ],
            ]));
            broadcast(new DashboardUpdated('sale.created', (int) $transaksi->kasir?->id_cabang));
        } catch (\Throwable $e) {
            Log::warning('POS websocket broadcast failed.', [
                'id_transaksi' => $transaksi->id_transaksi,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
