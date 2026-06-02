<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Anggota extends Model
{
    protected $table = 'anggotas';
    protected $primaryKey = 'id_anggota';
    public $timestamps = false;

    protected $fillable = [
        'id_account',
        'nomor_anggota',
        'nama_anggota',
        'alamat',
        'no_hp',
        'email',
        'tanggal_daftar',
        'status',
        'id_cabang',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_daftar' => 'date',
        ];
    }

    public function account()
    {
        return $this->belongsTo(Account::class, 'id_account', 'id_account');
    }
    public function admin()
    {
        return $this->hasOne(Admin::class, 'id_account', 'id_account');
    }
    public function pengurus()
    {
        return $this->hasOne(Pengurus::class, 'id_account', 'id_account');
    }
    public function kasir()
    {
        return $this->hasOne(Kasir::class, 'id_account', 'id_account');
    }
    public function gudang()
    {
        return $this->hasOne(Gudang::class, 'id_account', 'id_account');
    }
    public function cabang()
    {
        return $this->belongsTo(Cabang::class, 'id_cabang', 'id_cabang');
    }

    public function simpanans()
    {
        return $this->hasMany(Simpanan::class, 'id_anggota', 'id_anggota');
    }

    public function pinjamans()
    {
        return $this->hasMany(Pinjaman::class, 'id_anggota', 'id_anggota');
    }

    public function transaksiPos()
    {
        return $this->hasMany(TransaksiPos::class, 'id_anggota', 'id_anggota');
    }
}
