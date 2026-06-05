<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StoreMemberRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $role = $this->input('role');

        $rules = [
            'username' => ['required', 'string', 'min:3', 'max:50', 'alpha_dash', 'unique:accounts,username'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', 'in:Admin,Pengurus,Kasir,Gudang,Anggota'],
            'nama' => ['required', 'string', 'max:255'],
        ];

        if (in_array($role, ['Pengurus', 'Kasir', 'Gudang', 'Anggota'], true)) {
            $rules['id_cabang'] = ['required', 'integer', 'exists:cabangs,id_cabang'];
        }

        if ($role === 'Pengurus') {
            $rules['nip'] = ['nullable', 'string', 'max:50', 'unique:pengurus,nip'];
        }

        if ($role === 'Anggota') {
            $rules['email'] = ['required', 'email', 'max:255', 'unique:anggotas,email'];
            $rules['alamat'] = ['required', 'string'];
            $rules['no_hp'] = ['required', 'string', 'max:15'];
            $rules['status'] = ['nullable', 'in:Calon,Aktif,Non-Aktif'];
        }

        return $rules;
    }
}
