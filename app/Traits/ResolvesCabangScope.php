<?php

namespace App\Traits;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;

trait ResolvesCabangScope
{
    /**
     * Admin tidak dibatasi cabang (null).
     * Role operasional dibatasi ke cabang profil masing-masing.
     */
    protected function resolveCabangScope(Request $request): ?int
    {
        $user = $request->user();
        if (! $user) {
            return null;
        }

        if ($user->role === 'Admin') {
            return $request->filled('id_cabang') ? (int) $request->query('id_cabang') : null;
        }

        return match ($user->role) {
            'Pengurus' => $user->pengurus?->id_cabang,
            'Kasir' => $user->kasir?->id_cabang,
            'Gudang' => $user->gudang?->id_cabang,
            'Anggota' => $user->anggota?->id_cabang,
            default => null,
        };
    }

    protected function applyCabangScope(Builder $query, Request $request, string $column = 'id_cabang'): void
    {
        $cabangId = $this->resolveCabangScope($request);
        if ($cabangId !== null) {
            $query->where($column, $cabangId);
        }   
    }
}
