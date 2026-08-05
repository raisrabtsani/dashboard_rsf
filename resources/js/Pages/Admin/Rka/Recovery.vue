<script setup>
import RkaDomain from '@/Components/Admin/RkaDomain.vue';

defineProps({
    kolomBerkas: { type: Array, required: true },
});
</script>

<template>
    <RkaDomain
        domain="recovery"
        judul="Admin — RKA Recovery"
        :kolom-berkas="kolomBerkas"
        label-kelompok="Segmen"
    />
</template>
