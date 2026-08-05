<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Target RKA Laba per (segmen, tahun, bulan), juga KUMULATIF YTD — sama
     * satuan dengan tabel aktual, supaya pencapaian = laba YTD / target YTD.
     */
    public function up(): void
    {
        Schema::create('rka_laba', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('cabang_id');
            $table->unsignedInteger('uker_id');
            $table->string('segmen', 30);
            $table->unsignedSmallInteger('tahun');
            $table->unsignedTinyInteger('bulan');
            // RUPIAH PENUH, bertanda (target bisa negatif di segmen tertentu).
            $table->decimal('target', 20, 2)->default(0);
            $table->timestamps();

            $table->foreign('cabang_id')->references('id')->on('cabang')->cascadeOnUpdate();
            $table->foreign('uker_id')->references('id')->on('uker')->cascadeOnUpdate();

            $table->unique(['uker_id', 'segmen', 'tahun', 'bulan'], 'rka_laba_unique');
            $table->index(['tahun', 'bulan', 'segmen']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rka_laba');
    }
};
