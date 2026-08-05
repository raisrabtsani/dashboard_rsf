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
    />
</template>
