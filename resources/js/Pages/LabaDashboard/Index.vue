<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import BarChart from '@/Components/BarChart.vue';
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
} from '@/services/labaApi';
import { formatAngka, formatDelta, formatDeltaPct, formatPct } from '@/utils/formatAngka';
import { deltaCls, pctBadgeClsArah, pctClsArah } from '@/utils/pencapaian';
import { useScope } from '@/utils/scope';
import { useTableSort } from '@/utils/useTableSort';

const props = defineProps({
    tahunAwal: { type: Number, required: true },
    bulanAwal: { type: Number, required: true },
    filterAwal: { type: Object, default: () => ({}) },
});

const scope = useScope();

const BULAN = [
    { value: 1, label: 'Januari', singkat: 'Jan' },
    { value: 2, label: 'Februari', singkat: 'Feb' },
    { value: 3, label: 'Maret', singkat: 'Mar' },
    { value: 4, label: 'April', singkat: 'Apr' },
    { value: 5, label: 'Mei', singkat: 'Mei' },
    { value: 6, label: 'Juni', singkat: 'Jun' },
    { value: 7, label: 'Juli', singkat: 'Jul' },
    { value: 8, label: 'Agustus', singkat: 'Ags' },
    { value: 9, label: 'September', singkat: 'Sep' },
    { value: 10, label: 'Oktober', singkat: 'Okt' },
    { value: 11, label: 'November', singkat: 'Nov' },
    { value: 12, label: 'Desember', singkat: 'Des' },
];

const pending = reactive({
    area_id: props.filterAwal.area_id ?? null,
    cabang_id: props.filterAwal.cabang_id ?? null,
    uker_id: props.filterAwal.uker_id ?? null,
    tahun: props.tahunAwal,
    bulan: props.bulanAwal,
});
const applied = reactive({ ...pending });

const dirty = computed(() =>
    Object.keys(applied).some((key) => (pending[key] ?? null) !== (applied[key] ?? null)),
);

const opsi = reactive({ area: [], cabang: [], uker: [], tahun: [] });
const snapshot = ref(null);
const charts = reactive({});
const branch = ref({ grouping: 'cabang', baris: [] });
const drilldown = ref(null);
const memuat = reactive({ kartu: false, chart: false, tabel: false });
const sort = useTableSort('nilai', 'desc');

const periodeLabel = computed(() => {
    const bulan = BULAN.find((item) => item.value === Number(applied.bulan));
    return `${bulan?.label ?? applied.bulan} ${applied.tahun}`;
});

const kartu = computed(() => snapshot.value?.kartu ?? []);
const sections = computed(() => kartu.value.map((card, index) => ({
    card,
    index,
    chart: charts[card.key] ?? null,
})));
const barisTerurut = computed(() => sort.urutkan(branch.value?.baris ?? []));

const WARNA_HERO = [
    'from-[#0756c9] via-[#0c67d5] to-[#064da9]',
    'from-[#1675dd] via-[#2787e6] to-[#1670ce]',
    'from-[#35a6eb] via-[#45b5ee] to-[#2697df]',
    'from-[#0a58c0] via-[#0f6ccc] to-[#084b9f]',
    'from-[#0b4b9f] via-[#0b61bc] to-[#073a7f]',
];

const WARNA_CHART = [
    ['#0a61c8', '#67c3e5'],
    ['#1680e2', '#79d0ee'],
    ['#4aa8e7', '#a8dff3'],
    ['#075dc6', '#64b9e2'],
    ['#0b4f9f', '#62acd7'],
];

function heroTone(index) {
    return WARNA_HERO[index % WARNA_HERO.length];
}

function chartColors(index) {
    return WARNA_CHART[index % WARNA_CHART.length];
}

function deltaRows(card) {
    return (snapshot.value?.label_delta ?? [
        { key: 'mtd', label: 'MTD' },
        { key: 'ytd', label: 'YTD' },
        { key: 'yoy', label: 'YOY' },
    ]).map((row) => ({
        ...row,
        nilai: card?.delta?.[row.key]?.nilai ?? null,
        persen: card?.delta?.[row.key]?.persen ?? null,
    }));
}

