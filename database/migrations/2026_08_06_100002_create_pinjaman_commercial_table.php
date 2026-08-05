<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pinjaman segmen Commercial — segmen yang TIDAK masuk dashboard harian tapi
 * wajib ikut di halaman PRESENT. Struktur mencerminkan tabel `pinjaman`
 * (berdimensi kualitas Lancar/SML/NPL); upload & RKA-nya terpisah di admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pinjaman_commercial', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('cabang_id');
            $table->unsignedInteger('uker_id');
            $table->string('segmen', 30);
            $table->string('segmentasi', 50);
            // Lancar | SML | NPL. OS = jumlah ketiganya.
            $table->string('kualitas', 10);
            $table->date('tanggal');
            // RUPIAH PENUH — konversi ke juta hanya lewat App\Support\Satuan.
            $table->decimal('baki_debet', 20, 2)->default(0);
            $table->timestamps();

            $table->foreign('cabang_id')->references('id')->on('cabang')->cascadeOnUpdate();
            $table->foreign('uker_id')->references('id')->on('uker')->cascadeOnUpdate();

            $table->unique(['uker_id', 'segmen', 'segmentasi', 'kualitas', 'tanggal'], 'pinjaman_commercial_unique');
            $table->index(['tanggal', 'kualitas']);
            $table->index(['cabang_id', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pinjaman_commercial');
    }
};
