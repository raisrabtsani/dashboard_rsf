<script setup>
import ApplyButton from '@/Components/ApplyButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import KpiCard from '@/Components/KpiCard.vue';
import LineChart from '@/Components/LineChart.vue';
import LoadingOverlay from '@/Components/LoadingOverlay.vue';
import SortArrow from '@/Components/SortArrow.vue';
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import {
    fetchBranch,
    fetchCabang,
    fetchChart,
    fetchFilterOptions,
    fetchSnapshot,
    fetchUker,
} from '@/services/simpananHourlyApi';
import { formatAngka, formatDelta } from '@/utils/formatAngka';
import { deltaCls } from '@/utils/pencapaian';
import { useScope } from '@/utils/scope';
import { useTableSort } from '@/utils/useTableSort';
import { warnaBulan } from '@/utils/chartColors';

const props = defineProps({
    tanggalAwal: { type: String, required: true },
    intervalRefresh: { type: Number, default: 120 },
});

const scope = useScope();

const pending = reactive({
    area_id: null,
    cabang_id: null,
    uker_id: null,
    tanggal: props.tanggalAwal,
    jam: null,
});

const applied = reactive({ ...pending });

const dirty = computed(() =>
    Object.keys(applied).some((k) => (pending[k] ?? null) !== (applied[k] ?? null)),
);

const opsi = reactive({ area: [], cabang: [], uker: [], tanggal: [] });
const snapshot = ref(null);
const chart = ref(null);
const branch = ref({ grouping: 'cabang', baris: [] });
const memuat = reactive({ kartu: false, chart: false, tabel: false });

/**
 * Auto-refresh "senyap": overlay loading TIDAK dinyalakan agar layar videotron
 * tidak berkedip tiap dua menit.
 */
const senyap = ref(false);
let timer = null;

const sort = useTableSort('nilai', 'desc');
const barisTerurut = computed(() => sort.urutkan(branch.value.baris ?? []));

const KOLOM = [
    { key: 'nama', label: 'Nama', kelas: 'text-left' },
    { key: 'nilai', label: 'Posisi Jam Ini', kelas: 'text-right' },
    { key: 'baseline', label: 'Posisi H-1', kelas: 'text-right' },
    { key: 'delta', label: 'Pergerakan', kelas: 'text-right' },
];

// Domain ini hanya punya satu delta: vs posisi HARIAN hari sebelumnya.
const LABEL_DELTA = [{ key: 'dtd', label: 'vs H-1' }];

const jamTersedia = computed(() => snapshot.value?.jam_tersedia ?? []);

const datasetChart = computed(() => {
    const c = chart.value;
    if (!c?.seri?.length) return null;

    return {
        labels: c.label,
        datasets: c.seri.map((s, i) => ({
            label: s.judul,
            borderColor: warnaBulan(i + 1),
            backgroundColor: warnaBulan(i + 1),
            data: s.titik,
            spanGaps: false,
        })),
    };
});

async function muatOpsi() {
    const data = await fetchFilterOptions({ area_id: applied.area_id, cabang_id: applied.cabang_id });
    opsi.area = data.area ?? [];
    opsi.cabang = data.cabang ?? [];
    opsi.uker = data.uker ?? [];
    opsi.tanggal = data.tanggal ?? [];
}

/**
 * Semua pemuatan memakai `applied` — perubahan filter yang BELUM di-Terapkan
 * sengaja diabaikan, termasuk oleh auto-refresh.
 */
async function muatSemua({ diam = false } = {}) {
    senyap.value = diam;

    if (!diam) {
        memuat.kartu = memuat.chart = memuat.tabel = true;
    }

    try {
        const [s, c, b] = await Promise.all([
            fetchSnapshot({ ...applied }),
            fetchChart({ ...applied }),
            fetchBranch({ ...applied }),
        ]);

        snapshot.value = s;
        chart.value = c;
        branch.value = b;
    } finally {
        memuat.kartu = memuat.chart = memuat.tabel = false;
        senyap.value = false;
    }
}

function terapkan() {
    Object.assign(applied, pending);
    muatSemua();
}

function mulaiAutoRefresh() {
    hentikanAutoRefresh();
    timer = setInterval(() => muatSemua({ diam: true }), props.intervalRefresh * 1000);
}

function hentikanAutoRefresh() {
    if (timer !== null) {
        clearInterval(timer);
        timer = null;
    }
}

watch(
    () => pending.area_id,
    async (areaId) => {
        pending.cabang_id = null;
        pending.uker_id = null;
        opsi.uker = [];
        opsi.cabang = areaId ? await fetchCabang(areaId) : (await fetchFilterOptions({})).cabang;
    },
);

watch(
    () => pending.cabang_id,
    async (cabangId) => {
        pending.uker_id = null;
        opsi.uker = cabangId ? await fetchUker(cabangId) : [];
    },
);

onMounted(async () => {
    await muatOpsi();
    await muatSemua();
    mulaiAutoRefresh();
});

// Wajib: tanpa ini interval terus hidup setelah pindah halaman (Inertia tidak
// me-reload dokumen), menumpuk request tiap kali halaman dibuka ulang.
onUnmounted(hentikanAutoRefresh);
</script>

