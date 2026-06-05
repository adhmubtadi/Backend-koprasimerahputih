<?php

namespace App\Http\Requests;

class CheckoutRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_kasir' => ['sometimes', 'integer', 'exists:kasirs,id_kasir'],
            'id_anggota' => ['nullable', 'integer', 'exists:anggotas,id_anggota'],
            'tanggal_jam' => ['nullable', 'date'],
            'ppn' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id_produk' => ['required', 'integer', 'exists:produks,id_produk'],
            'items.*.jumlah' => ['required', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $user = $this->user();
        if ($user?->role === 'Kasir' && $user->kasir) {
            $this->merge([
                'id_kasir' => $user->kasir->id_kasir,
            ]);
        }
    }
}
