<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PH = Pinjaman Hapus buku (write-off).
     *
     * FLOW BULANAN, bukan posisi: `saldo` adalah jumlah yang dihapusbukukan
     * SELAMA bulan itu. `periode` selalu tanggal akhir bulan dan berfungsi
     * sebagai penanda bulan, bukan tanggal kejadian.
     *
     * PH tidak punya RKA — tidak ada tabel rka_ph.
     */
    public function up(): void
    {
        Schema::create('ph', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('cabang_id');
            $table->unsignedInteger('uker_id');
            // Segmen KANONIK (Micro/SME/Consumer) — dinormalkan importer.
            $table->string('segmen', 30);
            $table->date('periode');
            // RUPIAH PENUH.
            $table->decimal('saldo', 20, 2)->default(0);
            $table->timestamps();

            $table->foreign('cabang_id')->references('id')->on('cabang')->cascadeOnUpdate();
            $table->foreign('uker_id')->references('id')->on('uker')->cascadeOnUpdate();

            $table->unique(['uker_id', 'segmen', 'periode'], 'ph_unique');
            $table->index(['periode', 'segmen']);
            $table->index(['cabang_id', 'periode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ph');
    }
};
