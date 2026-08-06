<script setup>
import UploadDomain from '@/Components/Admin/UploadDomain.vue';

defineProps({
    kolomBerkas: { type: Array, required: true },
});
</script>

<template>
    <UploadDomain
        domain="simpanan"
        judul="Admin — Upload Data Simpanan"
        :kolom-berkas="kolomBerkas"
        key-nilai="total_saldo"
        label-nilai="Total Saldo"
        catatan-duplikat="Kolom id_cabang tidak diperlukan. Cabang otomatis diambil dari master berdasarkan id_uker. Baris dengan id_uker yang belum memiliki cabang akan dilewati dan nilainya tidak masuk. Tanggal yang sudah ada tetap harus dihapus sebelum unggah ulang."
    />
</template>
