<script setup>
import ApplyButton from '@/Components/ApplyButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
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
    fetchChartSegmen,
    fetchFilterOptions,
    fetchProduk,
    fetchSnapshot,
    fetchUker,
} from '@/services/pinjamanApi';
import { formatAngka, formatDelta, formatPct } from '@/utils/formatAngka';
import { deltaCls, pctClsArah } from '@/utils/pencapaian';
import { useScope } from '@/utils/scope';
import { useTableSort } from '@/utils/useTableSort';
import { warnaBulan } from '@/utils/chartColors';

const props = defineProps({
    tanggalAwal: { type: String, required: true },
    tabAwal: { type: String, default: 'total' },
    filterAwal: { type: Object, default: () => ({}) },
});

const scope = useScope();

const TAB = [
    { key: 'total', label: 'Total', keterangan: 'OS = Lancar + SML + NPL' },
    { key: 'sml', label: 'SML', keterangan: 'Special Mention Loan — makin kecil makin baik' },
    { key: 'npl', label: 'NPL', keterangan: 'Non-Performing Loan — makin kecil makin baik' },
];

/** Tab ikut pola applied: berganti tab langsung memuat ulang data. */
const tab = ref(props.tabAwal);

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
const snapshot = ref(null);
const chart = ref(null);
const chartSegmen = ref(null);
const produk = ref({ baris: [] });
const branch = ref({ grouping: 'cabang', baris: [] });

const memuat = reactive({ kartu: false, chart: false, segmen: false, produk: false, tabel: false });
const drilldown = ref(null);

const sort = useTableSort('nilai', 'desc');

/** Tab SML/NPL membalik arah pewarnaan pencapaian. */
const inverse = computed(() => tab.value !== 'total');

const KOLOM = [
    { key: 'nama', label: 'Nama', kelas: 'text-left' },
    { key: 'nilai', label: 'Baki Debet', kelas: 'text-right' },
    { key: 'target', label: 'RKA', kelas: 'text-right' },
    { key: 'gap', label: 'Gap', kelas: 'text-right' },
    { key: 'pencapaian', label: 'Pencapaian', kelas: 'text-right' },
];

const barisTerurut = computed(() => sort.urutkan(branch.value.baris ?? []));

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

/** Palet segmen: dipinjam dari palet bulan agar konsisten satu sistem warna. */
const WARNA_SEGMEN = [1, 8, 5, 10, 3, 6];

const datasetSegmen = computed(() => {
    const seri = chartSegmen.value?.seri ?? [];
    const labels = (chartSegmen.value?.tanggal ?? []).map((t) => t.slice(8, 10));

    return {
        labels,
        datasets: seri.map((s, i) => ({
            label: s.segmen,
            borderColor: warnaBulan(WARNA_SEGMEN[i % WARNA_SEGMEN.length]),
            backgroundColor: warnaBulan(WARNA_SEGMEN[i % WARNA_SEGMEN.length]),
            data: s.titik.map((t) => t.nilai),
            spanGaps: false,
        })),
    };
});

const filterAktif = () => ({ ...applied, tab: tab.value });

async function muatOpsi() {
    const data = await fetchFilterOptions({ area_id: applied.area_id, cabang_id: applied.cabang_id });
    opsi.area = data.area ?? [];
    opsi.cabang = data.cabang ?? [];
    opsi.uker = data.uker ?? [];
}

async function muatKartu() {
    memuat.kartu = true;
    try {
        snapshot.value = await fetchSnapshot(filterAktif());
    } finally {
        memuat.kartu = false;
    }
}

async function muatChart() {
    memuat.chart = true;
    try {
        chart.value = await fetchChart(filterAktif());
    } finally {
        memuat.chart = false;
    }
}

async function muatChartSegmen() {
    memuat.segmen = true;
    try {
        chartSegmen.value = await fetchChartSegmen(filterAktif());
    } finally {
        memuat.segmen = false;
    }
}

async function muatProduk() {
    memuat.produk = true;
    try {
        produk.value = await fetchProduk({ ...applied });
    } finally {
        memuat.produk = false;
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
            ...filterAktif(),
            cabang_id: drilldown.value ?? applied.cabang_id,
        });
    } finally {
        memuat.tabel = false;
    }
}

function muatSemua() {
    return Promise.all([muatKartu(), muatChart(), muatChartSegmen(), muatProduk(), muatTabel()]);
}

function terapkan() {
    Object.assign(applied, pending);
    drilldown.value = null;
    muatSemua();
}

