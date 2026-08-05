<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Target Recovery disimpan PER SEGMEN x tahun x bulan. Segmennya juga
     * disimpan MENTAH apa adanya; saat baca, actual dan target sama-sama
     * dilipat ke segmen kanonik (App\Models\Recovery::kanonik) supaya
     * perbandingan pencapaian tetap apple-to-apple sekalipun taksonomi berkas
     * RKA dan berkas aktual berbeda antar tahun.
     */
    public function up(): void
    {
        Schema::create('rka_recovery', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('cabang_id');
            $table->unsignedInteger('uker_id');
            $table->string('segmen', 30);
            $table->unsignedSmallInteger('tahun');
            $table->unsignedTinyInteger('bulan');
            // RUPIAH PENUH, sama seperti tabel aktual.
            $table->decimal('target', 20, 2)->default(0);
            $table->timestamps();

            $table->foreign('cabang_id')->references('id')->on('cabang')->cascadeOnUpdate();
            $table->foreign('uker_id')->references('id')->on('uker')->cascadeOnUpdate();

            $table->unique(['uker_id', 'segmen', 'tahun', 'bulan'], 'rka_recovery_unique');
            $table->index(['tahun', 'bulan', 'segmen']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rka_recovery');
    }
};
