<script setup>
import UploadDomain from '@/Components/Admin/UploadDomain.vue';

defineProps({
    kolomBerkas: { type: Array, required: true },
});
</script>

<template>
    <UploadDomain
        domain="pinjaman-commercial"
        judul="Admin — Upload Pinjaman Commercial"
        :kolom-berkas="kolomBerkas"
        key-nilai="total"
        label-nilai="Total Baki Debet"
        :preview-before-upload="true"
        contoh-format="id_cabang | id_uker | SEGMEN_2025 | Segmentasi | Kualitas Kredit | Baki Debet | Month, Day, Year of Posisi
317 | 317 | Commercial | Commercial | Lancar | 110779677172 | 1/31/2026"
        catatan-duplikat="Klik Preview & Validasi lebih dulu. Sistem menampilkan jumlah baris valid, baris tidak valid, dan detail kesalahannya sebelum data disimpan."
    />
</template>