function gantiTab(key) {
    if (tab.value === key) return;
    tab.value = key;
    // Rincian per produk tidak bergantung tab, jadi tidak perlu dimuat ulang.
    Promise.all([muatKartu(), muatChart(), muatChartSegmen(), muatTabel()]);
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
    <Head title="Dashboard Pinjaman (Kredit)" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Dashboard Pinjaman (Kredit)
            </h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <!-- Tab Total / SML / NPL -->
                <div class="flex flex-wrap items-center gap-2 rounded-lg bg-white p-2 shadow ring-1 ring-gray-100">
                    <button
                        v-for="t in TAB"
                        :key="t.key"
                        type="button"
                        class="rounded-md px-4 py-2 text-sm font-semibold transition"
                        :class="
                            tab === t.key
                                ? 'bg-indigo-600 text-white'
                                : 'text-gray-600 hover:bg-gray-100'
                        "
                        :title="t.keterangan"
                        @click="gantiTab(t.key)"
                    >
                        {{ t.label }}
                    </button>

                    <span class="ms-2 text-xs text-gray-400">
                        {{ TAB.find((t) => t.key === tab)?.keterangan }}
                    </span>
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

                <!-- Kartu KPI -->
                <div class="relative">
                    <LoadingOverlay :show="memuat.kartu" />
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
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
                            :inverse="snapshot?.inverse ?? false"
                        />
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <!-- Tren harian per bulan -->
                    <div class="relative rounded-lg bg-white p-4 shadow ring-1 ring-gray-100">
                        <LoadingOverlay :show="memuat.chart" />
                        <h3 class="text-sm font-semibold text-gray-700">
                            Tren Harian {{ chart?.tahun ?? '' }}
                        </h3>
                        <div class="mt-3 h-64">
                            <LineChart
                                v-if="datasetChart.datasets.length"
                                :labels="datasetChart.labels"
                                :datasets="datasetChart.datasets"
                            />
                            <p v-else class="pt-16 text-center text-sm text-gray-400">Tidak ada data.</p>
                        </div>
                    </div>

                    <!-- Tren per segmen (endpoint khusus Pinjaman) -->
                    <div class="relative rounded-lg bg-white p-4 shadow ring-1 ring-gray-100">
                        <LoadingOverlay :show="memuat.segmen" />
                        <h3 class="text-sm font-semibold text-gray-700">Tren per Segmen (bulan berjalan)</h3>
                        <div class="mt-3 h-64">
                            <LineChart
                                v-if="datasetSegmen.datasets.length"
                                :labels="datasetSegmen.labels"
                                :datasets="datasetSegmen.datasets"
                            />
                            <p v-else class="pt-16 text-center text-sm text-gray-400">Tidak ada data.</p>
                        </div>
                    </div>
                </div>

                <!-- Rincian per produk (endpoint khusus Pinjaman) -->
                <div class="relative rounded-lg bg-white shadow ring-1 ring-gray-100">
                    <LoadingOverlay :show="memuat.produk" />
                    <div class="border-b border-gray-100 p-4">
                        <h3 class="text-sm font-semibold text-gray-700">Rincian Per Produk (Segmen)</h3>
                        <p class="mt-1 text-xs text-gray-500">
                            Komposisi kualitas ditampilkan lengkap, tidak mengikuti tab aktif.
                        </p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-100 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500">Segmen</th>
                                    <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500">Lancar</th>
                                    <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500">SML</th>
                                    <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500">NPL</th>
                                    <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500">OS</th>
                                    <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500">%SML</th>
                                    <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500">%NPL</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <tr v-for="b in produk.baris" :key="b.segmen" class="hover:bg-gray-50">
                                    <td class="px-4 py-2 text-gray-800">{{ b.segmen }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ formatAngka(b.lancar) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ formatAngka(b.sml) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ formatAngka(b.npl) }}</td>
                                    <td class="px-4 py-2 text-right font-semibold tabular-nums">{{ formatAngka(b.os) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-gray-500">{{ formatPct(b.pct_sml) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-gray-500">{{ formatPct(b.pct_npl) }}</td>
                                </tr>
                                <tr v-if="!produk.baris.length">
                                    <td colspan="7" class="px-4 py-6 text-center text-gray-400">Tidak ada data.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Kinerja cabang / uker -->
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
                                    <td class="px-4 py-2 text-right tabular-nums">{{ formatAngka(b.nilai) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-gray-500">{{ formatAngka(b.target) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums" :class="deltaCls(b.gap, inverse)">
                                        {{ formatDelta(b.gap) }}
                                    </td>
                                    <td
                                        class="px-4 py-2 text-right font-semibold tabular-nums"
                                        :class="pctClsArah(b.pencapaian, inverse)"
                                    >
                                        {{ formatPct(b.pencapaian) }}
                                    </td>
                                </tr>
                                <tr v-if="!barisTerurut.length">
                                    <td colspan="5" class="px-4 py-6 text-center text-gray-400">Tidak ada data.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p v-if="branch.grouping === 'cabang'" class="border-t border-gray-100 px-4 py-2 text-xs text-gray-400">
                        Segmen Menengah dikelola level Region dan tidak tampil sebagai baris cabang,
                        sehingga jumlah tabel ini lebih kecil dari kartu Total.
                    </p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
