<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateMemberRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $idAccount = (int) $this->route('id_account');

        return [
            'nama' => ['sometimes', 'string', 'max:255'],
            'password' => ['sometimes', 'string', 'min:8', 'confirmed'],
            'id_cabang' => ['sometimes', 'integer', 'exists:cabangs,id_cabang'],
            'nip' => ['sometimes', 'string', 'max:50', Rule::unique('pengurus', 'nip')->ignore($idAccount, 'id_account')],
            'email' => ['sometimes', 'email', 'max:255'],
            'alamat' => ['sometimes', 'string'],
            'no_hp' => ['sometimes', 'string', 'max:15'],
            'status' => ['sometimes', 'in:Calon,Aktif,Non-Aktif,Tertunda,Tidak Aktif'],
        ];
    }
}
