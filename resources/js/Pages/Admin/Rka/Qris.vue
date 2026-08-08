<script setup>
import RkaDomain from '@/Components/Admin/RkaDomain.vue';

defineProps({
    kolomBerkas: { type: Array, required: true },
});
</script>

<template>
    <RkaDomain
        domain="qris"
        judul="Admin — RKA QRIS"
        :kolom-berkas="kolomBerkas"
        label-kelompok="KPI"
        petunjuk-kpi="Sales_Volume_QRIS = Sales Volume | Jml Qris = User QRIS | Qris Produktif = QRIS Produktif"
    />
</template>
