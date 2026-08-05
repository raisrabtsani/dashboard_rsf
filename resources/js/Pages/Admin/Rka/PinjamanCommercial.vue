<script setup>
import RkaDomain from '@/Components/Admin/RkaDomain.vue';

defineProps({
    kolomBerkas: { type: Array, required: true },
});
</script>

<template>
    <RkaDomain
        domain="pinjaman-commercial"
        judul="Admin — RKA Pinjaman Commercial"
        :kolom-berkas="kolomBerkas"
        label-kelompok="Kualitas"
    />
</template>
