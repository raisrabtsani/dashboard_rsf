<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const PRIORITY_MENU = [
    {
        title: 'Kantor Cabang',
        description: 'Lihat struktur kantor cabang, area, dan seluruh unit kerja di bawahnya.',
        route: 'admin.cabang.index',
        accent: 'cyan',
        icon: 'building',
        badge: 'Master Kantor',
    },
    {
        title: 'Manajemen User',
        description: 'Kelola akun, akses kantor, status pengguna, dan penggantian password.',
        route: 'admin.users.index',
        accent: 'blue',
        icon: 'users',
        badge: 'Kelola Akses',
    },
    {
        title: 'Aktivitas Pengguna',
        description: 'Pantau pengguna online dan waktu aktivitas terakhir setiap akun.',
        route: 'admin.activity.index',
        accent: 'emerald',
        icon: 'activity',
        badge: 'Monitoring',
    },
];

const DOMAIN_MENU = [
    {
        title: 'Simpanan',
        description: 'Kelola Simpanan Non-Wholesale dan Simpanan Wholesale dalam satu menu.',
        accent: 'blue',
        icon: 'wallet',
        options: [
            { label: 'Upload Non-Wholesale', route: 'admin.upload.simpanan.index', type: 'upload' },
            { label: 'RKA Non-Wholesale', route: 'admin.rka.simpanan.index', type: 'rka' },
            { label: 'Upload Wholesale', route: 'admin.upload.simpanan-wholesale.index', type: 'upload' },
            { label: 'RKA Wholesale', route: 'admin.rka.simpanan-wholesale.index', type: 'rka' },
        ],
    },
    {
        title: 'Pinjaman',
        description: 'Kelola pinjaman reguler dan segmen commercial.',
        accent: 'indigo',
        icon: 'credit',
        options: [
            { label: 'Upload Pinjaman', route: 'admin.upload.pinjaman.index', type: 'upload' },
            { label: 'RKA Pinjaman', route: 'admin.rka.pinjaman.index', type: 'rka' },
            { label: 'Upload Commercial', route: 'admin.upload.pinjaman-commercial.index', type: 'upload' },
            { label: 'RKA Commercial', route: 'admin.rka.pinjaman-commercial.index', type: 'rka' },
        ],
    },
    {
        title: 'DPK Hourly',
        description: 'Kelola data DPK per jam secara terpisah dari Simpanan reguler dan Wholesale.',
        accent: 'cyan',
        icon: 'clock',
        options: [
            { label: 'Upload DPK Hourly', route: 'admin.upload.simpanan-hourly.index', type: 'upload' },
        ],
    },
    {
        title: 'Recovery EC & NET DG',
        description: 'Kelola data recovery, target, dan flow hapus buku.',
        accent: 'amber',
        icon: 'recovery',
        options: [
            { label: 'Upload Recovery EC', route: 'admin.upload.recovery.index', type: 'upload' },
            { label: 'RKA Recovery', route: 'admin.rka.recovery.index', type: 'rka' },
            { label: 'Upload NET DG', route: 'admin.upload.ph.index', type: 'upload' },
        ],
    },
    {
        title: 'Laba',
        description: 'Kelola realisasi laba kumulatif dan target tahunan.',
        accent: 'emerald',
        icon: 'chart',
        options: [
            { label: 'Upload Data Laba', route: 'admin.upload.laba.index', type: 'upload' },
            { label: 'RKA Laba', route: 'admin.rka.laba.index', type: 'rka' },
        ],
    },
    {
        title: 'Merchant',
        description: 'Kelola KPI dan target EDC serta QRIS.',
        accent: 'violet',
        icon: 'merchant',
        options: [
            { label: 'Upload EDC', route: 'admin.upload.edc.index', type: 'upload' },
            { label: 'RKA EDC', route: 'admin.rka.edc.index', type: 'rka' },
            { label: 'Upload QRIS', route: 'admin.upload.qris.index', type: 'upload' },
            { label: 'RKA QRIS', route: 'admin.rka.qris.index', type: 'rka' },
        ],
    },
];

const accentClass = {
    blue: {
        soft: 'bg-blue-50 text-blue-600 ring-blue-100',
        border: 'hover:border-blue-200 hover:shadow-blue-100/70',
        glow: 'from-blue-500/10',
    },
    indigo: {
        soft: 'bg-indigo-50 text-indigo-600 ring-indigo-100',
        border: 'hover:border-indigo-200 hover:shadow-indigo-100/70',
        glow: 'from-indigo-500/10',
    },
    cyan: {
        soft: 'bg-cyan-50 text-cyan-600 ring-cyan-100',
        border: 'hover:border-cyan-200 hover:shadow-cyan-100/70',
        glow: 'from-cyan-500/10',
    },
    amber: {
        soft: 'bg-amber-50 text-amber-600 ring-amber-100',
        border: 'hover:border-amber-200 hover:shadow-amber-100/70',
        glow: 'from-amber-500/10',
    },
    emerald: {
        soft: 'bg-emerald-50 text-emerald-600 ring-emerald-100',
        border: 'hover:border-emerald-200 hover:shadow-emerald-100/70',
        glow: 'from-emerald-500/10',
    },
    violet: {
        soft: 'bg-violet-50 text-violet-600 ring-violet-100',
        border: 'hover:border-violet-200 hover:shadow-violet-100/70',
        glow: 'from-violet-500/10',
    },
};
</script>

