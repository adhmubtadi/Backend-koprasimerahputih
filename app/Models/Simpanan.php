<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Simpanan extends Model
{
    protected $table = 'simpanans';
    protected $primaryKey = 'id_simpanan';
    public $timestamps = false;

    protected $fillable = [
        'id_anggota',
        'jenis_simpanan',
        'jumlah',
        'tanggal',
        'bukti_transfer',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
        ];
    }

    public function anggota()
    {
        return $this->belongsTo(Anggota::class, 'id_anggota', 'id_anggota');
    }
}
