<?php

namespace App\Http\Controllers;

use App\Http\Resources\SimpananResource;
use App\Models\Anggota;
use App\Models\Simpanan;
use App\Traits\ApiResponse;
use App\Traits\ResolvesCabangScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Services\JurnalService;

class SimpananController extends Controller
{
    use ApiResponse, ResolvesCabangScope;

    public function index(Request $request): JsonResponse
    {
        if ($request->string('view')->toString() === 'summary') {
            return $this->summary($request);
        }

        $perPage = min(max((int) $request->integer('per_page', 25), 1), 100);
        $query = Simpanan::query()
            ->select([
                'id_simpanan',
                'id_anggota',
                'jenis_simpanan',
                'jumlah',
                'tanggal',
                'bukti_transfer',
                'status',
            ])
            ->with('anggota:id_anggota,nama_anggota,status,id_cabang');

        if ($request->has('id_anggota')) {
            $query->where('id_anggota', $request->id_anggota);
        }

        if ($request->has('jenis')) {
            $query->where('jenis_simpanan', $request->jenis);
        }

        $cabangScope = $this->resolveCabangScope($request);
        if ($cabangScope !== null) {
            $query->whereHas('anggota', fn ($q) => $q->where('id_cabang', $cabangScope));
        }

        if ($request->user()?->role === 'Anggota') {
            $query->where('id_anggota', $request->user()->anggota->id_anggota);
        }

        $data = $request->boolean('paginate', true)
            ? $query->orderByDesc('id_simpanan')->paginate($perPage)
            : $query->orderByDesc('id_simpanan')->get();

        return $this->successResponse(
            'Riwayat simpanan berhasil diambil.',
            SimpananResource::collection($data)
        );
    }

