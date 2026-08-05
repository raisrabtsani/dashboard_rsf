<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Target disimpan PER KUALITAS (Lancar/SML/NPL), sama seperti tabel aktual.
     * Target tab "Total" tidak disimpan terpisah — ia dijumlahkan dari ketiganya,
     * persis seperti OS dihitung dari data aktual. Menyimpan baris "Total"
     * sendiri akan membuat dua sumber kebenaran yang bisa berbeda.
     */
    public function up(): void
    {
        Schema::create('rka_pinjaman', function (Blueprint $table) {
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
                'rka_pinjaman_unique',
            );
            $table->index(['tahun', 'bulan', 'kualitas']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rka_pinjaman');
    }
};
