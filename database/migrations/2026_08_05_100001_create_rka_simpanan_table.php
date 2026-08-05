<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rka_simpanan', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('cabang_id');
            $table->unsignedInteger('uker_id');
            $table->string('produk', 20);
            $table->unsignedSmallInteger('tahun');
            $table->unsignedTinyInteger('bulan');
            // RUPIAH PENUH, sama seperti tabel aktual.
            $table->decimal('target', 20, 2)->default(0);
            $table->timestamps();

            $table->foreign('cabang_id')->references('id')->on('cabang')->cascadeOnUpdate();
            $table->foreign('uker_id')->references('id')->on('uker')->cascadeOnUpdate();

            $table->unique(['uker_id', 'produk', 'tahun', 'bulan'], 'rka_simpanan_unique');
            $table->index(['tahun', 'bulan', 'produk']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rka_simpanan');
    }
};
