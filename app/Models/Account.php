<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Account extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'accounts';
    protected $primaryKey = 'id_account';
    public $timestamps = false;

    protected $fillable = [
        'username',
        'password',
        'role',
        'email',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
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

    public function anggota()
    {
        return $this->hasOne(Anggota::class, 'id_account', 'id_account');
    }
}
