<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import LineChart from '@/Components/LineChart.vue';
import LoadingOverlay from '@/Components/LoadingOverlay.vue';
import SortArrow from '@/Components/SortArrow.vue';
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import {
    fetchBranchPencapaian,
    fetchCabang,
    fetchChart,
    fetchFilterOptions,
    fetchSnapshot,
    fetchUker,
} from '@/services/simpananApi';
import { formatAngka, formatDelta, formatDeltaPct, formatPct } from '@/utils/formatAngka';
import { deltaCls, pctBadgeClsArah, pctCls } from '@/utils/pencapaian';
import { useScope } from '@/utils/scope';
import { useTableSort } from '@/utils/useTableSort';
import { warnaBulan } from '@/utils/chartColors';

const props = defineProps({
    tanggalAwal: { type: String, required: true },
    filterAwal: { type: Object, default: () => ({}) },
});

const scope = useScope();

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
const branch = ref({ grouping: 'cabang', baris: [] });
const memuat = reactive({ kartu: false, chart: false, tabel: false });
const drilldown = ref(null);

const sort = useTableSort('nilai', 'desc');

// --- Snapshot per kartu --------------------------------------------------
const kartuByKey = computed(() => {
    const m = {};
    (snapshot.value?.kartu ?? []).forEach((k) => (m[k.key] = k));
    return m;
});
const total = computed(() => kartuByKey.value.total ?? null);

const DELTA = [
    { key: 'dtd', label: 'D-1' },
    { key: 'mtd', label: 'MTD' },
    { key: 'ytd', label: 'YTD' },
    { key: 'yoy', label: 'YOY' },
];
const deltaList = (k) =>
    DELTA.map((d) => ({
        ...d,
        nilai: k?.delta?.[d.key]?.nilai ?? null,
        persen: k?.delta?.[d.key]?.persen ?? null,
    }));

// Baris tabel rincian produk (Tabungan/Giro/Deposito/CASA).
const PRODUK = ['tabungan', 'giro', 'deposito', 'casa'];
const barisProduk = computed(() => PRODUK.map((key) => kartuByKey.value[key]).filter(Boolean));

// --- Chart helper --------------------------------------------------------
function buildDataset(seri) {
    const list = seri ?? [];
    if (!list.length) return null;
    const hariMaks = Math.max(1, ...list.flatMap((s) => s.titik.map((t) => t.hari)));
    const labels = Array.from({ length: hariMaks }, (_, i) => String(i + 1));

    return {
        labels,
        datasets: list.map((s) => ({
            label: s.nama,
            borderColor: warnaBulan(s.bulan),
            backgroundColor: warnaBulan(s.bulan),
            data: labels.map((h) => s.titik.find((t) => t.hari === Number(h))?.nilai ?? null),
            spanGaps: false,
        })),
    };
}
const chartTotal = computed(() => buildDataset(chart.value?.seri));
const chartTabungan = computed(() => buildDataset(chart.value?.seri_produk?.tabungan));
const chartGiro = computed(() => buildDataset(chart.value?.seri_produk?.giro));
const chartCasa = computed(() => buildDataset(chart.value?.seri_produk?.casa));

// --- Tabel cabang --------------------------------------------------------
const KOLOM = [
    { key: 'nama', label: 'Nama Cabang', kelas: 'text-left' },
    { key: 'nilai', label: 'Actual', kelas: 'text-right' },
    { key: 'target', label: 'Target', kelas: 'text-right' },
    { key: 'pencapaian', label: 'Penc %', kelas: 'text-right' },
    { key: 'gap', label: 'Gap', kelas: 'text-right' },
];
const barisTerurut = computed(() => sort.urutkan(branch.value.baris ?? []));

// --- Format --------------------------------------------------------------
const BULAN_ID = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
function tanggalPanjang(iso) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso ?? '');
    return m ? `${Number(m[3])} ${BULAN_ID[Number(m[2]) - 1]} ${m[1]}` : iso;
}

