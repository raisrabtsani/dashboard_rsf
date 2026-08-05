<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recovery', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('cabang_id');
            $table->unsignedInteger('uker_id');
            // Segmen disimpan MENTAH apa adanya dari berkas sumber. Taksonomi
            // berubah antar tahun (mis. "SME" dipecah jadi "Small" + "Medium"),
            // jadi normalisasi ke segmen kanonik dilakukan SAAT BACA di service
            // (App\Models\Recovery::kanonik), bukan saat import.
            $table->string('segmen', 30);
            $table->date('tanggal');
            // RUPIAH PENUH — konversi ke juta hanya lewat App\Support\Satuan.
            $table->decimal('actual', 20, 2)->default(0);
            $table->timestamps();

            $table->foreign('cabang_id')->references('id')->on('cabang')->cascadeOnUpdate();
            $table->foreign('uker_id')->references('id')->on('uker')->cascadeOnUpdate();

            // Kunci idempotensi importer: importer menjumlahkan banyak baris debitur
            // per kombinasi ini menjadi SATU baris, lalu upsert. uker_id di depan
            // supaya indeks ini ikut menopang foreign key uker_id (cabang_id
            // ditentukan uker, jadi tidak perlu di kunci).
            $table->unique(['uker_id', 'segmen', 'tanggal'], 'recovery_unique');
            $table->index(['tanggal', 'segmen']);
            $table->index(['cabang_id', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recovery');
    }
};
