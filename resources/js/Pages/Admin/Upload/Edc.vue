<script setup>
import UploadDomain from '@/Components/Admin/UploadDomain.vue';

defineProps({
    kolomBerkas: { type: Array, required: true },
});
</script>

<template>
    <UploadDomain
        domain="edc"
        judul="Admin — Upload Data EDC"
        :kolom-berkas="kolomBerkas"
        key-nilai="total"
        label-nilai="Jumlah Nilai (campuran satuan)"
        catatan-duplikat="Baris di-upsert per (uker, KPI, tanggal): mengunggah ulang menimpa, bukan menggandakan. Pemetaan KPI: SV_RP_Nol = EDC SV Rp.0; Sales_Volume_Marginal = Sales Volume; Jml_Trx_Marginal = Jumlah Transaksi; TID = TID; MID = MID."
    />
</template>
