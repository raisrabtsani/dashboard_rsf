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
        petunjuk-kpi="Sales_Volume_Marginal = Sales Volume; EDC_Produktif = Produktif; TID = TID; MID = MID. Target 0 pada Sales_Volume_Marginal tetap masuk ke Sales Volume."
    />
</template>
