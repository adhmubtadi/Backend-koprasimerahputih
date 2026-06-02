<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateAnggotaRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $idAnggota = (int) $this->route('id_anggota');

        return [
            'nama_anggota' => ['sometimes', 'string', 'max:255'],
            'alamat' => ['sometimes', 'string'],
            'no_hp' => ['sometimes', 'string', 'max:15'],
            'email' => [
                'sometimes',
                'email',
                'max:255',
                Rule::unique('anggotas', 'email')->ignore($idAnggota, 'id_anggota'),
            ],
            'id_cabang' => ['sometimes', 'integer', 'exists:cabangs,id_cabang'],
            'status' => ['sometimes', 'in:Calon,Aktif'],
        ];
    }
}
