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
    />
</template>
