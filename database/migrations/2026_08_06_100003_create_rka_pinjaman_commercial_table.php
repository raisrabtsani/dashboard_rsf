<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RKA Pinjaman Commercial — target PER KUALITAS untuk pinjaman_commercial,
 * mencerminkan rka_pinjaman. Target "Total/OS" tidak disimpan terpisah; ia
 * dijumlahkan dari ketiga kualitas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rka_pinjaman_commercial', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('cabang_id');
            $table->unsignedInteger('uker_id');
            $table->string('segmen', 30);
            $table->string('segmentasi', 50)->default('');
            $table->string('kualitas', 10);
            $table->unsignedSmallInteger('tahun');
            $table->unsignedTinyInteger('bulan');
            $table->decimal('target', 20, 2)->default(0);
            $table->timestamps();

            $table->foreign('cabang_id')->references('id')->on('cabang')->cascadeOnUpdate();
            $table->foreign('uker_id')->references('id')->on('uker')->cascadeOnUpdate();

            $table->unique(
                ['uker_id', 'segmen', 'segmentasi', 'kualitas', 'tahun', 'bulan'],
                'rka_pinjaman_commercial_unique',
            );
            $table->index(['tahun', 'bulan', 'kualitas']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rka_pinjaman_commercial');
    }
};
