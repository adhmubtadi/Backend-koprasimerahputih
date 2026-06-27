<?php

namespace App\Services;

use App\Models\Supplier;

class SupplierService
{
    public function getAllSuppliers(?string $search = null)
    {
        $query = Supplier::query()->select(['id_supplier', 'nama_supplier', 'alamat']);

        if ($search) {
            $query->where('nama_supplier', 'like', "%{$search}%")
                  ->orWhere('alamat', 'like', "%{$search}%");
        }

        return $query->orderBy('nama_supplier')->limit(200)->get();
    }

    public function createSupplier(array $data): Supplier
    {
        return Supplier::create($data);
    }

    public function updateSupplier(int $id, array $data): Supplier
    {
        $supplier = Supplier::findOrFail($id);
        $supplier->update($data);
        return $supplier;
    }
}