// --- Fetch ---------------------------------------------------------------
async function muatOpsi() {
    const data = await fetchFilterOptions({ area_id: applied.area_id, cabang_id: applied.cabang_id });
    opsi.area = data.area ?? [];
    opsi.cabang = data.cabang ?? [];
    opsi.uker = data.uker ?? [];
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
async function muatTabel() {
    if (!scope.bolehLihatRanking.value) {
        branch.value = { grouping: 'cabang', baris: [] };
        return;
    }
    memuat.tabel = true;
    try {
        branch.value = await fetchBranchPencapaian({ ...applied, cabang_id: drilldown.value ?? applied.cabang_id });
    } finally {
        memuat.tabel = false;
    }
}
const muatSemua = () => Promise.all([muatKartu(), muatChart(), muatTabel()]);

function terapkan() {
    Object.assign(applied, pending);
    drilldown.value = null;
    muatSemua();
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
    <Head title="Dana Pihak Ketiga" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-7xl space-y-5">
            <h1 class="text-2xl font-extrabold uppercase tracking-tight text-brand-700">Dana Pihak Ketiga</h1>

            <!-- Filter bar -->
            <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-100">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <label class="block">
                        <span class="text-[11px] font-semibold uppercase text-slate-400">Tanggal</span>
                        <input v-model="pending.tanggal" type="date" class="mt-1 block w-full rounded-lg border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500" />
                    </label>
                    <label v-if="scope.bolehPilihArea.value" class="block">
                        <span class="text-[11px] font-semibold uppercase text-slate-400">Area</span>
                        <select v-model="pending.area_id" class="mt-1 block w-full rounded-lg border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                            <option :value="null">Semua Area</option>
                            <option v-for="a in opsi.area" :key="a.id" :value="a.id">{{ a.nama }}</option>
                        </select>
                    </label>
                    <label v-if="scope.bolehPilihCabang.value" class="block">
                        <span class="text-[11px] font-semibold uppercase text-slate-400">Cabang</span>
                        <select v-model="pending.cabang_id" class="mt-1 block w-full rounded-lg border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                            <option :value="null">Semua Cabang</option>
                            <option v-for="c in opsi.cabang" :key="c.id" :value="c.id">{{ c.nama }}</option>
                        </select>
                    </label>
                    <label v-if="scope.bolehPilihUker.value" class="block">
                        <span class="text-[11px] font-semibold uppercase text-slate-400">Unit Kerja</span>
                        <select v-model="pending.uker_id" class="mt-1 block w-full rounded-lg border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                            <option :value="null">Semua Uker</option>
                            <option v-for="u in opsi.uker" :key="u.id" :value="u.id">{{ u.nama }}</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-[11px] font-semibold uppercase text-slate-400">Segmentasi</span>
                        <select class="mt-1 block w-full rounded-lg border-slate-200 text-sm text-slate-500" disabled>
                            <option>Semua Segmen</option>
                        </select>
                    </label>
                    <div class="flex items-end">
                        <button
                            type="button"
                            class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700"
                            :class="{ 'ring-2 ring-amber-300': dirty }"
                            @click="terapkan"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 103.9 9.4l3.3 3.3a1 1 0 001.4-1.4l-3.3-3.3A5.5 5.5 0 009 3.5zM5.5 9a3.5 3.5 0 117 0 3.5 3.5 0 01-7 0z" clip-rule="evenodd" /></svg>
                            Terapkan
                        </button>
                    </div>
                </div>
            </div>

            <!-- Hero + tren total -->
            <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
                <!-- Hero -->
                <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-brand-700 via-brand-600 to-brand-500 p-5 text-white shadow-lg">
                    <LoadingOverlay :show="memuat.kartu" />
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-white/75">Total Dana Pihak Ketiga</p>
                            <p class="mt-1 text-4xl font-extrabold tabular-nums">{{ formatAngka(total?.nilai) }}</p>
                            <p class="mt-1 text-xs text-white/70">Posisi {{ tanggalPanjang(snapshot?.tanggal) }}</p>
                        </div>
                        <div class="shrink-0 rounded-xl bg-white/15 p-3 text-right">
                            <p class="text-[10px] font-semibold uppercase text-white/70">Target RKA</p>
                            <p class="text-lg font-bold tabular-nums">{{ formatAngka(total?.target) }}</p>
                            <span class="mt-1 inline-block rounded-md bg-white/90 px-2 py-0.5 text-xs font-bold text-brand-700">
                                Penc {{ formatPct(total?.pencapaian) }}
                            </span>
                            <p class="mt-1 text-[11px] text-white/80">Gap {{ formatDelta(total?.gap) }}</p>
                        </div>
                    </div>

                    <div class="mt-5 grid grid-cols-4 gap-2">
                        <div v-for="d in deltaList(total)" :key="d.key" class="rounded-xl bg-white/10 p-2 text-center">
                            <p class="text-[10px] font-semibold uppercase text-white/60">{{ d.label }}</p>
                            <p class="mt-0.5 text-xs font-bold tabular-nums">{{ formatDelta(d.nilai) }}</p>
                            <p class="text-[10px] tabular-nums" :class="d.nilai >= 0 ? 'text-emerald-300' : 'text-rose-300'">{{ formatDeltaPct(d.persen) }}</p>
                        </div>
                    </div>
                </div>

                <!-- Tren total -->
                <div class="relative rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-100">
                    <LoadingOverlay :show="memuat.chart" />
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-bold text-slate-700">Total Trend</h3>
                        <span class="text-xs text-slate-400">Tahun {{ chart?.tahun ?? '' }}</span>
                    </div>
                    <div class="mt-2 h-56">
                        <LineChart v-if="chartTotal" :labels="chartTotal.labels" :datasets="chartTotal.datasets" />
                        <p v-else class="pt-16 text-center text-sm text-slate-400">Tidak ada data.</p>
                    </div>
                </div>
            </div>

            <!-- Rincian produk + chart tabungan/giro -->
            <div class="grid grid-cols-1 gap-5 lg:grid-cols-5">
                <!-- Tabel rincian -->
                <div class="relative rounded-2xl bg-white shadow-sm ring-1 ring-slate-100 lg:col-span-3">
                    <LoadingOverlay :show="memuat.kartu" />
                    <div class="border-b border-slate-100 p-4">
                        <h3 class="text-sm font-bold text-slate-700">Rincian Performa Produk</h3>
                        <p class="text-xs text-slate-400">Posisi {{ tanggalPanjang(snapshot?.tanggal) }}</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-100 text-sm">
                            <thead class="bg-slate-50">
                                <tr class="text-[11px] uppercase text-slate-400">
                                    <th class="px-3 py-2 text-left font-semibold">Produk</th>
                                    <th class="px-3 py-2 text-right font-semibold">Actual</th>
                                    <th class="px-3 py-2 text-right font-semibold">Target</th>
                                    <th class="px-3 py-2 text-right font-semibold">Penc %</th>
                                    <th v-for="d in DELTA" :key="d.key" class="px-3 py-2 text-right font-semibold">{{ d.label }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <tr v-for="k in barisProduk" :key="k.key" class="hover:bg-slate-50">
                                    <td class="px-3 py-2.5 font-semibold text-slate-800">{{ k.judul }}</td>
                                    <td class="px-3 py-2.5 text-right font-bold tabular-nums text-slate-900">{{ formatAngka(k.nilai) }}</td>
                                    <td class="px-3 py-2.5 text-right tabular-nums text-slate-400">{{ formatAngka(k.target) }}</td>
                                    <td class="px-3 py-2.5 text-right">
                                        <span class="rounded-md px-1.5 py-0.5 text-xs font-bold tabular-nums" :class="pctBadgeClsArah(k.pencapaian, false)">{{ formatPct(k.pencapaian) }}</span>
                                    </td>
                                    <td v-for="d in deltaList(k)" :key="d.key" class="px-3 py-2.5 text-right tabular-nums">
                                        <span class="block text-xs font-semibold" :class="deltaCls(d.nilai)">{{ formatDelta(d.nilai) }}</span>
                                        <span class="block text-[10px]" :class="deltaCls(d.nilai)">{{ formatDeltaPct(d.persen) }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Chart tabungan + giro -->
                <div class="space-y-5 lg:col-span-2">
                    <div class="relative rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-100">
                        <LoadingOverlay :show="memuat.chart" />
                        <h3 class="text-xs font-bold uppercase text-slate-500">Tabungan</h3>
                        <div class="mt-2 h-40">
                            <LineChart v-if="chartTabungan" :labels="chartTabungan.labels" :datasets="chartTabungan.datasets" />
                            <p v-else class="pt-12 text-center text-xs text-slate-400">Tidak ada data.</p>
                        </div>
                    </div>
                    <div class="relative rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-100">
                        <LoadingOverlay :show="memuat.chart" />
                        <h3 class="text-xs font-bold uppercase text-slate-500">Giro</h3>
                        <div class="mt-2 h-40">
                            <LineChart v-if="chartGiro" :labels="chartGiro.labels" :datasets="chartGiro.datasets" />
                            <p v-else class="pt-12 text-center text-xs text-slate-400">Tidak ada data.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- CASA full width -->
            <div class="relative rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-100">
                <LoadingOverlay :show="memuat.chart" />
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-bold text-slate-700">CASA</h3>
                    <span class="text-xs text-slate-400">Tabungan + Giro</span>
                </div>
                <div class="mt-2 h-56">
                    <LineChart v-if="chartCasa" :labels="chartCasa.labels" :datasets="chartCasa.datasets" />
                    <p v-else class="pt-16 text-center text-sm text-slate-400">Tidak ada data.</p>
                </div>
            </div>

            <!-- Kinerja cabang -->
            <div v-if="scope.bolehLihatRanking.value" class="relative rounded-2xl bg-white shadow-sm ring-1 ring-slate-100">
                <LoadingOverlay :show="memuat.tabel" />
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4">
                    <div>
                        <h3 class="text-sm font-bold text-slate-700">
                            Kinerja Dana Pihak Ketiga {{ branch.grouping === 'uker' ? 'Unit Kerja' : 'Cabang' }}
                        </h3>
                        <p class="text-xs text-slate-400">Posisi {{ tanggalPanjang(branch?.tanggal) }}</p>
                    </div>
                    <label class="flex items-center gap-2 text-xs text-slate-500">
                        Drill-down BO
                        <select v-model="drilldown" class="rounded-lg border-slate-200 text-sm">
                            <option :value="null">Semua BO</option>
                            <option v-for="c in opsi.cabang" :key="c.id" :value="c.id">{{ c.nama }}</option>
                        </select>
                    </label>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-100 text-sm">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-3 py-2 text-left text-[11px] font-semibold uppercase text-slate-400">#</th>
                                <th
                                    v-for="k in KOLOM"
                                    :key="k.key"
                                    class="cursor-pointer select-none px-3 py-2 text-[11px] font-semibold uppercase text-slate-400"
                                    :class="k.kelas"
                                    @click="sort.urutkanKolom(k.key)"
                                >
                                    {{ k.label }} <SortArrow :arah="sort.arahUntuk(k.key)" />
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <tr v-for="(b, i) in barisTerurut" :key="b.id" class="hover:bg-slate-50">
                                <td class="px-3 py-2.5 text-slate-400">{{ i + 1 }}</td>
                                <td class="px-3 py-2.5 font-semibold text-slate-800">{{ b.nama }}</td>
                                <td class="px-3 py-2.5 text-right font-bold tabular-nums text-slate-900">{{ formatAngka(b.nilai) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-slate-400">{{ formatAngka(b.target) }}</td>
                                <td class="px-3 py-2.5 text-right">
                                    <span class="rounded-md px-1.5 py-0.5 text-xs font-bold tabular-nums" :class="pctBadgeClsArah(b.pencapaian, false)">{{ formatPct(b.pencapaian) }}</span>
                                </td>
                                <td class="px-3 py-2.5 text-right font-semibold tabular-nums" :class="deltaCls(b.gap)">{{ formatDelta(b.gap) }}</td>
                            </tr>
                            <tr v-if="!barisTerurut.length">
                                <td colspan="6" class="px-3 py-6 text-center text-slate-400">Tidak ada data.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
