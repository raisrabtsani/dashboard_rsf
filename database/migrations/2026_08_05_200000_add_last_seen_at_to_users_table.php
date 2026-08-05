<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `last_seen_at` — cap waktu aktivitas terakhir tiap user, diperbarui middleware
 * TrackUserActivity (throttle 60 detik). Dipakai halaman Admin > Aktivitas
 * Pengguna untuk kolom "terakhir aktif". Terpisah dari `updated_at` supaya
 * aktivitas tidak dikira perubahan data akun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('is_locked')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_seen_at');
        });
    }
};
