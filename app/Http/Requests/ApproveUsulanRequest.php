<?php

namespace App\Http\Requests;

class ApproveUsulanRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'id_usulan' => $this->route('id_usulan'),
        ]);
    }

    public function rules(): array
    {
        return [
            'id_usulan' => ['required', 'integer', 'exists:usulan_stoks,id_usulan'],
            'harga_jual' => ['required', 'numeric', 'min:0'],
        ];
    }
}
