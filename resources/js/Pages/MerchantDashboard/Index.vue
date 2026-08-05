<script setup>
import ApplyButton from '@/Components/ApplyButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import KpiCard from '@/Components/KpiCard.vue';
import LineChart from '@/Components/LineChart.vue';
import LoadingOverlay from '@/Components/LoadingOverlay.vue';
import SortArrow from '@/Components/SortArrow.vue';
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import * as edcApi from '@/services/edcApi';
import * as qrisApi from '@/services/qrisApi';
import { formatAngka, formatDelta, formatDeltaJumlah, formatJumlah, formatPct } from '@/utils/formatAngka';
import { deltaCls, pctClsArah } from '@/utils/pencapaian';
import { useScope } from '@/utils/scope';
import { useTableSort } from '@/utils/useTableSort';
import { warnaBulan } from '@/utils/chartColors';

const props = defineProps({
    tanggalAwal: { type: String, required: true },
    filterAwal: { type: Object, default: () => ({}) },
});

const scope = useScope();

/** Toggle EDC | QRIS — SATU halaman, dua sub-domain. */
const TOGGLE = [
    { key: 'edc', label: 'EDC' },
    { key: 'qris', label: 'QRIS' },
];
const toggle = ref('edc');
const api = computed(() => (toggle.value === 'edc' ? edcApi : qrisApi));

const pending = reactive({
    area_id: props.filterAwal.area_id ?? null,
    cabang_id: props.filterAwal.cabang_id ?? null,
    uker_id: props.filterAwal.uker_id ?? null,
    tanggal: props.tanggalAwal,
});
const applied = reactive({ ...pending });

const dirty = computed(() =>
    Object.keys(applied).some((k) => (pending[k] ?? null) !== (applied[k] ?? null)),
);

const opsi = reactive({ area: [], cabang: [], uker: [] });
const kpiMeta = ref([]);
/** KPI yang dipakai chart + tabel kinerja (kartu selalu menampilkan semua KPI). */
const selectedKpi = ref(null);

const snapshot = ref(null);
const chart = ref(null);
const branch = ref({ grouping: 'cabang', baris: [], rupiah: false, punya_target: false, inverse: false });

const memuat = reactive({ kartu: false, chart: false, tabel: false });
const drilldown = ref(null);
const sort = useTableSort('nilai', 'desc');

const barisTerurut = computed(() => sort.urutkan(branch.value.baris ?? []));

const KOLOM = computed(() => {
    const kolom = [
        { key: 'nama', label: 'Nama', kelas: 'text-left' },
        { key: 'nilai', label: 'Nilai', kelas: 'text-right' },
    ];

    if (branch.value.punya_target) {
        kolom.push(
            { key: 'target', label: 'RKA', kelas: 'text-right' },
            { key: 'gap', label: 'Gap', kelas: 'text-right' },
            { key: 'pencapaian', label: 'Pencapaian', kelas: 'text-right' },
        );
    }

    return kolom;
});

const fmtBranchNilai = computed(() => (branch.value.rupiah ? formatAngka : formatJumlah));
const fmtBranchDelta = computed(() => (branch.value.rupiah ? formatDelta : formatDeltaJumlah));
const formatChart = computed(() => (chart.value?.rupiah ? formatAngka : formatJumlah));

const datasetChart = computed(() => {
    const seri = chart.value?.seri ?? [];
    const hariMaks = Math.max(1, ...seri.flatMap((s) => s.titik.map((t) => t.hari)));
    const labels = Array.from({ length: hariMaks }, (_, i) => String(i + 1));

    return {
        labels,
        datasets: seri.map((s) => ({
            label: s.nama,
            borderColor: warnaBulan(s.bulan),
            backgroundColor: warnaBulan(s.bulan),
            data: labels.map((h) => s.titik.find((t) => t.hari === Number(h))?.nilai ?? null),
            spanGaps: false,
        })),
    };
});

const judulChart = computed(() => {
    const kpi = kpiMeta.value.find((k) => k.kode === selectedKpi.value);
    if (!kpi) return 'Tren Harian';

    return kpi.flow ? `Tren Kumulatif YTD — ${kpi.label}` : `Tren Harian — ${kpi.label}`;
});

async function muatOpsi() {
    const data = await api.value.fetchFilterOptions({ area_id: applied.area_id, cabang_id: applied.cabang_id });
    opsi.area = data.area ?? [];
    opsi.cabang = data.cabang ?? [];
    opsi.uker = data.uker ?? [];
    kpiMeta.value = data.kpi ?? [];

    if (!kpiMeta.value.some((k) => k.kode === selectedKpi.value)) {
        selectedKpi.value = kpiMeta.value[0]?.kode ?? null;
    }
}

async function muatKartu() {
    memuat.kartu = true;
    try {
        snapshot.value = await api.value.fetchSnapshot({ ...applied });
    } finally {
        memuat.kartu = false;
    }
}

async function muatChart() {
    memuat.chart = true;
    try {
        chart.value = await api.value.fetchChart({ ...applied, kpi: selectedKpi.value });
    } finally {
        memuat.chart = false;
    }
}

async function muatTabel() {
    if (!scope.bolehLihatRanking.value) {
        branch.value = { grouping: 'cabang', baris: [], rupiah: false, punya_target: false, inverse: false };

        return;
    }

    memuat.tabel = true;
    try {
        branch.value = await api.value.fetchBranchPencapaian({
            ...applied,
            kpi: selectedKpi.value,
            cabang_id: drilldown.value ?? applied.cabang_id,
        });
    } finally {
        memuat.tabel = false;
    }
}

