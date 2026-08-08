<script setup>
import UploadDomain from '@/Components/Admin/UploadDomain.vue';

defineProps({
    kolomBerkas: { type: Array, required: true },
});
</script>

<template>
    <UploadDomain
        domain="ph"
        judul="Admin — Upload Data PH (Hapus Buku)"
        :kolom-berkas="kolomBerkas"
        key-periode="periode"
        label-periode="Periode (akhir bulan)"
        key-nilai="total"
        label-nilai="Total PH"
        massal="tahun"
        catatan-duplikat="Saldo otomatis di-SUMIF berdasarkan id_uker + periode yang sama. id_cabang mengikuti master id_uker agar semua baris masuk ke grup yang benar; rincian segmen tetap dipertahankan. Periode lama tetap mengikuti aturan upload yang berlaku."
    />
</template>
