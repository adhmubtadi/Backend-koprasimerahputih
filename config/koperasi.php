<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Distribusi Cabang Koperasi Merah Putih (total 20 cabang)
    |--------------------------------------------------------------------------
    */
    'cabang_per_kota' => [
        'Bandung' => 5,
        'Jakarta' => 5,
        'Bekasi' => 5,
        'Serang' => 3,
        'Cilegon' => 2,
    ],

    'total_cabang' => 20,

  /*
    |--------------------------------------------------------------------------
    | Aturan Bisnis
    |--------------------------------------------------------------------------
    */
    'pinjaman_tenor_bulan' => [6, 12, 18, 24],

    'biaya_operasional_persen' => 0.02,

    'pinjaman_limit_multiplier_simpanan' => 3,

    // Nominal setoran wajib anggota per bulan. Diubah dari sini bila kebijakan koperasi berubah.
    'simpanan_wajib_bulanan' => 100000,

    'stok_warning_threshold' => 100,

    'kasir_per_cabang' => 2,

    'roles' => ['Admin', 'Pengurus', 'Kasir', 'Gudang', 'Anggota'],

    'anggota_status' => ['Calon', 'Aktif', 'Non-Aktif'],

];
