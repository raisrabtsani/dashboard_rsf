<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const MENU = [
    {
        judul: 'Upload Data Simpanan',
        deskripsi: 'Unggah data aktual harian (CSV/Excel), riwayat, unduh ulang, hapus per tanggal atau per bulan.',
        route: 'admin.upload.simpanan.index',
    },
    {
        judul: 'RKA Simpanan',
        deskripsi: 'Unggah target per tahun, lihat ringkasan per produk, hapus per tahun.',
        route: 'admin.rka.simpanan.index',
    },
    {
        judul: 'Upload Data Pinjaman',
        deskripsi: 'Unggah baki debet harian per segmen & kualitas. Duplikat dicek per tanggal + segmen.',
        route: 'admin.upload.pinjaman.index',
    },
    {
        judul: 'RKA Pinjaman',
        deskripsi: 'Unggah target per tahun (per segmen & kualitas), ringkasan, hapus per tahun.',
        route: 'admin.rka.pinjaman.index',
    },
    {
        judul: 'Upload Data Recovery',
        deskripsi: 'Unggah penagihan hapus buku per segmen. Baris debitur dijumlahkan per (cabang, uker, segmen, tanggal); unggah ulang menimpa (upsert).',
        route: 'admin.upload.recovery.index',
    },
    {
        judul: 'RKA Recovery',
        deskripsi: 'Unggah target per tahun (per segmen), ringkasan, hapus per tahun.',
        route: 'admin.rka.recovery.index',
    },
    {
        judul: 'Upload Data Laba',
        deskripsi: 'Unggah laba KUMULATIF YTD per (segmen, tahun, bulan). Idempoten (upsert), riwayat per periode, hapus per periode/tahun.',
        route: 'admin.upload.laba.index',
    },
    {
        judul: 'RKA Laba',
        deskripsi: 'Unggah target laba YTD per tahun (per segmen), ringkasan, hapus per tahun.',
        route: 'admin.rka.laba.index',
    },
    {
        judul: 'Upload Data EDC',
        deskripsi: 'Unggah nilai KPI EDC harian (TID, MID, Sales Volume, dst). Nama KPI dinormalkan otomatis; upsert per (uker, KPI, tanggal).',
        route: 'admin.upload.edc.index',
    },
    {
        judul: 'RKA EDC',
        deskripsi: 'Unggah target per KPI EDC (per tahun/bulan), ringkasan, hapus per tahun.',
        route: 'admin.rka.edc.index',
    },
    {
        judul: 'Upload Data QRIS',
        deskripsi: 'Unggah nilai KPI QRIS harian (User QRIS, Sales Volume, dst). Nama KPI dinormalkan otomatis; upsert per (uker, KPI, tanggal).',
        route: 'admin.upload.qris.index',
    },
    {
        judul: 'RKA QRIS',
        deskripsi: 'Unggah target per KPI QRIS (per tahun/bulan), ringkasan, hapus per tahun.',
        route: 'admin.rka.qris.index',
    },
    {
        judul: 'Manajemen User',
        deskripsi: 'CRUD akun, penetapan kantor, kunci/buka akun, ganti password.',
        route: 'admin.users.index',
    },
];
</script>

<template>
    <Head title="Admin" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Admin</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Link
                        v-for="m in MENU"
                        :key="m.route"
                        :href="route(m.route)"
                        class="block rounded-lg bg-white p-5 shadow ring-1 ring-gray-100 transition hover:ring-indigo-300"
                    >
                        <h3 class="text-sm font-semibold text-gray-800">{{ m.judul }}</h3>
                        <p class="mt-1 text-xs text-gray-500">{{ m.deskripsi }}</p>
                    </Link>
                </div>

                <p class="mt-6 text-xs text-gray-400">
                    Seluruh domain dashboard region kini tersedia.
                </p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
