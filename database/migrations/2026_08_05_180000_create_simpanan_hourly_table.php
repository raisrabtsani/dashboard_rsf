<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DPK per JAM — struktur tabel `simpanan` plus kolom `jam`.
     *
     * Hanya diisi untuk tanggal AKHIR BULAN (EOM): gunanya memantau pergerakan
     * DPK di hari penentuan posisi. Berkas sumber TIDAK membawa informasi jam;
     * jamnya ditetapkan admin saat mengunggah.
     *
     * Tidak punya RKA sendiri — pembanding delta memakai tabel `simpanan` harian.
     */
    public function up(): void
    {
        Schema::create('simpanan_hourly', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('cabang_id');
            $table->unsignedInteger('uker_id');
            $table->string('produk', 20);
            $table->string('segmentasi', 50);
            $table->date('tanggal');
            // Jam 0-23, ditetapkan admin saat upload.
            $table->unsignedTinyInteger('jam');
            // RUPIAH PENUH.
            $table->decimal('saldo', 20, 2)->default(0);
            $table->timestamps();

            $table->foreign('cabang_id')->references('id')->on('cabang')->cascadeOnUpdate();
            $table->foreign('uker_id')->references('id')->on('uker')->cascadeOnUpdate();

            $table->unique(
                ['uker_id', 'produk', 'segmentasi', 'tanggal', 'jam'],
                'simpanan_hourly_unique',
            );
            $table->index(['tanggal', 'jam']);
            $table->index(['cabang_id', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simpanan_hourly');
    }
};