function muatSemua() {
    return Promise.all([muatKartu(), muatChart(), muatTabel()]);
}

function terapkan() {
    Object.assign(applied, pending);
    drilldown.value = null;
    muatSemua();
}

async function gantiToggle(key) {
    if (toggle.value === key) return;
    toggle.value = key;
    drilldown.value = null;
    await muatOpsi();
    await muatSemua();
}

// Ganti KPI: cukup muat ulang chart + tabel (kartu tidak bergantung KPI terpilih).
watch(selectedKpi, () => {
    muatChart();
    muatTabel();
});

watch(drilldown, () => muatTabel());

watch(
    () => pending.area_id,
    async (areaId) => {
        pending.cabang_id = null;
        pending.uker_id = null;
        opsi.uker = [];
        opsi.cabang = areaId ? await api.value.fetchCabang(areaId) : (await api.value.fetchFilterOptions({})).cabang;
    },
);

watch(
    () => pending.cabang_id,
    async (cabangId) => {
        pending.uker_id = null;
        opsi.uker = cabangId ? await api.value.fetchUker(cabangId) : [];
    },
);

onMounted(async () => {
    await muatOpsi();
    await muatSemua();
});
</script>

<template>
    <Head title="Dashboard Merchant" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Dashboard Merchant
            </h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <!-- Toggle EDC | QRIS -->
                <div class="flex flex-wrap items-center gap-2 rounded-lg bg-white p-2 shadow ring-1 ring-gray-100">
                    <button
                        v-for="t in TOGGLE"
                        :key="t.key"
                        type="button"
                        class="rounded-md px-5 py-2 text-sm font-semibold transition"
                        :class="toggle === t.key ? 'bg-brand-600 text-white' : 'text-gray-600 hover:bg-gray-100'"
                        @click="gantiToggle(t.key)"
                    >
                        {{ t.label }}
                    </button>
                </div>

                <!-- Filter bar -->
                <div class="rounded-lg bg-white p-4 shadow ring-1 ring-gray-100">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
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
                            <span class="text-xs font-medium text-gray-500">Tanggal Posisi</span>
                            <input v-model="pending.tanggal" type="date" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                        </label>

                        <div class="flex items-end">
                            <ApplyButton :dirty="dirty" :loading="memuat.kartu || memuat.chart" @click="terapkan" />
                        </div>
                    </div>

                    <p v-if="dirty" class="mt-2 text-xs text-amber-600">
                        Ada perubahan filter yang belum diterapkan.
                    </p>
                </div>

                <!-- Kartu KPI: satu kartu per KPI toggle aktif -->
                <div class="relative">
                    <LoadingOverlay :show="memuat.kartu" />
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <KpiCard
                            v-for="k in snapshot?.kartu ?? []"
                            :key="k.kode"
                            :judul="k.judul"
                            :nilai="k.nilai"
                            :delta="k.delta"
                            :target="k.target"
                            :pencapaian="k.pencapaian"
                            :gap="k.gap"
                            :label-delta="k.label_delta"
                            :inverse="k.inverse"
                            :rupiah="k.rupiah"
                            :tampilkan-target="k.punya_target"
                        />
                    </div>
                </div>

                <!-- Tren harian KPI terpilih -->
                <div class="relative rounded-lg bg-white p-4 shadow ring-1 ring-gray-100">
                    <LoadingOverlay :show="memuat.chart" />
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h3 class="text-sm font-semibold text-gray-700">
                            {{ judulChart }} {{ chart?.tahun ?? '' }}
                        </h3>
                        <label class="flex items-center gap-2 text-xs text-gray-500">
                            KPI
                            <select v-model="selectedKpi" class="rounded-md border-gray-300 text-sm">
                                <option v-for="k in kpiMeta" :key="k.kode" :value="k.kode">{{ k.label }}</option>
                            </select>
                        </label>
                    </div>
                    <div class="mt-3 h-72">
                        <LineChart
                            v-if="datasetChart.datasets.length"
                            :labels="datasetChart.labels"
                            :datasets="datasetChart.datasets"
                            :format-nilai="formatChart"
                        />
                        <p v-else class="pt-16 text-center text-sm text-gray-400">Tidak ada data.</p>
                    </div>
                </div>

                <!-- Kinerja cabang / uker untuk KPI terpilih -->
                <div v-if="scope.bolehLihatRanking.value" class="relative rounded-lg bg-white shadow ring-1 ring-gray-100">
                    <LoadingOverlay :show="memuat.tabel" />

                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 p-4">
                        <h3 class="text-sm font-semibold text-gray-700">
                            Kinerja {{ branch.grouping === 'uker' ? 'Unit Kerja' : 'Cabang' }}
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
                                    <td class="px-4 py-2 text-right tabular-nums">{{ fmtBranchNilai(b.nilai) }}</td>
                                    <template v-if="branch.punya_target">
                                        <td class="px-4 py-2 text-right tabular-nums text-gray-500">{{ fmtBranchNilai(b.target) }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums" :class="deltaCls(b.gap, branch.inverse)">
                                            {{ fmtBranchDelta(b.gap) }}
                                        </td>
                                        <td
                                            class="px-4 py-2 text-right font-semibold tabular-nums"
                                            :class="pctClsArah(b.pencapaian, branch.inverse)"
                                        >
                                            {{ formatPct(b.pencapaian) }}
                                        </td>
                                    </template>
                                </tr>
                                <tr v-if="!barisTerurut.length">
                                    <td :colspan="KOLOM.length" class="px-4 py-6 text-center text-gray-400">Tidak ada data.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
