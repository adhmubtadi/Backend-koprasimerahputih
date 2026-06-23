<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('anggotas')) {
            DB::statement("ALTER TABLE anggotas MODIFY status ENUM('Calon', 'Aktif', 'Non-Aktif', 'Tertunda', 'Ditolak') NOT NULL DEFAULT 'Tertunda'");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('anggotas')) {
            DB::table('anggotas')->whereIn('status', ['Tertunda', 'Ditolak'])->update(['status' => 'Calon']);
            DB::statement("ALTER TABLE anggotas MODIFY status ENUM('Calon', 'Aktif', 'Non-Aktif') NOT NULL DEFAULT 'Calon'");
        }
    }
};
