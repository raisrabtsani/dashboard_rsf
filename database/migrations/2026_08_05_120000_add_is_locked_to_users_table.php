<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Status akun dikelola admin lewat tombol kunci/buka.
            // Menggantikan konsep "verifikasi email" bawaan Breeze, yang tidak
            // relevan karena login memakai username.
            $table->boolean('is_locked')->default(false)->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_locked');
        });
    }
};
