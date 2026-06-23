<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('simpanans', function (Blueprint $table) {
            $table->string('bukti_transfer')->nullable()->after('tanggal');
            $table->enum('status', ['Pending', 'Verified', 'Rejected'])->default('Verified')->after('bukti_transfer');
        });

        DB::table('simpanans')->whereNull('status')->update(['status' => 'Verified']);
    }

    public function down(): void
    {
        Schema::table('simpanans', function (Blueprint $table) {
            $table->dropColumn(['bukti_transfer', 'status']);
        });
    }
};
