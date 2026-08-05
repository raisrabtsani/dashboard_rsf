<script setup>
import ApplyButton from '@/Components/ApplyButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import BarChart from '@/Components/BarChart.vue';
import KpiCard from '@/Components/KpiCard.vue';
import LineChart from '@/Components/LineChart.vue';
import LoadingOverlay from '@/Components/LoadingOverlay.vue';
import SortArrow from '@/Components/SortArrow.vue';
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import {
    fetchBranchPencapaian,
    fetchCabang,
    fetchChart,
    fetchChartMtd,
    fetchFilterOptions,
    fetchSnapshot,
    fetchUker,
} from '@/services/labaApi';
import { formatAngka, formatDelta, formatPct } from '@/utils/formatAngka';
import { deltaCls, pctCls } from '@/utils/pencapaian';
import { useScope } from '@/utils/scope';
import { useTableSort } from '@/utils/useTableSort';
import { warnaBulan } from '@/utils/chartColors';

const props = defineProps({
    tahunAwal: { type: Number, required: true },
    bulanAwal: { type: Number, required: true },
    filterAwal: { type: Object, default: () => ({}) },
});

const scope = useScope();

const BULAN = [
    { value: 1, label: 'Januari' }, { value: 2, label: 'Februari' }, { value: 3, label: 'Maret' },
    { value: 4, label: 'April' }, { value: 5, label: 'Mei' }, { value: 6, label: 'Juni' },
    { value: 7, label: 'Juli' }, { value: 8, label: 'Agustus' }, { value: 9, label: 'September' },
    { value: 10, label: 'Oktober' }, { value: 11, label: 'November' }, { value: 12, label: 'Desember' },
];

/*
 * POLA PENDING vs APPLIED (identik Simpanan), tapi periode = tahun + bulan.
 */
const pending = reactive({
    area_id: props.filterAwal.area_id ?? null,
    cabang_id: props.filterAwal.cabang_id ?? null,
    uker_id: props.filterAwal.uker_id ?? null,
    tahun: props.tahunAwal,
    bulan: props.bulanAwal,
});

const applied = reactive({ ...pending });

const dirty = computed(() =>
    Object.keys(applied).some((k) => (pending[k] ?? null) !== (applied[k] ?? null)),
);

const opsi = reactive({ area: [], cabang: [], uker: [], tahun: [] });
const snapshot = ref(null);
const chart = ref(null);
const chartMtd = ref(null);
const branch = ref({ grouping: 'cabang', baris: [] });

const memuat = reactive({ kartu: false, chart: false, mtd: false, tabel: false });
const drilldown = ref(null);

const sort = useTableSort('nilai', 'desc');

const KOLOM = [
    { key: 'nama', label: 'Nama', kelas: 'text-left' },
    { key: 'nilai', label: 'Laba YTD', kelas: 'text-right' },
    { key: 'target', label: 'RKA', kelas: 'text-right' },
    { key: 'gap', label: 'Gap', kelas: 'text-right' },
    { key: 'pencapaian', label: 'Pencapaian', kelas: 'text-right' },
];

const barisTerurut = computed(() => sort.urutkan(branch.value.baris ?? []));

const datasetChart = computed(() => {
    const titik = chart.value?.titik ?? [];

    return {
        labels: titik.map((t) => t.nama),
        datasets: [
            {
                label: 'Kumulatif YTD',
                borderColor: warnaBulan(1),
                backgroundColor: warnaBulan(1),
                data: titik.map((t) => t.nilai),
                spanGaps: false,
            },
        ],
    };
});

const datasetMtd = computed(() => {
    const titik = chartMtd.value?.titik ?? [];

    return {
        labels: titik.map((t) => t.nama),
        datasets: [
            {
                label: 'Laba per Bulan (MTD)',
                borderColor: warnaBulan(5),
                backgroundColor: warnaBulan(5),
                // Bulan tanpa data = null -> batang kosong, bukan 0.
                data: titik.map((t) => t.nilai),
            },
        ],
    };
});

async function muatOpsi() {
    const data = await fetchFilterOptions({ area_id: applied.area_id, cabang_id: applied.cabang_id });
    opsi.area = data.area ?? [];
    opsi.cabang = data.cabang ?? [];
    opsi.uker = data.uker ?? [];
    opsi.tahun = data.tahun ?? [];

    // Pastikan tahun terpilih selalu ada di daftar (mis. tahun berjalan tanpa data).
    if (opsi.tahun.length && !opsi.tahun.includes(applied.tahun)) {
        opsi.tahun = [applied.tahun, ...opsi.tahun];
    }
}

async function muatKartu() {
    memuat.kartu = true;
    try {
        snapshot.value = await fetchSnapshot({ ...applied });
    } finally {
        memuat.kartu = false;
    }
}

async function muatChart() {
    memuat.chart = true;
    try {
        chart.value = await fetchChart({ ...applied });
    } finally {
        memuat.chart = false;
    }
}

async function muatMtd() {
    memuat.mtd = true;
    try {
        chartMtd.value = await fetchChartMtd({ ...applied });
    } finally {
        memuat.mtd = false;
    }
}

