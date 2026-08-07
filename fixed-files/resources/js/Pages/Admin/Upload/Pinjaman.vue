<script setup>
import UploadDomain from '@/Components/Admin/UploadDomain.vue';

defineProps({
    kolomBerkas: { type: Array, required: true },
});

const contohFormat = `id_cabang,id_uker,SEGMEN_2025,Segmentasi,Kualitas Kredit, Baki Debet ,Month, Day, Year of Posisi
2101,2101,Small,Cash Coll,Lancar,14404777000,02/28/2026`;
</script>

<template>
    <UploadDomain
        domain="pinjaman"
        judul="Admin — Upload Data Pinjaman"
        :kolom-berkas="kolomBerkas"
        key-nilai="total"
        label-nilai="Total Baki Debet"
        :contoh-format="contohFormat"
        catatan-duplikat="CSV UTF-8 dan UTF-16 didukung. Segmentasi yang kosong disimpan sebagai Tanpa Segmentasi. Baris tanpa Kualitas Kredit, Baki Debet, atau tanggal masuk daftar Error dan tidak menghentikan baris valid. Setelah verifikasi, tekan Upload Data Valid."
        preview-before-upload
    />
</template>
