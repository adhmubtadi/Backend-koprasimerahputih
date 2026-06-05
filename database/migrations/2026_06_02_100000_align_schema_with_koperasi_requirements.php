<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cabangs', function (Blueprint $table) {
            if (! Schema::hasColumn('cabangs', 'kota')) {
                $table->string('kota')->nullable()->after('nama_cabang');
            }
        });

        Schema::table('produks', function (Blueprint $table) {
            if (! Schema::hasColumn('produks', 'id_cabang')) {
                $table->unsignedBigInteger('id_cabang')->nullable()->after('id_produk');
                $table->foreign('id_cabang')->references('id_cabang')->on('cabangs')->nullOnDelete();
            }

            if (! Schema::hasColumn('produks', 'id_supplier')) {
                $table->unsignedBigInteger('id_supplier')->nullable()->after('id_cabang');
                $table->foreign('id_supplier')->references('id_supplier')->on('suppliers')->nullOnDelete();
            }
        });

        Schema::table('usulan_stoks', function (Blueprint $table) {
            if (! Schema::hasColumn('usulan_stoks', 'harga_jual')) {
                $table->double('harga_jual')->nullable()->after('harga_beli');
            }
        });

        // Tambah status Non-Aktif pada anggota (sesuai laporan anggota aktif/non-aktif)
        if (Schema::hasTable('anggotas')) {
            DB::statement("ALTER TABLE anggotas MODIFY status ENUM('Calon', 'Aktif', 'Non-Aktif') NOT NULL DEFAULT 'Calon'");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('anggotas')) {
            DB::statement("ALTER TABLE anggotas MODIFY status ENUM('Calon', 'Aktif') NOT NULL DEFAULT 'Calon'");
        }

        Schema::table('usulan_stoks', function (Blueprint $table) {
            if (Schema::hasColumn('usulan_stoks', 'harga_jual')) {
                $table->dropColumn('harga_jual');
            }
        });

        Schema::table('produks', function (Blueprint $table) {
            if (Schema::hasColumn('produks', 'id_supplier')) {
                $table->dropForeign(['id_supplier']);
                $table->dropColumn('id_supplier');
            }
            if (Schema::hasColumn('produks', 'id_cabang')) {
                $table->dropForeign(['id_cabang']);
                $table->dropColumn('id_cabang');
            }
        });

        Schema::table('cabangs', function (Blueprint $table) {
            if (Schema::hasColumn('cabangs', 'kota')) {
                $table->dropColumn('kota');
            }
        });
    }
};