<template>
    <Head title="DPK Hourly" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    DPK Hourly — Posisi per Jam
                </h2>
                <p class="text-xs text-gray-500">
                    Diperbarui otomatis tiap {{ Math.round(intervalRefresh / 60) }} menit ·
                    terakhir {{ snapshot?.diperbarui ?? '–' }}
                </p>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <!-- Filter bar -->
                <div class="rounded-lg bg-white p-4 shadow ring-1 ring-gray-100">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-6">
                        <label v-if="scope.bolehPilihArea.value" class="block">
                            <span class="text-xs font-medium text-gray-500">Area</span>
                            <select v-model="pending.area_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                <option :value="null">Semua Area</option>
                                <option v-for="a in opsi.area" :key="a.id" :value="a.id">{{ a.nama }}</option>
                            </select>
                        </label>

                        <label v-if="scope.bolehPilihCabang.value" class="block">
                            <span class="text-xs font-medium text-gray-500">Cabang (BO)</span>
                            <select v-model="pending.cabang_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                <option :value="null">Semua Cabang</option>
                                <option v-for="c in opsi.cabang" :key="c.id" :value="c.id">{{ c.nama }}</option>
                            </select>
                        </label>

                        <label v-if="scope.bolehPilihUker.value" class="block">
                            <span class="text-xs font-medium text-gray-500">Unit Kerja</span>
                            <select v-model="pending.uker_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                <option :value="null">Semua Uker</option>
                                <option v-for="u in opsi.uker" :key="u.id" :value="u.id">{{ u.nama }}</option>
                            </select>
                        </label>

                        <label class="block">
                            <span class="text-xs font-medium text-gray-500">Tanggal (EOM)</span>
                            <select v-model="pending.tanggal" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                <option v-for="t in opsi.tanggal" :key="t" :value="t">{{ t }}</option>
                            </select>
                        </label>

                        <label class="block">
                            <span class="text-xs font-medium text-gray-500">Jam</span>
                            <select v-model="pending.jam" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                <option :value="null">Terbaru</option>
                                <option v-for="j in jamTersedia" :key="j" :value="j">
                                    {{ String(j).padStart(2, '0') }}:00
                                </option>
                            </select>
                        </label>

                        <div class="flex items-end">
                            <ApplyButton :dirty="dirty" :loading="memuat.kartu" @click="terapkan" />
                        </div>
                    </div>

                    <p v-if="dirty" class="mt-2 text-xs text-amber-600">
                        Ada perubahan filter yang belum diterapkan — auto-refresh tetap memakai
                        filter yang sudah diterapkan.
                    </p>
                </div>

                <!-- Kartu KPI -->
                <div class="relative">
                    <LoadingOverlay :show="memuat.kartu" :senyap="senyap" />
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
                        <KpiCard
                            v-for="k in snapshot?.kartu ?? []"
                            :key="k.key"
                            :judul="k.judul"
                            :nilai="k.nilai"
                            :delta="k.delta"
                            :label-delta="LABEL_DELTA"
                            :tampilkan-target="false"
                        />
                    </div>
                    <p class="mt-2 text-xs text-gray-500">
                        Pembanding: posisi harian
                        <strong>{{ snapshot?.tanggal_baseline ?? '–' }}</strong>
                        (tabel simpanan), bukan jam sebelumnya.
                    </p>
                </div>

                <!-- Tren antar jam -->
                <div class="relative rounded-lg bg-white p-4 shadow ring-1 ring-gray-100">
                    <LoadingOverlay :show="memuat.chart" :senyap="senyap" />
                    <h3 class="text-sm font-semibold text-gray-700">
                        Pergerakan per Jam — {{ snapshot?.tanggal ?? '' }}
                    </h3>
                    <div class="mt-3 h-72">
                        <LineChart
                            v-if="datasetChart"
                            :labels="datasetChart.labels"
                            :datasets="datasetChart.datasets"
                        />
                        <p v-else class="pt-16 text-center text-sm text-gray-400">Tidak ada data.</p>
                    </div>
                </div>

                <!-- Tabel per entitas -->
                <div class="relative rounded-lg bg-white shadow ring-1 ring-gray-100">
                    <LoadingOverlay :show="memuat.tabel" :senyap="senyap" />

                    <div class="border-b border-gray-100 p-4">
                        <h3 class="text-sm font-semibold text-gray-700">
                            Posisi {{ branch.grouping === 'uker' ? 'Unit Kerja' : 'Cabang' }}
                            <span v-if="branch.jam !== null && branch.jam !== undefined">
                                pukul {{ String(branch.jam).padStart(2, '0') }}:00
                            </span>
                        </h3>
                        <p class="mt-1 text-xs text-gray-500">
                            diperbarui {{ snapshot?.diperbarui ?? '–' }}
                        </p>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-100 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th
                                        v-for="k in KOLOM"
                                        :key="k.key"
                                        scope="col"
                                        class="cursor-pointer select-none px-4 py-2 text-xs font-semibold text-gray-500"
                                        :class="k.kelas"
                                        @click="sort.urutkanKolom(k.key)"
                                    >
                                        {{ k.label }}
                                        <SortArrow :arah="sort.arahUntuk(k.key)" />
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <tr v-for="b in barisTerurut" :key="b.id" class="hover:bg-gray-50">
                                    <td class="px-4 py-2 text-gray-800">{{ b.nama }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ formatAngka(b.nilai) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-gray-500">
                                        {{ formatAngka(b.baseline) }}
                                    </td>
                                    <td class="px-4 py-2 text-right tabular-nums" :class="deltaCls(b.delta?.nilai)">
                                        {{ formatDelta(b.delta?.nilai) }}
                                    </td>
                                </tr>
                                <tr v-if="!barisTerurut.length">
                                    <td colspan="4" class="px-4 py-6 text-center text-gray-400">Tidak ada data.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
