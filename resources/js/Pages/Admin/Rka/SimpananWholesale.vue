<script setup>
import RkaDomain from '@/Components/Admin/RkaDomain.vue';

defineProps({
    kolomBerkas: { type: Array, required: true },
});
</script>

<template>
    <RkaDomain
        domain="simpanan-wholesale"
        judul="Admin — RKA DPK Wholesale"
        :kolom-berkas="kolomBerkas"
        label-kelompok="Produk"
    />
</template>
