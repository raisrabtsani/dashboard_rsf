<script setup>
import UploadBulanan from '@/Components/Admin/UploadBulanan.vue';

defineProps({
    kolomBerkas: { type: Array, required: true },
});
</script>

<template>
    <UploadBulanan
        domain="laba"
        judul="Admin — Upload Data Laba"
        :kolom-berkas="kolomBerkas"
        label-nilai="Total Laba (YTD)"
        catatan="Nilai laba KUMULATIF YTD (bisa negatif = rugi). Mengunggah ulang periode yang sama menimpa (upsert), bukan menggandakan. Sel laba kosong dilewati."
    />
</template>
