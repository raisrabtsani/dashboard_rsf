<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Data merchant EDC per KPI (bukan per produk). Satu baris = nilai satu KPI
     * di satu uker pada satu tanggal. Satuan mengikuti KPI-nya (rupiah untuk
     * Sales Volume, jumlah unit/transaksi untuk lainnya) — perbedaan itu diatur
     * di katalog KPI pada EdcService, bukan di skema.
     */
    public function up(): void
    {
        Schema::create('edc', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('cabang_id');
            $table->unsignedInteger('uker_id');
            // Kode KPI kanonik (lihat App\Services\EdcService::KPI).
            $table->string('kpi', 40);
            $table->date('tanggal');
            // Nilai mentah: rupiah penuh untuk KPI rupiah, angka apa adanya untuk
            // KPI hitungan. Bertanda supaya nilai negatif tetap bisa disimpan.
            $table->decimal('actual', 20, 2)->default(0);
            $table->timestamps();

            $table->foreign('cabang_id')->references('id')->on('cabang')->cascadeOnUpdate();
            $table->foreign('uker_id')->references('id')->on('uker')->cascadeOnUpdate();

            $table->unique(['uker_id', 'kpi', 'tanggal'], 'edc_unique');
            $table->index(['tanggal', 'kpi']);
            $table->index(['cabang_id', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edc');
    }
};
