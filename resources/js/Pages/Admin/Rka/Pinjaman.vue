<script setup>
import RkaDomain from '@/Components/Admin/RkaDomain.vue';

defineProps({
    kolomBerkas: { type: Array, required: true },
});
</script>

<template>
    <RkaDomain
        domain="pinjaman"
        judul="Admin — RKA Pinjaman"
        :kolom-berkas="kolomBerkas"
        label-kelompok="Kualitas"
    />
</template>