    private function summary(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 25), 1), 100);
        $query = Anggota::query()
            ->select(['id_anggota', 'nama_anggota', 'status', 'id_cabang'])
            ->selectSub(
                Simpanan::query()
                    ->selectRaw('COALESCE(SUM(jumlah), 0)')
                    ->whereColumn('simpanans.id_anggota', 'anggotas.id_anggota')
                    ->where('status', 'Verified')
                    ->where('jenis_simpanan', 'Pokok'),
                'simpanan_pokok'
            )
            ->selectSub(
                Simpanan::query()
                    ->selectRaw('COALESCE(SUM(jumlah), 0)')
                    ->whereColumn('simpanans.id_anggota', 'anggotas.id_anggota')
                    ->where('status', 'Verified')
                    ->where('jenis_simpanan', 'Wajib'),
                'simpanan_wajib'
            )
            ->selectSub(
                Simpanan::query()
                    ->selectRaw('COALESCE(SUM(jumlah), 0)')
                    ->whereColumn('simpanans.id_anggota', 'anggotas.id_anggota')
                    ->where('status', 'Verified')
                    ->where('jenis_simpanan', 'Sukarela'),
                'simpanan_sukarela'
            )
            ->selectSub(
                Simpanan::query()
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('simpanans.id_anggota', 'anggotas.id_anggota')
                    ->where('status', 'Pending'),
                'pending_simpanan'
            );

        $cabangScope = $this->resolveCabangScope($request);
        if ($cabangScope !== null) {
            $query->where('id_cabang', $cabangScope);
        }

        if ($request->user()?->role === 'Anggota') {
            $query->where('id_anggota', $request->user()->anggota->id_anggota);
        }

        if ($request->filled('search')) {
            $query->where('nama_anggota', 'like', '%' . $request->string('search')->toString() . '%');
        }

        $data = $query
            ->orderByDesc(DB::raw('(simpanan_pokok + simpanan_wajib + simpanan_sukarela)'))
            ->paginate($perPage);

        $data->getCollection()->transform(fn (Anggota $anggota) => [
            'id_anggota' => $anggota->id_anggota,
            'nama_anggota' => $anggota->nama_anggota,
            'status' => $anggota->status,
            'simpanan_pokok' => (float) $anggota->simpanan_pokok,
            'simpanan_wajib' => (float) $anggota->simpanan_wajib,
            'simpanan_sukarela' => (float) $anggota->simpanan_sukarela,
            'total_simpanan' => (float) $anggota->simpanan_pokok + (float) $anggota->simpanan_wajib + (float) $anggota->simpanan_sukarela,
            'pending_simpanan' => (int) $anggota->pending_simpanan,
        ]);

        return $this->successResponse('Ringkasan simpanan berhasil diambil.', $data);
    }

    public function rules(): JsonResponse
    {
        return $this->successResponse('Aturan simpanan berhasil diambil.', [
            'simpanan_wajib_bulanan' => (float) config('koperasi.simpanan_wajib_bulanan'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $isMember = $request->user()?->role === 'Anggota';
        $mandatoryAmount = (float) config('koperasi.simpanan_wajib_bulanan');

        // Nominal simpanan wajib tidak pernah dipercaya dari input klien.
        if ($request->input('jenis_simpanan') === 'Wajib') {
            $request->merge(['jumlah' => $mandatoryAmount]);
        }

        $validator = Validator::make($request->all(), [
            'id_anggota' => 'nullable|exists:anggotas,id_anggota',
            'jenis_simpanan' => 'required|in:Pokok,Wajib,Sukarela',
            'jumlah' => 'required|numeric|min:1',
            'tanggal' => 'nullable|date',
            'bukti_transfer' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validasi gagal.', $validator->errors(), 422);
        }

        $idAnggota = $isMember ? $request->user()?->anggota?->id_anggota : $request->id_anggota;
        if (! $idAnggota) {
            return $this->errorResponse('Anggota harus dipilih.', null, 422);
        }

        if ($isMember && ! $request->hasFile('bukti_transfer')) {
            return $this->errorResponse('Bukti transfer wajib diunggah.', null, 422);
        }

        $anggota = Anggota::find($idAnggota);
        if ($anggota?->status !== 'Aktif') {
            return $this->errorResponse('Simpanan hanya dapat dicatat untuk anggota aktif.', null, 422);
        }

        $tanggalSetoran = $request->tanggal ?? now()->format('Y-m-d');
        if ($request->jenis_simpanan === 'Wajib') {
            $sudahAdaSetoranWajib = Simpanan::where('id_anggota', $idAnggota)
                ->where('jenis_simpanan', 'Wajib')
                ->whereIn('status', ['Pending', 'Verified'])
                ->whereYear('tanggal', date('Y', strtotime($tanggalSetoran)))
                ->whereMonth('tanggal', date('m', strtotime($tanggalSetoran)))
                ->exists();

            if ($sudahAdaSetoranWajib) {
                return $this->errorResponse('Simpanan wajib untuk bulan ini sudah diajukan atau diverifikasi.', null, 422);
            }
        }

        $path = $request->hasFile('bukti_transfer')
            ? $request->file('bukti_transfer')->store('bukti_simpanan', 'public')
            : null;

        $simpanan = Simpanan::create([
            'id_anggota' => $idAnggota,
            'jenis_simpanan' => $request->jenis_simpanan,
            'jumlah' => $request->jumlah,
            'tanggal' => $tanggalSetoran,
            'bukti_transfer' => $path,
            'status' => $isMember ? 'Pending' : 'Verified',
        ]);

        if (! $isMember) {
            app(JurnalService::class)->catatSimpananMasuk($simpanan->load('anggota'));
        }

        return $this->successResponse(
            $isMember ? 'Bukti setoran berhasil diunggah, mohon tunggu verifikasi.' : 'Simpanan berhasil dicatat.',
            $simpanan,
            201
        );
    }

    public function verify(int $id_simpanan): JsonResponse
    {
        $simpanan = Simpanan::with('anggota')->findOrFail($id_simpanan);
        if ($simpanan->status !== 'Pending') {
            return $this->errorResponse('Setoran ini sudah diproses.', null, 400);
        }

        $simpanan->update(['status' => 'Verified']);
        app(JurnalService::class)->catatSimpananMasuk($simpanan->fresh('anggota'));

        return $this->successResponse('Setoran simpanan berhasil diverifikasi.', $simpanan->fresh('anggota'));
    }

    public function reject(int $id_simpanan): JsonResponse
    {
        $simpanan = Simpanan::findOrFail($id_simpanan);
        if ($simpanan->status !== 'Pending') {
            return $this->errorResponse('Setoran ini sudah diproses.', null, 400);
        }

        $simpanan->update(['status' => 'Rejected']);
        return $this->successResponse('Setoran simpanan berhasil ditolak.', $simpanan->fresh('anggota'));
    }

    public function cekSaldo(int $id_anggota): JsonResponse
    {
        $anggota = Anggota::find($id_anggota);
        if (! $anggota) {
            return $this->errorResponse('Anggota tidak ditemukan.', null, 404);
        }

        $totalSaldo = Simpanan::where('id_anggota', $id_anggota)->where('status', 'Verified')->sum('jumlah');

        $rincian = Simpanan::where('id_anggota', $id_anggota)
            ->where('status', 'Verified')
            ->selectRaw('jenis_simpanan, SUM(jumlah) as subtotal')
            ->groupBy('jenis_simpanan')
            ->get();

        return $this->successResponse('Saldo simpanan berhasil diambil.', [
            'id_anggota' => $anggota->id_anggota,
            'nomor_anggota' => $anggota->nomor_anggota,
            'nama_anggota' => $anggota->nama_anggota,
            'total_saldo' => (float) $totalSaldo,
            'rincian' => $rincian,
        ]);
    }
}
