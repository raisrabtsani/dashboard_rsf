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
        catatan-duplikat="Periode yang datanya sudah ada akan DILEWATI, bukan membatalkan seluruh berkas — jadi berkas kumulatif sepanjang tahun tetap bisa diunggah berulang. Untuk menimpa periode lama, pakai `php artisan import:ph`."
    />
</template>
