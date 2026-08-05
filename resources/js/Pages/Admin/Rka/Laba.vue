<script setup>
import RkaDomain from '@/Components/Admin/RkaDomain.vue';

defineProps({
    kolomBerkas: { type: Array, required: true },
});
</script>

<template>
    <RkaDomain
        domain="laba"
        judul="Admin — RKA Laba"
        :kolom-berkas="kolomBerkas"
        label-kelompok="Segmen"
    />
</template>