async function muatTabel() {
    if (!scope.bolehLihatRanking.value) {
        branch.value = { grouping: 'cabang', baris: [] };

        return;
    }

    memuat.tabel = true;
    try {
        branch.value = await fetchBranchPencapaian({
            ...applied,
            cabang_id: drilldown.value ?? applied.cabang_id,
        });
    } finally {
        memuat.tabel = false;
    }
}

function terapkan() {
    Object.assign(applied, pending);
    drilldown.value = null;
    muatSemua();
}

function muatSemua() {
    return Promise.all([muatKartu(), muatChart(), muatMtd(), muatTabel()]);
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

watch(drilldown, () => muatTabel());

onMounted(async () => {
    await muatOpsi();
    await muatSemua();
});
</script>

<template>
    <Head title="Dashboard Laba" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Dashboard Laba
            </h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <!-- Filter bar: periode tahun + bulan (bukan date picker harian) -->
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
                            <span class="text-xs font-medium text-gray-500">Tahun</span>
                            <select v-model.number="pending.tahun" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                <option v-for="t in opsi.tahun" :key="t" :value="t">{{ t }}</option>
                            </select>
                        </label>

                        <label class="block">
                            <span class="text-xs font-medium text-gray-500">Bulan</span>
                            <select v-model.number="pending.bulan" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                <option v-for="b in BULAN" :key="b.value" :value="b.value">{{ b.label }}</option>
                            </select>
                        </label>

                        <div class="flex items-end">
                            <ApplyButton
                                :dirty="dirty"
                                :loading="memuat.kartu || memuat.chart"
                                @click="terapkan"
                            />
                        </div>
                    </div>

                    <p v-if="dirty" class="mt-2 text-xs text-amber-600">
                        Ada perubahan filter yang belum diterapkan.
                    </p>
                </div>

                <!-- Kartu KPI: Total + per segmen. Delta = MTD / YTD / YoY -->
                <div class="relative">
                    <LoadingOverlay :show="memuat.kartu" />
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <KpiCard
                            v-for="k in snapshot?.kartu ?? []"
                            :key="k.key"
                            :judul="k.judul"
                            :nilai="k.nilai"
                            :delta="k.delta"
                            :target="k.target"
                            :pencapaian="k.pencapaian"
                            :gap="k.gap"
                            :label-delta="snapshot?.label_delta"
                        />
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <!-- Chart 1: garis kumulatif YTD -->
                    <div class="relative rounded-lg bg-white p-4 shadow ring-1 ring-gray-100">
                        <LoadingOverlay :show="memuat.chart" />
                        <h3 class="text-sm font-semibold text-gray-700">
                            Laba Kumulatif YTD {{ chart?.tahun ?? '' }}
                        </h3>
                        <div class="mt-3 h-64">
                            <LineChart
                                v-if="datasetChart.datasets[0].data.length"
                                :labels="datasetChart.labels"
                                :datasets="datasetChart.datasets"
                            />
                            <p v-else class="pt-16 text-center text-sm text-gray-400">Tidak ada data.</p>
                        </div>
                    </div>

                    <!-- Chart 2: batang laba per bulan (MTD) -->
                    <div class="relative rounded-lg bg-white p-4 shadow ring-1 ring-gray-100">
                        <LoadingOverlay :show="memuat.mtd" />
                        <h3 class="text-sm font-semibold text-gray-700">
                            Laba per Bulan (MTD) {{ chartMtd?.tahun ?? '' }}
                        </h3>
                        <div class="mt-3 h-64">
                            <BarChart
                                v-if="datasetMtd.datasets[0].data.length"
                                :labels="datasetMtd.labels"
                                :datasets="datasetMtd.datasets"
                            />
                            <p v-else class="pt-16 text-center text-sm text-gray-400">Tidak ada data.</p>
                        </div>
                    </div>
                </div>

                <!-- Kinerja cabang / uker -->
                <div v-if="scope.bolehLihatRanking.value" class="relative rounded-lg bg-white shadow ring-1 ring-gray-100">
                    <LoadingOverlay :show="memuat.tabel" />

                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 p-4">
                        <h3 class="text-sm font-semibold text-gray-700">
                            Kinerja {{ branch.grouping === 'uker' ? 'Unit Kerja' : 'Cabang' }} (Laba YTD)
                        </h3>

                        <label class="flex items-center gap-2 text-xs text-gray-500">
                            Drill-down BO
                            <select v-model="drilldown" class="rounded-md border-gray-300 text-sm">
                                <option :value="null">— Semua Cabang —</option>
                                <option v-for="c in opsi.cabang" :key="c.id" :value="c.id">{{ c.nama }}</option>
                            </select>
                        </label>
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
                                    <td class="px-4 py-2 text-right tabular-nums text-gray-500">{{ formatAngka(b.target) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums" :class="deltaCls(b.gap)">
                                        {{ formatDelta(b.gap) }}
                                    </td>
                                    <td class="px-4 py-2 text-right font-semibold tabular-nums" :class="pctCls(b.pencapaian)">
                                        {{ formatPct(b.pencapaian) }}
                                    </td>
                                </tr>
                                <tr v-if="!barisTerurut.length">
                                    <td colspan="5" class="px-4 py-6 text-center text-gray-400">Tidak ada data.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
