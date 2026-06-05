<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cabang extends Model
{
    protected $table = 'cabangs';
    protected $primaryKey = 'id_cabang';
    public $timestamps = false;

    protected $fillable = [
        'nama_cabang',
        'kota',
        'lokasi',
    ];

    public function pengurus()
    {
        return $this->hasMany(Pengurus::class, 'id_cabang', 'id_cabang');
    }

    public function kasirs()
    {
        return $this->hasMany(Kasir::class, 'id_cabang', 'id_cabang');
    }

    public function gudangs()
    {
        return $this->hasMany(Gudang::class, 'id_cabang', 'id_cabang');
    }

    public function anggotas()
    {
        return $this->hasMany(Anggota::class, 'id_cabang', 'id_cabang');
    }

    public function jurnals()
    {
        return $this->hasMany(Jurnal::class, 'id_cabang', 'id_cabang');
    }
}
