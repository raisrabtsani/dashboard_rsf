<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uker', function (Blueprint $table) {
            // ID manual dari code_uker.csv (id_uker) — BUKAN auto-increment.
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('cabang_id');
            $table->string('nama');
            // BO | SBO | UNIT | KK | REGION (REGION hanya untuk rollup 855).
            $table->string('tipe', 10);
            $table->timestamps();

            $table->foreign('cabang_id')->references('id')->on('cabang')->cascadeOnUpdate();

            $table->index('cabang_id');
            $table->index('tipe');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uker');
    }
};
