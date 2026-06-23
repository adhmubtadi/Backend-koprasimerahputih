<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckoutRequest;
use App\Http\Resources\TransactionResource;
use App\Models\TransaksiPos;
use App\Services\TransactionService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class TransactionController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TransactionService $transactionService)
    {
    }

    public function checkout(CheckoutRequest $request): JsonResponse
    {
        try {
            $result = $this->transactionService->checkout($request->validated());

            return $this->successResponse(
                message: 'Checkout berhasil diproses.',
                data: [
                    'transaksi' => new TransactionResource($result['transaksi']),
                    'warnings' => $result['warnings'],
                ],
                code: 201
            );
        } catch (\Throwable $e) {
            $code = 500;
            $msg = $e->getMessage();
            if (str_contains($msg, 'tidak mencukupi') || str_contains($msg, 'stok') || str_contains($msg, 'Stok')) {
                $code = 422;
            }
            return $this->errorResponse($msg, null, $code);
        }
    }

    /**
     * Struk / detail transaksi untuk keperluan cetak.
     */
    public function receipt(int $id_transaksi): JsonResponse
    {
        try {
            $transaksi = TransaksiPos::with(['kasir.cabang', 'detailTransaksi.produk'])->find($id_transaksi);
            if (! $transaksi) {
                return $this->errorResponse('Transaksi tidak ditemukan.', null, 404);
            }

            return $this->successResponse(
                message: 'Struk transaksi berhasil diambil.',
                data: new TransactionResource($transaksi),
                code: 200
            );
        } catch (\Throwable $e) {
            return $this->errorResponse('Terjadi kesalahan saat mengambil struk.', $e->getMessage(), 500);
        }
    }
}
