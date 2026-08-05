<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Target RKA EDC per (kpi, tahun, bulan). Hanya KPI ber-flag target yang diisi. */
    public function up(): void
    {
        Schema::create('rka_edc', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('cabang_id');
            $table->unsignedInteger('uker_id');
            $table->string('kpi', 40);
            $table->unsignedSmallInteger('tahun');
            $table->unsignedTinyInteger('bulan');
            $table->decimal('target', 20, 2)->default(0);
            $table->timestamps();

            $table->foreign('cabang_id')->references('id')->on('cabang')->cascadeOnUpdate();
            $table->foreign('uker_id')->references('id')->on('uker')->cascadeOnUpdate();

            $table->unique(['uker_id', 'kpi', 'tahun', 'bulan'], 'rka_edc_unique');
            $table->index(['tahun', 'bulan', 'kpi']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rka_edc');
    }
};
