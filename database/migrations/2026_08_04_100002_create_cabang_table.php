<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cabang', function (Blueprint $table) {
            // ID manual dari code_uker.csv (id_cabang) — BUKAN auto-increment.
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('region_id');
            // Null bila BO belum dipetakan ke area mana pun di peta_area.csv.
            $table->unsignedInteger('area_id')->nullable();
            $table->string('nama');
            $table->timestamps();

            $table->foreign('region_id')->references('id')->on('region')->cascadeOnUpdate();
            $table->foreign('area_id')->references('id')->on('areas')->nullOnDelete()->cascadeOnUpdate();

            $table->index('area_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cabang');
    }
};
