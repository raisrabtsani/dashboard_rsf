<script setup>
import UploadDomain from '@/Components/Admin/UploadDomain.vue';

defineProps({
    kolomBerkas: { type: Array, required: true },
});
</script>

<template>
    <UploadDomain
        domain="edc"
        judul="Admin — Upload Data EDC"
        :kolom-berkas="kolomBerkas"
        key-nilai="total"
        label-nilai="Jumlah Nilai (campuran satuan)"
        catatan-duplikat="Baris di-upsert per (uker, KPI, tanggal): mengunggah ulang menimpa, bukan menggandakan. Nama KPI dinormalkan otomatis ke kode katalog."
    />
</template>