function deltaTone(value) {
    if (value === null || value === undefined || Number(value) === 0) return 'text-white/70';
    return Number(value) > 0 ? 'text-emerald-300' : 'text-rose-300';
}

function pctGap(card) {
    const target = Number(card?.target ?? 0);
    const gap = Number(card?.gap ?? 0);
    return target !== 0 ? (gap / Math.abs(target)) * 100 : null;
}

function titikTerbatas(chartData) {
    return (chartData?.titik ?? []).filter((item) => Number(item.bulan) <= Number(applied.bulan));
}

function datasetActual(section) {
    const titik = titikTerbatas(section.chart);
    const [actualColor, targetColor] = chartColors(section.index);

    return {
        labels: titik.map((item) => item.nama),
        datasets: [
            {
                label: `Actual ${applied.tahun}`,
                backgroundColor: actualColor,
                borderColor: actualColor,
                labelColor: actualColor,
                data: titik.map((item) => item.nilai),
            },
            {
                label: 'Target RKA',
                backgroundColor: targetColor,
                borderColor: targetColor,
                labelColor: '#64748b',
                data: titik.map((item) => item.target),
            },
        ],
    };
}

function datasetMtd(section) {
    const titik = titikTerbatas(section.chart);
    const [currentColor] = chartColors(section.index);

    return {
        labels: titik.map((item) => item.nama),
        datasets: [
            {
                label: `MTD ${applied.tahun - 1}`,
                backgroundColor: '#cbd5e1',
                borderColor: '#94a3b8',
                labelColor: '#64748b',
                data: titik.map((item) => item.mtd_tahun_lalu),
            },
            {
                label: `MTD ${applied.tahun}`,
                backgroundColor: currentColor,
                borderColor: currentColor,
                labelColor: currentColor,
                data: titik.map((item) => item.mtd),
            },
        ],
    };
}

function bulanLabel(bulan) {
    return BULAN.find((item) => item.value === Number(bulan))?.label ?? bulan;
}

function bulanSingkat(bulan) {
    return BULAN.find((item) => item.value === Number(bulan))?.singkat?.toUpperCase() ?? String(bulan).toUpperCase();
}

function validNumber(value) {
    return value !== null && value !== undefined && !Number.isNaN(Number(value));
}

function monthlyMetrics(section) {
    return titikTerbatas(section.chart).map((item) => {
        const nilai = validNumber(item.nilai) ? Number(item.nilai) : null;
        const target = validNumber(item.target) ? Number(item.target) : null;
        const tahunLalu = validNumber(item.nilai_tahun_lalu) ? Number(item.nilai_tahun_lalu) : null;
        const yoy = nilai !== null && tahunLalu !== null ? nilai - tahunLalu : null;

        return {
            bulan: item.bulan,
            nama: item.nama,
            pencapaian: nilai !== null && target !== null && target !== 0
                ? (nilai / target) * 100
                : null,
            yoy,
            yoyPersen: yoy !== null && tahunLalu !== 0
                ? (yoy / Math.abs(tahunLalu)) * 100
                : null,
        };
    });
}

function metricGridStyle(section) {
    return {
        gridTemplateColumns: `repeat(${Math.max(1, monthlyMetrics(section).length)}, minmax(92px, 1fr))`,
    };
}

function deltaByKey(card, key) {
    return deltaRows(card).find((item) => item.key === key) ?? { nilai: null, persen: null };
}

function punyaData(dataset) {
    return dataset.datasets.some((item) => item.data.some((value) => value !== null && value !== undefined));
}

