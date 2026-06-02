<?php

namespace App\Http\Requests;

class RegisterAnggotaRequest extends BaseApiRequest
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
            'nama_anggota' => ['required', 'string', 'max:255'],
            'alamat' => ['required', 'string'],
            'no_hp' => ['required', 'string', 'max:15'],
            'email' => ['required', 'email', 'max:255', 'unique:anggotas,email'],
            'id_cabang' => ['required', 'integer', 'exists:cabangs,id_cabang'],
        ];
    }
}