<template>
    <Head title="Admin" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-lg font-bold leading-tight text-slate-800 sm:text-xl">Admin</h2>
                <p class="mt-0.5 text-xs text-slate-500">Pusat pengelolaan data dan pengguna dashboard</p>
            </div>
        </template>

        <div class="min-h-[calc(100vh-7rem)] bg-slate-50/80 py-6 sm:py-8">
            <div class="mx-auto max-w-[1440px] px-4 sm:px-6 lg:px-8">
                <!-- Hero -->
                <section class="relative overflow-hidden rounded-2xl bg-gradient-to-r from-[#0758bd] via-[#0b65ce] to-[#1477df] px-5 py-6 text-white shadow-lg shadow-blue-900/10 sm:px-7 sm:py-7">
                    <div class="pointer-events-none absolute -right-20 -top-24 h-64 w-64 rounded-full bg-white/10 blur-2xl"></div>
                    <div class="pointer-events-none absolute -bottom-24 left-1/3 h-56 w-56 rounded-full bg-cyan-300/10 blur-3xl"></div>

                    <div class="relative flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                        <div class="max-w-2xl">
                            <div class="mb-3 inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/10 px-3 py-1 text-[11px] font-semibold tracking-wide backdrop-blur-sm">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-300"></span>
                                ADMINISTRATOR PANEL
                            </div>
                            <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">Kelola dashboard dari satu halaman</h1>
                            <p class="mt-2 max-w-xl text-sm leading-6 text-blue-100">
                                Akses pengguna, aktivitas, data aktual, dan target RKA telah dikelompokkan agar lebih cepat ditemukan.
                            </p>
                        </div>

                        <div class="grid grid-cols-3 gap-2 sm:gap-3">
                            <div class="rounded-xl border border-white/15 bg-white/10 px-4 py-3 text-center backdrop-blur-sm">
                                <div class="text-xl font-bold">6</div>
                                <div class="mt-0.5 text-[10px] uppercase tracking-wide text-blue-100">Domain</div>
                            </div>
                            <div class="rounded-xl border border-white/15 bg-white/10 px-4 py-3 text-center backdrop-blur-sm">
                                <div class="text-xl font-bold">3</div>
                                <div class="mt-0.5 text-[10px] uppercase tracking-wide text-blue-100">Admin Tools</div>
                            </div>
                            <div class="rounded-xl border border-white/15 bg-white/10 px-4 py-3 text-center backdrop-blur-sm">
                                <div class="text-xl font-bold">1</div>
                                <div class="mt-0.5 text-[10px] uppercase tracking-wide text-blue-100">Control Hub</div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Priority -->
                <section class="mt-7">
                    <div class="mb-3 flex items-end justify-between gap-4">
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-blue-600">Prioritas Admin</p>
                            <h2 class="mt-1 text-lg font-bold text-slate-800">Pengguna dan aktivitas</h2>
                        </div>
                        <span class="hidden text-xs text-slate-400 sm:block">Akses cepat administrasi akun</span>
                    </div>

                    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <Link
                            v-for="item in PRIORITY_MENU"
                            :key="item.route"
                            :href="route(item.route)"
                            class="group relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:border-blue-200 hover:shadow-lg hover:shadow-blue-100/60 sm:p-6"
                        >
                            <div class="absolute inset-y-0 right-0 w-40 bg-gradient-to-l from-blue-50/80 to-transparent opacity-70 transition group-hover:opacity-100"></div>

                            <div class="relative flex items-start gap-4">
                                <div
                                    class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl ring-1"
                                    :class="accentClass[item.accent].soft"
                                >
                                    <svg v-if="item.icon === 'users'" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm13 10v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" />
                                    </svg>
                                    <svg v-else-if="item.icon === 'building'" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 21h18M6 21V5l6-3 6 3v16M9 9h.01M9 13h.01M9 17h.01M15 9h.01M15 13h.01M15 17h.01" />
                                    </svg>
                                    <svg v-else class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 12h4l3-8 4 16 3-8h4" />
                                    </svg>
                                </div>

                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="text-base font-bold text-slate-800">{{ item.title }}</h3>
                                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-semibold text-slate-500">
                                            {{ item.badge }}
                                        </span>
                                    </div>
                                    <p class="mt-1.5 max-w-xl text-sm leading-5 text-slate-500">{{ item.description }}</p>
                                </div>

                                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-50 text-slate-400 transition group-hover:bg-blue-600 group-hover:text-white">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6" />
                                    </svg>
                                </div>
                            </div>
                        </Link>
                    </div>
                </section>

                <!-- Domains -->
                <section class="mt-8">
                    <div class="mb-4 flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-blue-600">Operasional</p>
                            <h2 class="mt-1 text-lg font-bold text-slate-800">Upload data dan RKA</h2>
                            <p class="mt-1 text-xs text-slate-500">Pilih domain, lalu pilih jenis pengelolaan yang dibutuhkan.</p>
                        </div>
                        <div class="mt-2 inline-flex w-fit items-center gap-2 rounded-full bg-white px-3 py-1.5 text-[11px] text-slate-500 shadow-sm ring-1 ring-slate-200 sm:mt-0">
                            <span class="h-2 w-2 rounded-full bg-blue-500"></span>
                            Upload aktual
                            <span class="ml-1 h-2 w-2 rounded-full bg-emerald-500"></span>
                            Target RKA
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <article
                            v-for="domain in DOMAIN_MENU"
                            :key="domain.title"
                            class="group relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:shadow-lg"
                            :class="accentClass[domain.accent].border"
                        >
                            <div
                                class="pointer-events-none absolute -right-12 -top-12 h-36 w-36 rounded-full bg-gradient-to-br to-transparent opacity-80"
                                :class="accentClass[domain.accent].glow"
                            ></div>

                            <div class="relative">
                                <div class="flex items-start gap-3.5">
                                    <div
                                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl ring-1"
                                        :class="accentClass[domain.accent].soft"
                                    >
                                        <svg v-if="domain.icon === 'wallet'" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M20 7V5a2 2 0 0 0-2-2H5a3 3 0 0 0 0 6h15v10a2 2 0 0 1-2 2H5a3 3 0 0 1-3-3V6" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M16 13h2" />
                                        </svg>
                                        <svg v-else-if="domain.icon === 'credit'" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                            <rect x="3" y="5" width="18" height="14" rx="2" />
                                            <path stroke-linecap="round" d="M3 10h18M7 15h3" />
                                        </svg>
                                        <svg v-else-if="domain.icon === 'building'" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 21h18M6 21V5l6-3 6 3v16M9 9h.01M9 13h.01M9 17h.01M15 9h.01M15 13h.01M15 17h.01" />
                                        </svg>
                                        <svg v-else-if="domain.icon === 'recovery'" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 12a9 9 0 1 0 3-6.7L3 8" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 3v5h5M12 7v5l3 2" />
                                        </svg>
                                        <svg v-else-if="domain.icon === 'clock'" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                            <circle cx="12" cy="12" r="9" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 7v5l3.5 2" />
                                            <path stroke-linecap="round" d="M8 3.9 6.7 2.6M16 3.9l1.3-1.3" />
                                        </svg>
                                        <svg v-else-if="domain.icon === 'chart'" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 19V9M10 19V5M16 19v-7M22 19H2" />
                                        </svg>
                                        <svg v-else class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                            <rect x="3" y="4" width="18" height="16" rx="2" />
                                            <path stroke-linecap="round" d="M7 8h10M7 12h4M15 12h2M7 16h2M13 16h4" />
                                        </svg>
                                    </div>

                                    <div class="min-w-0">
                                        <h3 class="text-[15px] font-bold text-slate-800">{{ domain.title }}</h3>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">{{ domain.description }}</p>
                                    </div>
                                </div>

                                <div class="mt-5 grid grid-cols-1 gap-2" :class="domain.options.length >= 4 ? 'sm:grid-cols-2' : ''">
                                    <Link
                                        v-for="option in domain.options"
                                        :key="option.route"
                                        :href="route(option.route)"
                                        class="group/option flex items-center justify-between rounded-xl border px-3.5 py-3 text-xs font-semibold transition"
                                        :class="option.type === 'upload'
                                            ? 'border-blue-100 bg-blue-50/70 text-blue-700 hover:border-blue-200 hover:bg-blue-100'
                                            : 'border-emerald-100 bg-emerald-50/70 text-emerald-700 hover:border-emerald-200 hover:bg-emerald-100'"
                                    >
                                        <span class="flex min-w-0 items-center gap-2.5">
                                            <svg v-if="option.type === 'upload'" class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L7 9m5-5 5 5M5 20h14" />
                                            </svg>
                                            <svg v-else class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 19V9M10 19V5M16 19v-7M22 19H2" />
                                            </svg>
                                            <span class="truncate">{{ option.label }}</span>
                                        </span>
                                        <svg class="h-3.5 w-3.5 shrink-0 opacity-50 transition group-hover/option:translate-x-0.5 group-hover/option:opacity-100" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6" />
                                        </svg>
                                    </Link>
                                </div>
                            </div>
                        </article>
                    </div>
                </section>

                <div class="mt-7 flex flex-col gap-2 border-t border-slate-200 pt-5 text-[11px] text-slate-400 sm:flex-row sm:items-center sm:justify-between">
                    <span>Seluruh domain dashboard Region 7 Jakarta 2 tersedia.</span>
                    <span>Gunakan menu sesuai jenis data: aktual atau RKA.</span>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
