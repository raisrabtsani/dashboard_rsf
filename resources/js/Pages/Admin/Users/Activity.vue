<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import LoadingOverlay from '@/Components/LoadingOverlay.vue';
import { Head } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';
import { fetchAktivitas } from '@/services/adminActivityApi';

const online = ref([]);
const pengguna = ref([]);
const statistik = ref({ online: 0, total: 0, ambang_menit: 5 });
const memuat = ref(false);

/** Refresh senyap tiap 30 detik supaya daftar tetap segar tanpa berkedip. */
const INTERVAL_MS = 30_000;
let timer = null;

async function muat({ senyap = false } = {}) {
    if (!senyap) memuat.value = true;
    try {
        const data = await fetchAktivitas();
        online.value = data.online ?? [];
        pengguna.value = data.pengguna ?? [];
        statistik.value = data.statistik ?? statistik.value;
    } finally {
        memuat.value = false;
    }
}

/** "baru saja" / "3 mnt lalu" / "2 jam lalu" / "4 hr lalu" / "—". */
function sejak(menit) {
    if (menit === null || menit === undefined) return '—';
    if (menit < 1) return 'baru saja';
    if (menit < 60) return `${menit} mnt lalu`;
    if (menit < 1440) return `${Math.floor(menit / 60)} jam lalu`;

    return `${Math.floor(menit / 1440)} hr lalu`;
}

onMounted(() => {
    muat();
    timer = setInterval(() => muat({ senyap: true }), INTERVAL_MS);
});

onUnmounted(() => {
    if (timer) clearInterval(timer);
});
</script>

<template>
    <Head title="Aktivitas Pengguna" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Aktivitas Pengguna</h2>
                <button class="btn btn-ghost" :disabled="memuat" @click="muat()">Segarkan</button>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <!-- Ringkasan -->
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="card">
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Online Sekarang</p>
                        <p class="mt-1 text-3xl font-semibold tabular-nums text-emerald-600">{{ statistik.online }}</p>
                        <p class="mt-1 text-[11px] text-gray-400">
                            Aktivitas ≤ {{ statistik.ambang_menit }} menit (dari tabel sessions)
                        </p>
                    </div>
                    <div class="card">
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Total Akun</p>
                        <p class="mt-1 text-3xl font-semibold tabular-nums text-gray-900">{{ statistik.total }}</p>
                    </div>
                </div>

                <!-- Online sekarang -->
                <div class="relative rounded-lg bg-white shadow-sm ring-1 ring-gray-100">
                    <LoadingOverlay :show="memuat" />
                    <div class="border-b border-gray-100 p-4">
                        <h3 class="text-sm font-semibold text-gray-700">
                            Online Sekarang
                            <span class="badge badge-positif ml-1">{{ online.length }}</span>
                        </h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="table-data">
                            <thead>
                                <tr>
                                    <th class="text-left">Pengguna</th>
                                    <th class="text-left">Tipe</th>
                                    <th class="text-left">IP</th>
                                    <th class="text-right">Sesi</th>
                                    <th class="text-right">Aktivitas Terakhir</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="u in online" :key="u.id" class="hover:bg-gray-50">
                                    <td>
                                        <span class="font-medium text-gray-800">{{ u.name }}</span>
                                        <span class="ml-1 text-gray-400">@{{ u.username }}</span>
                                    </td>
                                    <td class="text-gray-600">{{ u.role === 'admin' ? 'Admin' : u.tipe }}</td>
                                    <td class="tabular-nums text-gray-500">{{ u.ip ?? '—' }}</td>
                                    <td class="text-right tabular-nums text-gray-500">{{ u.sesi }}</td>
                                    <td class="text-right tabular-nums text-gray-600" :title="u.terakhir_aktivitas">
                                        {{ sejak(u.menit_lalu) }}
                                    </td>
                                </tr>
                                <tr v-if="!online.length">
                                    <td colspan="5" class="py-6 text-center text-gray-400">Tidak ada yang online.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Semua pengguna: terakhir aktif -->
                <div class="rounded-lg bg-white shadow-sm ring-1 ring-gray-100">
                    <div class="border-b border-gray-100 p-4">
                        <h3 class="text-sm font-semibold text-gray-700">Terakhir Aktif — Semua Pengguna</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="table-data">
                            <thead>
                                <tr>
                                    <th class="text-left">Pengguna</th>
                                    <th class="text-left">Kantor</th>
                                    <th class="text-left">Status</th>
                                    <th class="text-right">Terakhir Aktif</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="u in pengguna" :key="u.id" class="hover:bg-gray-50">
                                    <td>
                                        <span class="font-medium text-gray-800">{{ u.name }}</span>
                                        <span class="ml-1 text-gray-400">@{{ u.username }}</span>
                                    </td>
                                    <td class="text-gray-600">
                                        {{ u.uker ?? u.cabang ?? '—' }}
                                    </td>
                                    <td>
                                        <span v-if="u.online" class="badge badge-positif">Online</span>
                                        <span v-else class="badge badge-netral">Offline</span>
                                    </td>
                                    <td class="text-right tabular-nums text-gray-600" :title="u.terakhir_aktif ?? ''">
                                        {{ sejak(u.menit_lalu) }}
                                    </td>
                                </tr>
                                <tr v-if="!pengguna.length">
                                    <td colspan="4" class="py-6 text-center text-gray-400">Belum ada data.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
