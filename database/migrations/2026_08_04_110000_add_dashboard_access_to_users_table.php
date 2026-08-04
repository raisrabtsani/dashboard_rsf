<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Login memakai username, bukan email.
            $table->string('username')->unique()->after('name');
            $table->string('role', 10)->default('user')->after('password');
            // RO | BO | SBO | UNIT | KK — dasar penentuan access_level.
            $table->string('tipe', 10)->nullable()->after('role');
            // Kantor yang dikunci untuk user (konsep 1 user = 1 kantor).
            $table->unsignedInteger('cabang_id')->nullable()->after('tipe');
            $table->unsignedInteger('uker_id')->nullable()->after('cabang_id');

            $table->foreign('cabang_id')->references('id')->on('cabang')->nullOnDelete()->cascadeOnUpdate();
            $table->foreign('uker_id')->references('id')->on('uker')->nullOnDelete()->cascadeOnUpdate();

            $table->index(['role', 'tipe']);
        });

        // Email tidak dipakai di UI maupun untuk login; disisakan nullable supaya
        // kolom bawaan Breeze tidak perlu dibongkar. Unique tetap dipertahankan —
        // MySQL & SQLite sama-sama mengizinkan banyak baris NULL di unique index.
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['cabang_id']);
            $table->dropForeign(['uker_id']);
            $table->dropIndex(['role', 'tipe']);
            $table->dropColumn(['username', 'role', 'tipe', 'cabang_id', 'uker_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
