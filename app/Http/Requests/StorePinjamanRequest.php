<?php

namespace App\Http\Requests;

class StorePinjamanRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenors = implode(',', config('koperasi.pinjaman_tenor_bulan', [6, 12, 18, 24]));

        return [
            'id_anggota' => ['required', 'integer', 'exists:anggotas,id_anggota'],
            'id_pengurus_acc' => ['nullable', 'integer', 'exists:pengurus,id_pengurus'],
            'jumlah_pinjaman' => ['required', 'numeric', 'min:1'],
            'tenor' => ['required', 'in:6,12,18,24'],
            'tanggal_pengajuan' => ['required', 'date'],
            'status' => ['nullable', 'in:Pending,Approved,Rejected'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $user = $this->user();

        if ($user?->role === 'Anggota' && $user->anggota) {
            $this->merge([
                'id_anggota' => $user->anggota->id_anggota,
                'tanggal_pengajuan' => $this->input('tanggal_pengajuan', now()->toDateString()),
            ]);
        }
    }
}
