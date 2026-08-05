<script setup>
import RkaDomain from '@/Components/Admin/RkaDomain.vue';

defineProps({
    kolomBerkas: { type: Array, required: true },
});
</script>

<template>
    <RkaDomain
        domain="edc"
        judul="Admin — RKA EDC"
        :kolom-berkas="kolomBerkas"
        label-kelompok="KPI"
    />
</template>
