<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Cabang;
use App\Models\Admin;
use App\Models\Pengurus;
use App\Models\Gudang;
use App\Models\Kasir;
use App\Models\Anggota;
use App\Models\Akun;
use App\Models\Produk;
use App\Models\Supplier;
use App\Models\Simpanan;
use App\Models\Pinjaman;
use App\Models\UsulanStok;
use App\Models\BranchProductStock;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $cabangPerKota = config('koperasi.cabang_per_kota', [
            'Bandung' => 5,
            'Jakarta' => 5,
            'Bekasi' => 5,
            'Serang' => 3,
            'Cilegon' => 2,
        ]);

        $cabangList = [];
        foreach ($cabangPerKota as $kota => $jumlah) {
            for ($i = 1; $i <= $jumlah; $i++) {
                $cabangList[] = [
                    'nama_cabang' => $kota.' '.$i,
                    'kota' => $kota,
                    'lokasi' => 'Toko Koperasi Merah Putih - '.$kota.' Cabang '.$i,
                ];
            }
        }

        foreach ($cabangList as $row) {
            Cabang::firstOrCreate(
                ['nama_cabang' => $row['nama_cabang']],
                ['kota' => $row['kota'], 'lokasi' => $row['lokasi']]
            );
        }

        $coa = [
            ['nama_akun' => 'Kas', 'jenis' => 'Aset'],
            ['nama_akun' => 'Penjualan', 'jenis' => 'Pendapatan'],
            ['nama_akun' => 'Piutang', 'jenis' => 'Aset'],
            ['nama_akun' => 'Simpanan Anggota', 'jenis' => 'Kewajiban'],
            ['nama_akun' => 'Pendapatan Biaya Operasional', 'jenis' => 'Pendapatan'],
            ['nama_akun' => 'Persediaan Barang', 'jenis' => 'Aset'],
            ['nama_akun' => 'HPP', 'jenis' => 'Beban'],
        ];
        foreach ($coa as $item) {
            Akun::firstOrCreate(
                ['nama_akun' => $item['nama_akun']],
                ['jenis' => $item['jenis']]
            );
        }

        $accAdmin = Account::firstOrCreate(
            ['username' => 'admin_husain'],
            ['password' => Hash::make('password123'), 'role' => 'Admin']
        );
        Admin::firstOrCreate(
            ['id_account' => $accAdmin->id_account],
            ['nama_admin' => 'Husain Abdul Ghani']
        );

        $cabang = Cabang::where('nama_cabang', 'Bandung 1')->first() ?? Cabang::first();

        $accPengurus = Account::firstOrCreate(
            ['username' => 'pengurus_koperasi'],
            ['password' => Hash::make('password123'), 'role' => 'Pengurus']
        );
        $pengurus = Pengurus::firstOrCreate(
            ['id_account' => $accPengurus->id_account],
            [
                'nama_pengurus' => 'Dewi Lestari',
                'nip' => 'PG-001',
                'id_cabang' => $cabang->id_cabang,
            ]
        );

        $pengurusNames = [
            'Dewi Lestari',
            'Ahmad Fauzan',
            'Rina Marlina',
            'Satria Nugraha',
            'Maya Kartika',
            'Fajar Ramadhan',
            'Nadia Putri',
            'Yusuf Maulana',
            'Intan Permata',
            'Rizky Pratama',
        ];
        $pengurusIndex = 1;
        foreach (Cabang::where('id_cabang', '!=', $cabang->id_cabang)->orderBy('id_cabang')->get() as $cb) {
            $slug = strtolower(str_replace(' ', '_', $cb->nama_cabang));
            $accCabangPengurus = Account::firstOrCreate(
                ['username' => 'pengurus_'.$slug],
                ['password' => Hash::make('password123'), 'role' => 'Pengurus']
            );

            Pengurus::firstOrCreate(
                ['id_account' => $accCabangPengurus->id_account],
                [
                    'nama_pengurus' => $pengurusNames[$pengurusIndex % count($pengurusNames)],
                    'nip' => 'PG-'.str_pad((string) ($pengurusIndex + 1), 3, '0', STR_PAD_LEFT),
                    'id_cabang' => $cb->id_cabang,
                ]
            );
            $pengurusIndex++;
        }

        $accGudang = Account::firstOrCreate(
            ['username' => 'gudang_koperasi'],
            ['password' => Hash::make('password123'), 'role' => 'Gudang']
        );
        $gudang = Gudang::firstOrCreate(
            ['id_account' => $accGudang->id_account],
            [
                'nama_petugas' => 'Rudi Gudang',
                'id_cabang' => $cabang->id_cabang,
            ]
        );

        // 2 kasir per cabang (sesuai dokumen requirement)
        $kasirPerCabang = (int) config('koperasi.kasir_per_cabang', 2);
        $kasirNames = [
            'Andi Saputra',
            'Siti Amelia',
            'Budi Santoso',
            'Nabila Azzahra',
            'Dimas Prakoso',
            'Putri Maharani',
            'Rizal Hakim',
            'Laras Wulandari',
            'Yoga Firmansyah',
            'Citra Anjani',
            'Teguh Wicaksono',
            'Aulia Rahma',
        ];
        $kasirIndex = 0;
        foreach (Cabang::all() as $cb) {
            for ($k = 1; $k <= $kasirPerCabang; $k++) {
                $slug = strtolower(str_replace(' ', '_', $cb->nama_cabang));
                $username = 'kasir_'.$slug.'_'.$k;

                $accKasir = Account::firstOrCreate(
                    ['username' => $username],
                    ['password' => Hash::make('password123'), 'role' => 'Kasir']
                );

                Kasir::firstOrCreate(
                    ['id_account' => $accKasir->id_account],
                    [
                        'nama_kasir' => $kasirNames[$kasirIndex % count($kasirNames)],
                        'id_cabang' => $cb->id_cabang,
                    ]
                );
                $kasirIndex++;
            }
        }

        $accAnggota = Account::firstOrCreate(
            ['username' => 'anggota_koperasi'],
            ['password' => Hash::make('password123'), 'role' => 'Anggota']
        );
        $anggota = Anggota::firstOrCreate(
            ['email' => 'asep@example.com'],
            [
                'id_account' => $accAnggota->id_account,
                'nomor_anggota' => 'AGT-'.$cabang->id_cabang.'-000001',
                'nama_anggota' => 'Asep Anggota',
                'alamat' => 'Bandung Barat',
                'no_hp' => '082119300188',
                'tanggal_daftar' => now(),
                'status' => 'Aktif',
                'id_cabang' => $cabang->id_cabang,
            ]
        );

        $supplier = Supplier::firstOrCreate(
            ['nama_supplier' => 'PT Sumber Sembako Sejahtera'],
            ['alamat' => 'Jl. Raya Utama No. 10, Bandung']
        );

        $produkBeras = Produk::firstOrCreate(
            ['nama_produk' => 'Beras Merah Putih 5kg', 'id_cabang' => $cabang->id_cabang],
            [
                'id_supplier' => $supplier->id_supplier,
                'harga_beli' => 60000,
                'harga_jual' => 75000,
                'stok' => 150,
            ]
        );

        $produkMinyak = Produk::firstOrCreate(
            ['nama_produk' => 'Minyak Goreng 1L', 'id_cabang' => $cabang->id_cabang],
            [
                'id_supplier' => $supplier->id_supplier,
                'harga_beli' => 14000,
                'harga_jual' => 17000,
                'stok' => 50,
            ]
        );

        foreach (Cabang::all() as $cb) {
            foreach ([$produkBeras, $produkMinyak] as $produk) {
                BranchProductStock::updateOrCreate(
                    [
                        'id_cabang' => $cb->id_cabang,
                        'id_produk' => $produk->id_produk,
                    ],
                    [
                        'stok' => (int) $cb->id_cabang === (int) $produk->id_cabang ? (int) $produk->stok : 0,
                    ]
                );
            }
        }

        Simpanan::firstOrCreate([
            'id_anggota' => $anggota->id_anggota,
            'jenis_simpanan' => 'Wajib',
            'jumlah' => 100000,
            'tanggal' => now()->toDateString(),
        ]);

        Pinjaman::firstOrCreate(
            [
                'id_anggota' => $anggota->id_anggota,
                'jumlah_pinjaman' => 2000000,
                'tanggal_pengajuan' => now()->toDateString(),
            ],
            [
                'id_pengurus_acc' => null,
                'biaya_operasional' => 2000000 * 0.02,
                'tenor' => '12',
                'status' => 'Pending',
            ]
        );

        UsulanStok::firstOrCreate(
            [
                'id_produk' => $produkMinyak->id_produk,
                'id_gudang' => $gudang->id_gudang,
                'id_supplier' => $supplier->id_supplier,
                'id_cabang' => $cabang->id_cabang,
            ],
            [
                'id_pengurus_acc' => null,
                'jumlah' => 200,
                'harga_beli' => (float) $produkMinyak->harga_beli,
                'status' => 'Pending',
                'tanggal_usulan' => now()->toDateString(),
            ]
        );
    }
}