async function muatOpsi() {
    const data = await fetchFilterOptions({ area_id: applied.area_id, cabang_id: applied.cabang_id });
    opsi.area = data.area ?? [];
    opsi.cabang = data.cabang ?? [];
    opsi.uker = data.uker ?? [];
    opsi.tahun = data.tahun ?? [];

    if (!opsi.tahun.includes(Number(applied.tahun))) {
        opsi.tahun = [Number(applied.tahun), ...opsi.tahun];
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

async function muatCharts() {
    memuat.chart = true;
    try {
        const cards = snapshot.value?.kartu ?? [];
        const hasil = await Promise.all(cards.map(async (card) => {
            const data = await fetchChart({
                ...applied,
                segmen: card.key === 'total' ? null : card.key,
            });
            return [card.key, data];
        }));

        Object.keys(charts).forEach((key) => delete charts[key]);
        hasil.forEach(([key, data]) => { charts[key] = data; });
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
        branch.value = await fetchBranchPencapaian({
            ...applied,
            cabang_id: drilldown.value ?? applied.cabang_id,
        });
    } finally {
        memuat.tabel = false;
    }
}

async function muatSemua() {
    await Promise.all([muatKartu(), muatTabel()]);
    await muatCharts();
}

async function terapkan() {
    Object.assign(applied, pending);
    drilldown.value = null;
    await muatSemua();
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
    <Head title="Laba" />

    <AuthenticatedLayout>
        <div class="-mx-4 bg-[#f3f7fc] px-4 py-4 sm:-mx-6 sm:px-6">
            <div class="mx-auto max-w-[1600px] space-y-4 pb-10">
                <div class="flex items-center gap-3">
                    <div class="h-8 w-1.5 rounded-full bg-[#0a62ca]" />
                    <h1 class="text-2xl font-black uppercase tracking-tight text-[#0755bd]">Laba</h1>
                </div>

                <!-- Filter -->
                <section class="rounded-[18px] border border-slate-200 bg-white p-3.5 shadow-[0_8px_24px_rgba(15,23,42,0.07)]">
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-[150px_180px_minmax(160px,1fr)_minmax(180px,1fr)_minmax(180px,1fr)_150px]">
                        <label class="space-y-1.5">
                            <span class="block text-[10px] font-extrabold uppercase tracking-[0.12em] text-slate-400">Tahun</span>
                            <select v-model.number="pending.tahun" class="h-11 w-full rounded-xl border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option v-for="tahun in opsi.tahun" :key="tahun" :value="tahun">{{ tahun }}</option>
                            </select>
                        </label>

                        <label class="space-y-1.5">
                            <span class="block text-[10px] font-extrabold uppercase tracking-[0.12em] text-slate-400">Bulan Posisi</span>
                            <select v-model.number="pending.bulan" class="h-11 w-full rounded-xl border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option v-for="bulan in BULAN" :key="bulan.value" :value="bulan.value">{{ bulan.label }}</option>
                            </select>
                        </label>

                        <label v-if="scope.bolehPilihArea.value" class="space-y-1.5">
                            <span class="block text-[10px] font-extrabold uppercase tracking-[0.12em] text-slate-400">Area</span>
                            <select v-model="pending.area_id" class="h-11 w-full rounded-xl border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option :value="null">Semua Area</option>
                                <option v-for="area in opsi.area" :key="area.id" :value="area.id">{{ area.nama }}</option>
                            </select>
                        </label>

                        <label v-if="scope.bolehPilihCabang.value" class="space-y-1.5">
                            <span class="block text-[10px] font-extrabold uppercase tracking-[0.12em] text-slate-400">Cabang</span>
                            <select v-model="pending.cabang_id" class="h-11 w-full rounded-xl border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option :value="null">Semua Cabang</option>
                                <option v-for="cabang in opsi.cabang" :key="cabang.id" :value="cabang.id">{{ cabang.nama }}</option>
                            </select>
                        </label>

                        <label v-if="scope.bolehPilihUker.value" class="space-y-1.5">
                            <span class="block text-[10px] font-extrabold uppercase tracking-[0.12em] text-slate-400">Unit Kerja</span>
                            <select v-model="pending.uker_id" class="h-11 w-full rounded-xl border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option :value="null">Semua Uker</option>
                                <option v-for="uker in opsi.uker" :key="uker.id" :value="uker.id">{{ uker.nama }}</option>
                            </select>
                        </label>

                        <div class="flex items-end">
                            <button
                                type="button"
                                class="inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-[#075bc5] px-5 text-sm font-extrabold text-white shadow-[0_8px_18px_rgba(7,91,197,0.28)] transition hover:bg-[#064eaa] disabled:cursor-not-allowed disabled:opacity-70"
                                :disabled="memuat.kartu || memuat.chart"
                                @click="terapkan"
                            >
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="9" r="5.5"/><path d="m13 13 4 4"/></svg>
                                {{ dirty ? 'Terapkan' : 'Sudah Diterapkan' }}
                            </button>
                        </div>
                    </div>
                </section>

                <!-- Total dan breakdown segmen -->
                <section v-for="section in sections" :key="section.card.key" class="space-y-3">
                    <div class="flex items-center gap-3 pt-1">
                        <span class="h-0.5 w-7 rounded-full bg-[#0a62ca]" />
                        <h2 class="shrink-0 text-sm font-black uppercase tracking-[0.08em] text-[#0755bd]">{{ section.card.judul }}</h2>
                        <span class="h-px flex-1 bg-[#b8cce8]" />
                    </div>

                    <article class="relative overflow-hidden rounded-[18px] bg-gradient-to-r p-5 text-white shadow-[0_12px_28px_rgba(7,82,181,0.22)]" :class="heroTone(section.index)">
                        <LoadingOverlay :show="memuat.kartu" />
                        <span class="pointer-events-none absolute -right-12 -top-20 h-60 w-60 rounded-full bg-white/[0.08]" />
                        <span class="pointer-events-none absolute right-8 top-0 h-44 w-44 rounded-full border border-white/[0.08]" />

                        <div class="relative grid grid-cols-1 gap-5 xl:grid-cols-[minmax(260px,1.55fr)_190px_185px_150px_150px] xl:items-center">
                            <div>
                                <p class="text-[11px] font-extrabold uppercase tracking-[0.12em] text-white/80">{{ section.card.key === 'total' ? 'Total' : section.card.judul }}</p>
                                <p class="mt-1 text-5xl font-black leading-none tracking-tight tabular-nums">{{ formatAngka(section.card.nilai) }}</p>
                                <p class="mt-2 text-xs font-bold text-white/80">Posisi {{ periodeLabel }}</p>
                            </div>

                            <div class="text-center xl:text-right">
                                <span class="inline-flex rounded-lg px-3 py-1.5 text-base font-black tabular-nums" :class="pctBadgeClsArah(section.card.pencapaian, false)">
                                    Penc {{ formatPct(section.card.pencapaian) }}
                                </span>
                                <p class="mt-4 text-lg font-black tabular-nums" :class="deltaTone(section.card.gap)">{{ formatDelta(section.card.gap) }}</p>
                                <p class="mt-0.5 text-[10px] font-black tabular-nums" :class="deltaTone(section.card.gap)">{{ formatDeltaPct(pctGap(section.card)) }}</p>
                            </div>

                            <div class="grid grid-cols-2 divide-x divide-white/[0.18] xl:grid-cols-1 xl:divide-x-0 xl:divide-y">
                                <div class="px-3 pb-2 text-center">
                                    <p class="text-[9px] font-extrabold uppercase tracking-[0.12em] text-white/55">Target {{ bulanLabel(applied.bulan) }}</p>
                                    <p class="mt-1 text-lg font-black tabular-nums">{{ formatAngka(section.card.target) }}</p>
                                </div>
                                <div class="px-3 pt-2 text-center">
                                    <p class="text-[9px] font-extrabold uppercase tracking-[0.12em] text-white/55">Target {{ bulanLabel(section.card.target_berikutnya_bulan) }}</p>
                                    <p class="mt-1 text-lg font-black tabular-nums">{{ formatAngka(section.card.target_berikutnya) }}</p>
                                </div>
                            </div>

                            <div class="border-l-0 border-white/[0.16] text-center xl:border-l xl:pl-5">
                                <p class="text-[9px] font-extrabold uppercase tracking-[0.12em] text-white/55">MTD</p>
                                <p class="mt-1 text-2xl font-black tabular-nums" :class="deltaTone(deltaByKey(section.card, 'mtd').nilai)">{{ formatDelta(deltaByKey(section.card, 'mtd').nilai) }}</p>
                                <p class="mt-1 text-[10px] font-black tabular-nums" :class="deltaTone(deltaByKey(section.card, 'mtd').nilai)">{{ formatDeltaPct(deltaByKey(section.card, 'mtd').persen) }}</p>
                            </div>

                            <div class="border-l-0 border-white/[0.16] text-center xl:border-l xl:pl-5">
                                <p class="text-[9px] font-extrabold uppercase tracking-[0.12em] text-white/55">YOY</p>
                                <p class="mt-1 text-2xl font-black tabular-nums" :class="deltaTone(deltaByKey(section.card, 'yoy').nilai)">{{ formatDelta(deltaByKey(section.card, 'yoy').nilai) }}</p>
                                <p class="mt-1 text-[10px] font-black tabular-nums" :class="deltaTone(deltaByKey(section.card, 'yoy').nilai)">{{ formatDeltaPct(deltaByKey(section.card, 'yoy').persen) }}</p>
                            </div>
                        </div>

                        <div class="relative mt-4 h-1 overflow-hidden rounded-full bg-blue-950/20">
                            <div class="h-full rounded-full bg-cyan-300" :style="{ width: `${Math.min(100, Math.max(0, Number(section.card.pencapaian ?? 0)))}%` }" />
                        </div>
                    </article>

                    <div class="grid grid-cols-1 gap-3">
                        <article class="relative overflow-hidden rounded-[18px] border border-slate-200 bg-white p-4 shadow-[0_8px_24px_rgba(15,23,42,0.06)]">
                            <LoadingOverlay :show="memuat.chart" />

                            <div class="mb-3 flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-[9px] font-extrabold uppercase tracking-[0.14em] text-slate-400">Actual vs Target</p>
                                    <h3 class="text-xs font-black uppercase tracking-wide text-slate-500">{{ section.card.key === 'total' ? 'Total' : section.card.judul }}</h3>
                                </div>
                                <span class="rounded-full bg-blue-50 px-3 py-1 text-[10px] font-bold text-blue-700">{{ applied.tahun }}</span>
                            </div>

                            <div v-if="section.chart && monthlyMetrics(section).length" class="mb-3 overflow-x-auto pb-1">
                                <div class="min-w-[760px] space-y-1">
                                    <div class="grid grid-cols-[62px_minmax(0,1fr)] items-stretch gap-2">
                                        <div class="flex items-center text-[9px] font-black uppercase tracking-[0.12em] text-slate-400">PENC</div>
                                        <div class="grid gap-px" :style="metricGridStyle(section)">
                                            <div
                                                v-for="metric in monthlyMetrics(section)"
                                                :key="`penc-${section.card.key}-${metric.bulan}`"
                                                class="rounded-md border border-slate-200 bg-slate-50 px-2 py-2 text-center text-[10px] font-black tabular-nums"
                                                :class="pctClsArah(metric.pencapaian, false)"
                                            >
                                                {{ formatPct(metric.pencapaian, 1) }}
                                            </div>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-[62px_minmax(0,1fr)] items-stretch gap-2">
                                        <div class="flex items-center text-[9px] font-black uppercase tracking-[0.12em] text-slate-400">YOY</div>
                                        <div class="grid gap-px" :style="metricGridStyle(section)">
                                            <div
                                                v-for="metric in monthlyMetrics(section)"
                                                :key="`yoy-${section.card.key}-${metric.bulan}`"
                                                class="rounded-md border border-slate-200 bg-slate-50 px-2 py-1.5 text-center tabular-nums"
                                                :class="deltaCls(metric.yoy)"
                                            >
                                                <span class="text-[10px] font-black">{{ formatDelta(metric.yoy) }}</span>
                                                <span class="ml-1 text-[8px] font-bold">({{ formatDeltaPct(metric.yoyPersen) }})</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="h-[270px] min-w-0">
                                <BarChart
                                    v-if="section.chart && punyaData(datasetActual(section))"
                                    :labels="datasetActual(section).labels"
                                    :datasets="datasetActual(section).datasets"
                                    variant="laba"
                                    :show-value-labels="true"
                                />
                                <p v-else class="pt-24 text-center text-xs text-slate-400">Tidak ada data.</p>
                            </div>
                        </article>

                        <article class="relative overflow-hidden rounded-[18px] border border-slate-200 bg-white p-4 shadow-[0_8px_24px_rgba(15,23,42,0.06)]">
                            <LoadingOverlay :show="memuat.chart" />

                            <div class="mb-3 flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-[9px] font-extrabold uppercase tracking-[0.14em] text-slate-400">Perbandingan MTD</p>
                                    <h3 class="text-xs font-black uppercase tracking-wide text-slate-500">Delta MTD {{ section.card.key === 'total' ? 'Total Laba' : section.card.judul }}</h3>
                                </div>
                                <span class="rounded-full bg-slate-100 px-3 py-1 text-[10px] font-bold text-slate-600">{{ applied.tahun - 1 }} / {{ applied.tahun }}</span>
                            </div>

                            <div v-if="monthlyMetrics(section).length" class="mb-3 grid grid-cols-[62px_minmax(0,1fr)] items-stretch gap-2">
                                <div class="flex items-center text-[9px] font-black uppercase tracking-[0.12em] text-slate-400">YOY</div>
                                <div class="grid gap-px" :style="metricGridStyle(section)">
                                    <div
                                        v-for="metric in monthlyMetrics(section)"
                                        :key="`mtd-yoy-${section.card.key}-${metric.bulan}`"
                                        class="rounded-md border border-slate-200 bg-slate-50 px-2 py-1.5 text-center tabular-nums"
                                        :class="deltaCls(metric.yoy)"
                                    >
                                        <span class="text-[10px] font-black">{{ formatDelta(metric.yoy) }}</span>
                                        <span class="ml-1 text-[8px] font-bold">({{ formatDeltaPct(metric.yoyPersen) }})</span>
                                    </div>
                                </div>
                            </div>

                            <div class="h-[360px] min-w-0">
                                <BarChart
                                    v-if="section.chart && punyaData(datasetMtd(section))"
                                    :labels="datasetMtd(section).labels"
                                    :datasets="datasetMtd(section).datasets"
                                    variant="laba"
                                    :show-value-labels="true"
                                />
                                <p v-else class="pt-28 text-center text-xs text-slate-400">Tidak ada data.</p>
                            </div>
                        </article>
                    </div>
                </section>

                <!-- Tabel kinerja -->
                <section v-if="scope.bolehLihatRanking.value" class="relative overflow-hidden rounded-[18px] border border-slate-200 bg-white shadow-[0_8px_24px_rgba(15,23,42,0.07)]">
                    <LoadingOverlay :show="memuat.tabel" />

                    <div class="flex flex-col gap-3 border-b border-slate-200 px-4 py-3 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-[10px] font-extrabold uppercase tracking-[0.16em] text-slate-400">Kinerja {{ branch.grouping === 'uker' ? 'Unit Kerja' : 'Branch Office' }}</p>
                            <h3 class="mt-0.5 text-sm font-black uppercase tracking-wide text-slate-700">Posisi {{ periodeLabel }}</h3>
                        </div>

                        <label class="min-w-[230px] space-y-1">
                            <span class="block text-[9px] font-extrabold uppercase tracking-[0.12em] text-slate-400">Drill-down Cabang</span>
                            <select v-model="drilldown" class="h-10 w-full rounded-xl border-slate-200 bg-white px-3 text-xs font-semibold text-slate-700 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option :value="null">Semua Cabang</option>
                                <option v-for="cabang in opsi.cabang" :key="cabang.id" :value="cabang.id">{{ cabang.nama }}</option>
                            </select>
                        </label>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-[1180px] w-full border-collapse text-[11px]">
                            <thead class="bg-[#edf5ff] text-[#0755bd]">
                                <tr>
                                    <th class="w-12 px-3 py-2.5 text-center font-black">#</th>
                                    <th class="min-w-[270px] cursor-pointer px-3 py-2.5 text-left font-black uppercase tracking-wide" @click="sort.urutkanKolom('nama')">Nama {{ branch.grouping === 'uker' ? 'Unit Kerja' : 'Cabang' }} <SortArrow :arah="sort.arahUntuk('nama')" /></th>
                                    <th class="min-w-[130px] cursor-pointer px-3 py-2.5 text-right font-black uppercase tracking-wide" @click="sort.urutkanKolom('posisi_bulan_lalu')">{{ bulanSingkat(applied.bulan) }} {{ applied.tahun - 1 }} <SortArrow :arah="sort.arahUntuk('posisi_bulan_lalu')" /></th>
                                    <th class="min-w-[130px] cursor-pointer px-3 py-2.5 text-right font-black uppercase tracking-wide" @click="sort.urutkanKolom('posisi_desember_lalu')">DES {{ applied.tahun - 1 }} <SortArrow :arah="sort.arahUntuk('posisi_desember_lalu')" /></th>
                                    <th class="min-w-[130px] cursor-pointer px-3 py-2.5 text-right font-black uppercase tracking-wide" @click="sort.urutkanKolom('nilai')">{{ bulanSingkat(applied.bulan) }} {{ applied.tahun }} <SortArrow :arah="sort.arahUntuk('nilai')" /></th>
                                    <th class="min-w-[130px] cursor-pointer px-3 py-2.5 text-right font-black uppercase tracking-wide" @click="sort.urutkanKolom('target')">RKA <SortArrow :arah="sort.arahUntuk('target')" /></th>
                                    <th class="min-w-[105px] cursor-pointer px-3 py-2.5 text-right font-black uppercase tracking-wide" @click="sort.urutkanKolom('pencapaian')">PENC % <SortArrow :arah="sort.arahUntuk('pencapaian')" /></th>
                                    <th class="min-w-[125px] cursor-pointer px-3 py-2.5 text-right font-black uppercase tracking-wide" @click="sort.urutkanKolom('gap')">GAP <SortArrow :arah="sort.arahUntuk('gap')" /></th>
                                    <th class="min-w-[120px] cursor-pointer px-3 py-2.5 text-right font-black uppercase tracking-wide" @click="sort.urutkanKolom('mtd')">MTD <SortArrow :arah="sort.arahUntuk('mtd')" /></th>
                                    <th class="min-w-[120px] cursor-pointer px-3 py-2.5 text-right font-black uppercase tracking-wide" @click="sort.urutkanKolom('yoy')">YOY <SortArrow :arah="sort.arahUntuk('yoy')" /></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="(row, index) in barisTerurut" :key="row.id" class="transition hover:bg-blue-50/50">
                                    <td class="px-3 py-2.5 text-center font-semibold text-slate-400">{{ index + 1 }}</td>
                                    <td class="px-3 py-2.5">
                                        <p class="font-extrabold text-slate-700">{{ row.nama }}</p>
                                        <p class="mt-0.5 text-[9px] font-semibold text-blue-500">Area Head: {{ row.area_nama ?? '–' }}</p>
                                    </td>
                                    <td class="px-3 py-2.5 text-right font-semibold tabular-nums text-slate-500">{{ formatAngka(row.posisi_bulan_lalu) }}</td>
                                    <td class="px-3 py-2.5 text-right font-semibold tabular-nums text-slate-500">{{ formatAngka(row.posisi_desember_lalu) }}</td>
                                    <td class="px-3 py-2.5 text-right font-black tabular-nums text-slate-800">{{ formatAngka(row.nilai) }}</td>
                                    <td class="px-3 py-2.5 text-right font-semibold tabular-nums text-slate-500">{{ formatAngka(row.target) }}</td>
                                    <td class="px-3 py-2.5 text-right"><span class="rounded-lg px-2 py-1 font-black tabular-nums" :class="pctBadgeClsArah(row.pencapaian, false)">{{ formatPct(row.pencapaian) }}</span></td>
                                    <td class="px-3 py-2.5 text-right font-black tabular-nums" :class="deltaCls(row.gap)">{{ formatDelta(row.gap) }}</td>
                                    <td class="px-3 py-2.5 text-right font-black tabular-nums" :class="deltaCls(row.mtd)">{{ formatDelta(row.mtd) }}</td>
                                    <td class="px-3 py-2.5 text-right font-black tabular-nums" :class="deltaCls(row.yoy)">{{ formatDelta(row.yoy) }}</td>
                                </tr>
                                <tr v-if="!barisTerurut.length">
                                    <td colspan="10" class="px-4 py-10 text-center text-slate-400">Tidak ada data pada periode ini.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
