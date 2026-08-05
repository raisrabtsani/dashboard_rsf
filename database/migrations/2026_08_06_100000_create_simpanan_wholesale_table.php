<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DPK segmen Wholesale — segmen yang TIDAK masuk dashboard harian tapi wajib
 * ikut di halaman PRESENT (rapat Region). Strukturnya mencerminkan tabel
 * `simpanan`; upload & RKA-nya terpisah di admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('simpanan_wholesale', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('cabang_id');
            $table->unsignedInteger('uker_id');
            // Tabungan | Giro | Deposito. CASA = Tabungan + Giro.
            $table->string('produk', 20);
            $table->string('segmentasi', 50);
            $table->date('tanggal');
            // RUPIAH PENUH — konversi ke juta hanya lewat App\Support\Satuan.
            $table->decimal('saldo', 20, 2)->default(0);
            $table->timestamps();

            $table->foreign('cabang_id')->references('id')->on('cabang')->cascadeOnUpdate();
            $table->foreign('uker_id')->references('id')->on('uker')->cascadeOnUpdate();

            $table->unique(['uker_id', 'produk', 'segmentasi', 'tanggal'], 'simpanan_wholesale_unique');
            $table->index(['tanggal', 'produk']);
            $table->index(['cabang_id', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simpanan_wholesale');
    }
};
