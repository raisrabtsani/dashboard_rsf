<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Laba adalah domain BULANAN — tidak ada kolom tanggal, hanya (tahun, bulan).
     *
     * Nilai `laba` yang disimpan bersifat KUMULATIF YTD (bukan laba bulan itu
     * saja). Laba bulan berjalan (MTD) diturunkan saat baca: laba(N) - laba(N-1).
     */
    public function up(): void
    {
        Schema::create('laba', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('cabang_id');
            $table->unsignedInteger('uker_id');
            $table->string('segmen', 30);
            $table->unsignedSmallInteger('tahun');
            $table->unsignedTinyInteger('bulan');
            // RUPIAH PENUH, kumulatif YTD. Kolom BERTANDA (signed) — laba bisa
            // negatif (rugi); jangan dijadikan unsigned. Konversi ke juta hanya
            // lewat App\Support\Satuan.
            $table->decimal('laba', 20, 2)->default(0);
            $table->timestamps();

            $table->foreign('cabang_id')->references('id')->on('cabang')->cascadeOnUpdate();
            $table->foreign('uker_id')->references('id')->on('uker')->cascadeOnUpdate();

            // Kunci idempotensi importer: satu baris per kombinasi, upsert.
            // uker_id di depan supaya indeks ikut menopang foreign key uker_id.
            $table->unique(['uker_id', 'segmen', 'tahun', 'bulan'], 'laba_unique');
            $table->index(['tahun', 'bulan', 'segmen']);
            $table->index(['cabang_id', 'tahun', 'bulan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laba');
    }
};
