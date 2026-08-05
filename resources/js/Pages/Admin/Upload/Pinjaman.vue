<script setup>
import UploadDomain from '@/Components/Admin/UploadDomain.vue';

defineProps({
    kolomBerkas: { type: Array, required: true },
});
</script>

<template>
    <UploadDomain
        domain="pinjaman"
        judul="Admin — Upload Data Pinjaman"
        :kolom-berkas="kolomBerkas"
        key-nilai="total"
        label-nilai="Total Baki Debet"
        catatan-duplikat="Proteksi duplikat dihitung per (tanggal + segmen): baris yang segmennya sudah ada pada tanggal itu dilewati, sedangkan segmen baru tetap masuk."
    />
</template>
