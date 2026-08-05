<script setup>
import RkaDomain from '@/Components/Admin/RkaDomain.vue';

defineProps({
    kolomBerkas: { type: Array, required: true },
});
</script>

<template>
    <RkaDomain
        domain="simpanan"
        judul="Admin — RKA Simpanan"
        :kolom-berkas="kolomBerkas"
        label-kelompok="Produk"
    />
</template>
