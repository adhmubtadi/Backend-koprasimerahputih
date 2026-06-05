<?php

namespace App\Http\Requests;

class StoreStaffUserRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'min:3', 'max:50', 'alpha_dash', 'unique:accounts,username'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', 'in:Pengurus,Kasir,Gudang'],
            'nama' => ['required', 'string', 'max:255'],
            'id_cabang' => ['required', 'integer', 'exists:cabangs,id_cabang'],
            'nip' => ['nullable', 'string', 'max:50', 'unique:pengurus,nip'],
        ];
    }
}
