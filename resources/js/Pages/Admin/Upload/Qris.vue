<script setup>
import UploadDomain from '@/Components/Admin/UploadDomain.vue';

defineProps({
    kolomBerkas: { type: Array, required: true },
});
</script>

<template>
    <UploadDomain
        domain="qris"
        judul="Admin — Upload Data QRIS"
        :kolom-berkas="kolomBerkas"
        key-nilai="total"
        label-nilai="Jumlah Nilai (campuran satuan)"
        catatan-duplikat="Baris di-upsert per (uker, KPI, tanggal): mengunggah ulang menimpa, bukan menggandakan. Pemetaan KPI: Sales Volume Marginal = Sales Volume; Jml QRIS = User QRIS; Jml Trx Marginal = Jumlah Transaksi; Qris Produktif = QRIS Produktif."
    />
</template>
