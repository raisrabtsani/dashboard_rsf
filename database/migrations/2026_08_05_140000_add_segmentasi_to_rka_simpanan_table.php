<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Berkas RKA dari unit bisnis membawa dimensi Segmentasi (Ritel/Micro),
     * sama seperti tabel aktual `simpanan`. Tanpa kolom ini, dua baris target
     * yang hanya berbeda segmentasi akan saling menimpa diam-diam saat upsert.
     */
    public function up(): void
    {
        Schema::table('rka_simpanan', function (Blueprint $table) {
            $table->string('segmentasi', 50)->default('')->after('produk');
        });

        // Indeks BARU dibuat lebih dulu, baru yang lama dibuang.
        // MySQL memakai `rka_simpanan_unique` (diawali uker_id) untuk menopang
        // foreign key uker_id; men-drop-nya duluan ditolak dengan errno 1553.
        // Indeks pengganti ini juga diawali uker_id sehingga FK tetap tertopang.
        Schema::table('rka_simpanan', function (Blueprint $table) {
            $table->unique(['uker_id', 'produk', 'segmentasi', 'tahun', 'bulan'], 'rka_simpanan_seg_unique');
        });

        Schema::table('rka_simpanan', function (Blueprint $table) {
            $table->dropUnique('rka_simpanan_unique');
        });
    }

    public function down(): void
    {
        Schema::table('rka_simpanan', function (Blueprint $table) {
            $table->unique(['uker_id', 'produk', 'tahun', 'bulan'], 'rka_simpanan_unique');
        });

        Schema::table('rka_simpanan', function (Blueprint $table) {
            $table->dropUnique('rka_simpanan_seg_unique');
        });

        Schema::table('rka_simpanan', function (Blueprint $table) {
            $table->dropColumn('segmentasi');
        });
    }
};
