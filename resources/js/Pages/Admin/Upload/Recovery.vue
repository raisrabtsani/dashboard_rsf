<script setup>
import UploadDomain from '@/Components/Admin/UploadDomain.vue';

defineProps({
    kolomBerkas: { type: Array, required: true },
});
</script>

<template>
    <UploadDomain
        domain="recovery"
        judul="Admin — Upload Data Recovery"
        :kolom-berkas="kolomBerkas"
        key-nilai="total"
        label-nilai="Total Recovery"
        catatan-duplikat="Banyak baris per (cabang, uker, segmen, tanggal) DIJUMLAHKAN jadi satu; mengunggah ulang tanggal yang sama menimpa dengan jumlah baru (upsert), bukan menggandakan."
    />
</template>
