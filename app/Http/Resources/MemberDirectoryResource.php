<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberDirectoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id_account' => $this->resource['id_account'],
            'id_profile' => $this->resource['id_profile'] ?? null,
            'nama' => $this->resource['nama'],
            'email' => $this->resource['email'],
            'username' => $this->resource['username'],
            'peran' => $this->resource['peran'],
            'status' => $this->resource['status'],
            'status_label' => $this->resource['status_label'],
            'telepon' => $this->resource['telepon'],
            'id_cabang' => $this->resource['id_cabang'] ?? null,
            'nama_cabang' => $this->resource['nama_cabang'] ?? null,
            'inicial' => $this->resource['inicial'],
        ];
    }
}
