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
import { deltaCls, pctBadgeClsArah } from '@/utils/pencapaian';
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
    segmentasi: props.filterAwal.segmentasi ?? null,
    tanggal: props.tanggalAwal,
});
const applied = reactive({ ...pending });

const dirty = computed(() =>
    Object.keys(applied).some((k) => (pending[k] ?? null) !== (applied[k] ?? null)),
);

const opsi = reactive({ area: [], cabang: [], uker: [], segmentasi: [], produk: [], periode: [] });
const snapshot = ref(null);
const chart = ref(null);
const branch = ref({ grouping: 'cabang', baris: [] });
const memuat = reactive({ kartu: false, chart: false, tabel: false });
const rankingFilter = reactive({
    cabang_id: null,
    segmentasi: null,
    produk: null,
});

const sort = useTableSort('pencapaian', 'desc');

// --- Snapshot per kartu --------------------------------------------------
const kartuByKey = computed(() => {
    const m = {};
    (snapshot.value?.kartu ?? []).forEach((k) => (m[k.key] = k));
    return m;
});
const total = computed(() => kartuByKey.value.total ?? null);
const tabungan = computed(() => kartuByKey.value.tabungan ?? null);
const giro = computed(() => kartuByKey.value.giro ?? null);
const casa = computed(() => kartuByKey.value.casa ?? null);

const pencapaianProgress = computed(() => {
    const nilai = Number(total.value?.pencapaian ?? 0);
    return Math.min(100, Math.max(0, Number.isFinite(nilai) ? nilai : 0));
});

const rasioCasa = computed(() => {
    const nilaiCasa = Number(casa.value?.nilai ?? 0);
    const nilaiTotal = Number(total.value?.nilai ?? 0);

    return nilaiTotal > 0 ? (nilaiCasa / nilaiTotal) * 100 : null;
});

const DELTA = [
    { key: 'dtd', label: 'DTD' },
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

// Daftar segmentasi berasal dari data aktual/RKA dan dipakai oleh filter serta tabel.
const daftarSegmentasi = computed(() => {
    const dariOpsi = Array.isArray(opsi.segmentasi) ? opsi.segmentasi : [];
    return dariOpsi.length ? dariOpsi : ['Wholesale', 'Ritel', 'Mikro'];
});
const barisSegmentasi = computed(() => {
    const data = Array.isArray(snapshot.value?.segmentasi) ? snapshot.value.segmentasi : [];
    const map = Object.fromEntries(data.map((item) => [String(item.nama ?? item.key).toLowerCase(), item]));

    const daftar = applied.segmentasi ? [applied.segmentasi] : daftarSegmentasi.value;

    return daftar.map((nama) => ({
        nama,
        ...(map[String(nama).toLowerCase()] ?? {}),
    }));
});

// --- Chart helper --------------------------------------------------------
function periodeTrend() {
    const tanggal = new Date(`${applied.tanggal}T00:00:00`);
    const tahun = tanggal.getFullYear();
    const bulan = tanggal.getMonth() + 1;
    const hasil = [{ tahun: tahun - 1, bulan: 12 }];

    // Lima bulan berjalan: bulan posisi + empat bulan sebelumnya.
    for (let mundur = 4; mundur >= 0; mundur -= 1) {
        const periode = new Date(tahun, bulan - 1 - mundur, 1);
        const item = { tahun: periode.getFullYear(), bulan: periode.getMonth() + 1 };
        if (!hasil.some((p) => p.tahun === item.tahun && p.bulan === item.bulan)) hasil.push(item);
    }

    return hasil;
}

function buildDataset(seri) {
    const sumber = seri ?? [];
    const periode = periodeTrend();
    const labels = Array.from({ length: 31 }, (_, i) => String(i + 1));
    const warnaTrend = ['#7c8ea6', '#5f95ff', '#31c48d', '#f0ad32', '#ff6b6b', '#14b8d4'];
    const namaBulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Ags', 'Sep', 'Okt', 'Nov', 'Des'];

    return {
        labels,
        datasets: periode.map((p, idx) => {
            const dataSeri = sumber.find((s) => Number(s.tahun) === p.tahun && Number(s.bulan) === p.bulan);
            const terakhir = idx === periode.length - 1;
            const warna = warnaTrend[idx] ?? warnaBulan(p.bulan);

            return {
                label: `${namaBulan[p.bulan - 1]} ${p.tahun}`,
                borderColor: warna,
                backgroundColor: warna,
                data: labels.map((h) => dataSeri?.titik?.find((t) => t.hari === Number(h))?.nilai ?? null),
                spanGaps: false,
                borderDash: terakhir ? [] : [5, 5],
                borderWidth: terakhir ? 3 : 2,
                pointRadius: 0,
                pointHoverRadius: terakhir ? 4 : 3,
                fill: false,
            };
        }),
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

function nilaiDeltaCabang(baris, key) {
    const data = baris?.[key];
    if (data && typeof data === 'object') return data.nilai ?? null;
    return data ?? null;
}

function persenDeltaCabang(baris, key) {
    const data = baris?.[key];
    return data && typeof data === 'object' ? data.persen ?? null : null;
}

// --- Format & label filter ----------------------------------------------
const BULAN_ID = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
function tanggalPanjang(iso) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso ?? '');
    return m ? `${Number(m[3])} ${BULAN_ID[Number(m[2]) - 1]} ${m[1]}` : iso;
}

function namaPilihan(list, id, fallback) {
    if (id === null || id === undefined || id === '') return fallback;
    return list.find((item) => String(item.id) === String(id))?.nama ?? fallback;
}

const filterKosong = computed(() =>
    !pending.area_id &&
    !pending.cabang_id &&
    !pending.uker_id &&
    !pending.segmentasi &&
    pending.tanggal === props.tanggalAwal &&
    !applied.area_id &&
    !applied.cabang_id &&
    !applied.uker_id &&
    !applied.segmentasi &&
    applied.tanggal === props.tanggalAwal,
);

const cakupanData = computed(() => {
    let organisasi = 'Semua data Region 7 Jakarta 2';
    if (applied.uker_id) organisasi = namaPilihan(opsi.uker, applied.uker_id, 'Unit Kerja terpilih');
    else if (applied.cabang_id) organisasi = namaPilihan(opsi.cabang, applied.cabang_id, 'Cabang terpilih');
    else if (applied.area_id) organisasi = namaPilihan(opsi.area, applied.area_id, 'Area terpilih');

    return applied.segmentasi ? `${organisasi} • ${applied.segmentasi}` : organisasi;
});

async function resetFilter() {
    Object.assign(pending, {
        area_id: null,
        cabang_id: null,
        uker_id: null,
        segmentasi: null,
        tanggal: props.tanggalAwal,
    });
    Object.assign(applied, pending);
    Object.assign(rankingFilter, {
        cabang_id: null,
        segmentasi: null,
        produk: null,
    });

    await muatOpsi();
    await muatSemua();
}

async function resetRankingFilter() {
    Object.assign(rankingFilter, {
        cabang_id: null,
        segmentasi: null,
        produk: null,
    });
    await muatTabel();
}

// --- Fetch ---------------------------------------------------------------
async function muatOpsi() {
    const data = await fetchFilterOptions({ area_id: applied.area_id, cabang_id: applied.cabang_id });
    opsi.area = data.area ?? [];
    opsi.cabang = data.cabang ?? [];
    opsi.uker = data.uker ?? [];
    opsi.segmentasi = data.segmentasi ?? [];
    opsi.produk = data.produk ?? [];
    opsi.periode = data.periode ?? [];
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
        branch.value = await fetchBranchPencapaian({
            tanggal: applied.tanggal,
            area_id: applied.area_id,
            cabang_id: rankingFilter.cabang_id ?? applied.cabang_id,
            uker_id: rankingFilter.cabang_id ? null : applied.uker_id,
            segmentasi: rankingFilter.segmentasi ?? applied.segmentasi,
            produk: rankingFilter.produk,
        });
    } finally {
        memuat.tabel = false;
    }
}
const muatSemua = () => Promise.all([muatKartu(), muatChart(), muatTabel()]);

async function terapkan() {
    Object.assign(applied, pending);
    rankingFilter.cabang_id = null;
    await muatOpsi();
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
watch(
    () => [rankingFilter.cabang_id, rankingFilter.segmentasi, rankingFilter.produk],
    () => muatTabel(),
);


onMounted(async () => {
    await muatOpsi();
    await muatSemua();
});
</script>

<template>
    <Head title="Dana Pihak Ketiga" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-none space-y-3 pb-4">
            <h1 class="text-lg font-extrabold uppercase tracking-tight text-brand-700 sm:text-xl">
                Dana Pihak Ketiga
            </h1>

            <!-- Filter bar -->
            <section class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200/80">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-2.5">
                    <div class="flex min-w-0 flex-wrap items-center gap-2 text-[11px] text-slate-500">
                        <span class="font-semibold">Cakupan data:</span>
                        <span class="max-w-full truncate rounded-md bg-brand-50 px-2 py-1 font-semibold text-brand-700">
                            {{ cakupanData }}
                        </span>
                    </div>
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1"
                        :class="dirty
                            ? 'bg-amber-50 text-amber-700 ring-amber-200'
                            : 'bg-emerald-50 text-emerald-700 ring-emerald-200'"
                    >
                        <span class="h-1.5 w-1.5 rounded-full" :class="dirty ? 'bg-amber-500' : 'bg-emerald-500'" />
                        {{ dirty ? 'Perubahan belum diterapkan' : 'Filter sudah diterapkan' }}
                    </span>
                </div>

                <div class="grid grid-cols-2 gap-2.5 p-3 sm:grid-cols-3 xl:grid-cols-[1.05fr_1fr_1fr_1fr_1fr_auto_auto]">
                    <label class="block min-w-0">
                        <span class="filter-label">Periode</span>
                        <select v-model="pending.tanggal" class="filter-control">
                            <option v-for="periode in opsi.periode" :key="periode" :value="periode">
                                {{ tanggalPanjang(periode) }}
                            </option>
                        </select>
                    </label>
                    <label v-if="scope.bolehPilihArea.value" class="block min-w-0">
                        <span class="filter-label">Area</span>
                        <select v-model="pending.area_id" class="filter-control">
                            <option :value="null">Semua Area</option>
                            <option v-for="a in opsi.area" :key="a.id" :value="a.id">{{ a.nama }}</option>
                        </select>
                    </label>
                    <label v-if="scope.bolehPilihCabang.value" class="block min-w-0">
                        <span class="filter-label">Cabang</span>
                        <select v-model="pending.cabang_id" class="filter-control">
                            <option :value="null">Semua Cabang</option>
                            <option v-for="c in opsi.cabang" :key="c.id" :value="c.id">{{ c.nama }}</option>
                        </select>
                    </label>
                    <label v-if="scope.bolehPilihUker.value" class="block min-w-0">
                        <span class="filter-label">Unit Kerja</span>
                        <select v-model="pending.uker_id" class="filter-control">
                            <option :value="null">Semua Unit Kerja</option>
                            <option v-for="u in opsi.uker" :key="u.id" :value="u.id">{{ u.nama }}</option>
                        </select>
                    </label>
                    <label class="block min-w-0">
                        <span class="filter-label">Segmentasi</span>
                        <select v-model="pending.segmentasi" class="filter-control">
                            <option :value="null">Semua Segmentasi</option>
                            <option v-for="s in daftarSegmentasi" :key="s" :value="s">{{ s }}</option>
                        </select>
                    </label>
                    <button
                        type="button"
                        class="mt-[18px] inline-flex h-[38px] items-center justify-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-500 transition hover:bg-slate-50 disabled:opacity-40"
                        :disabled="filterKosong"
                        @click="resetFilter"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M4 5v4h4M16 15v-4h-4" />
                            <path d="M5.6 14.4A7 7 0 0115 5.7M14.4 5.6A7 7 0 015 14.3" />
                        </svg>
                        Reset
                    </button>
                    <button
                        type="button"
                        class="mt-[18px] inline-flex h-[38px] min-w-40 items-center justify-center gap-2 rounded-lg bg-sky-400 px-5 text-xs font-bold text-white shadow-sm transition hover:bg-sky-500 disabled:cursor-default disabled:bg-sky-300"
                        :disabled="!dirty"
                        @click="terapkan"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8">
                            <circle cx="10" cy="10" r="6.5" />
                            <path d="m7.3 10 1.8 1.8 3.8-4" />
                        </svg>
                        {{ dirty ? 'Terapkan Filter' : 'Sudah Diterapkan' }}
                    </button>
                </div>
            </section>

            <!-- Hero + tren total -->
            <section class="grid grid-cols-1 gap-3 xl:grid-cols-[minmax(330px,0.82fr)_minmax(0,1.28fr)]">
                <div class="relative overflow-hidden rounded-xl bg-gradient-to-br from-[#1264ce] via-[#0757c6] to-[#0049ad] p-4 text-white shadow-md">
                    <LoadingOverlay :show="memuat.kartu" />
                    <div class="pointer-events-none absolute -right-16 -top-20 h-64 w-64 rounded-full bg-white/5" />
                    <div class="pointer-events-none absolute right-12 top-2 h-40 w-40 rounded-full border border-white/5" />

                    <div class="relative flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-white/75">Total Dana Pihak Ketiga</p>
                            <p class="mt-1 text-3xl font-extrabold leading-none tabular-nums sm:text-[34px]">{{ formatAngka(total?.nilai) }}</p>
                            <p class="mt-2 text-xs font-semibold text-cyan-100">Posisi {{ tanggalPanjang(snapshot?.tanggal) }}</p>
                        </div>

                        <div class="w-36 shrink-0 rounded-xl bg-white/10 p-3 text-right ring-1 ring-white/10 backdrop-blur-sm sm:w-40">
                            <p class="text-[9px] font-bold uppercase tracking-wide text-white/65">Target RKA</p>
                            <p class="mt-0.5 text-xl font-extrabold tabular-nums">{{ formatAngka(total?.target) }}</p>
                            <span class="mt-2 inline-flex rounded-md bg-emerald-50 px-2 py-1 text-[11px] font-extrabold text-emerald-700">
                                Penc {{ formatPct(total?.pencapaian) }}
                            </span>
                            <p class="mt-1.5 text-[11px] font-semibold text-white/85">Gap {{ formatDelta(total?.gap) }}</p>
                            <p class="text-[10px] font-bold tabular-nums" :class="Number(total?.gap ?? 0) >= 0 ? 'text-emerald-300' : 'text-rose-300'">{{ formatDeltaPct(total?.target ? (total.gap / total.target) * 100 : null) }}</p>
                        </div>
                    </div>

                    <div class="relative mt-3 h-1.5 overflow-hidden rounded-full bg-cyan-950/20">
                        <div class="h-full rounded-full bg-cyan-300 transition-all" :style="{ width: `${pencapaianProgress}%` }" />
                    </div>

                    <div class="relative mt-3 grid grid-cols-4 divide-x divide-white/10 border-t border-white/10 pt-3">
                        <div v-for="d in deltaList(total)" :key="d.key" class="px-1.5 text-center first:pl-0 last:pr-0">
                            <p class="text-[9px] font-bold uppercase tracking-wider text-white/55">{{ d.label }}</p>
                            <p class="mt-1 text-sm font-extrabold tabular-nums">{{ formatDelta(d.nilai) }}</p>
                            <p class="mt-0.5 text-[9px] font-semibold tabular-nums" :class="d.nilai >= 0 ? 'text-emerald-300' : 'text-rose-300'">
                                {{ formatDeltaPct(d.persen) }}
                            </p>
                        </div>
                    </div>
                </div>

                <div class="dashboard-card relative p-3.5">
                    <LoadingOverlay :show="memuat.chart" />
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="panel-title">Total Trend</h3>
                        <span class="text-[10px] text-slate-300">Tahun {{ chart?.tahun ?? '' }}</span>
                    </div>
                    <div class="mt-1 h-[205px]">
                        <LineChart v-if="chartTotal" :labels="chartTotal.labels" :datasets="chartTotal.datasets" variant="monthly-trend" :show-last-value-tag="true" />
                        <p v-else class="pt-20 text-center text-xs text-slate-400">Tidak ada data.</p>
                    </div>
                </div>
            </section>

            <!-- Rincian produk + chart tabungan/giro -->
            <section class="grid grid-cols-1 gap-3 xl:grid-cols-[minmax(0,1.48fr)_minmax(320px,0.92fr)]">
                <div class="dashboard-card relative overflow-hidden">
                    <LoadingOverlay :show="memuat.kartu" />
                    <div class="border-b border-slate-100 px-4 py-3">
                        <h3 class="panel-title">Rincian Performa Produk</h3>
                        <p class="mt-0.5 text-[10px] text-slate-400">Posisi {{ tanggalPanjang(snapshot?.tanggal) }}</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-[820px] w-full text-[11px]">
                            <thead>
                                <tr class="border-b border-slate-100 bg-slate-50/70 text-[9px] uppercase tracking-wide text-slate-400">
                                    <th class="table-head text-left">Produk</th>
                                    <th class="table-head text-right">Actual</th>
                                    <th class="table-head text-right">Target</th>
                                    <th class="table-head text-right">Penc %</th>
                                    <th v-for="d in DELTA" :key="d.key" class="table-head text-right">{{ d.label }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="k in barisProduk" :key="k.key" class="transition hover:bg-slate-50/70">
                                    <td class="table-cell font-bold uppercase text-slate-700">{{ k.judul }}</td>
                                    <td class="table-cell text-right">
                                        <span class="rounded-md bg-blue-50 px-2 py-1 font-bold tabular-nums text-blue-600">{{ formatAngka(k.nilai) }}</span>
                                    </td>
                                    <td class="table-cell text-right tabular-nums text-slate-500">{{ formatAngka(k.target) }}</td>
                                    <td class="table-cell text-right">
                                        <span class="rounded-md px-1.5 py-0.5 font-bold tabular-nums" :class="pctBadgeClsArah(k.pencapaian, false)">
                                            {{ formatPct(k.pencapaian) }}
                                        </span>
                                    </td>
                                    <td v-for="d in deltaList(k)" :key="d.key" class="table-cell text-right tabular-nums">
                                        <span class="block font-bold" :class="deltaCls(d.nilai)">{{ formatDelta(d.nilai) }}</span>
                                        <span class="block text-[9px]" :class="deltaCls(d.nilai)">{{ formatDeltaPct(d.persen) }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
                    <div class="dashboard-card relative p-3">
                        <LoadingOverlay :show="memuat.chart" />
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <h3 class="panel-title">Tabungan</h3>
                                <p class="mt-0.5 text-xs font-extrabold tabular-nums text-slate-700">{{ formatAngka(tabungan?.nilai) }}</p>
                            </div>
                            <span class="rounded-full bg-slate-50 px-2 py-1 text-[9px] font-bold text-slate-500 ring-1 ring-slate-100">
                                {{ formatPct(tabungan?.pencapaian) }}
                            </span>
                        </div>
                        <div class="mt-1 h-[122px]">
                            <LineChart v-if="chartTabungan" :labels="chartTabungan.labels" :datasets="chartTabungan.datasets" variant="monthly-trend" :show-last-value-tag="true" />
                            <p v-else class="pt-10 text-center text-[10px] text-slate-400">Tidak ada data.</p>
                        </div>
                    </div>

                    <div class="dashboard-card relative p-3">
                        <LoadingOverlay :show="memuat.chart" />
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <h3 class="panel-title">Giro</h3>
                                <p class="mt-0.5 text-xs font-extrabold tabular-nums text-slate-700">{{ formatAngka(giro?.nilai) }}</p>
                            </div>
                            <span class="rounded-full bg-slate-50 px-2 py-1 text-[9px] font-bold text-slate-500 ring-1 ring-slate-100">
                                {{ formatPct(giro?.pencapaian) }}
                            </span>
                        </div>
                        <div class="mt-1 h-[122px]">
                            <LineChart v-if="chartGiro" :labels="chartGiro.labels" :datasets="chartGiro.datasets" variant="monthly-trend" :show-last-value-tag="true" />
                            <p v-else class="pt-10 text-center text-[10px] text-slate-400">Tidak ada data.</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- CASA full width -->
            <section class="dashboard-card relative p-3.5">
                <LoadingOverlay :show="memuat.chart" />
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <h3 class="panel-title">CASA</h3>
                        <p class="mt-0.5 text-[10px] text-slate-400">Tabungan + Giro</p>
                    </div>
                    <div class="flex flex-wrap items-center justify-end gap-1.5 text-[9px] font-bold">
                        <span class="metric-pill bg-blue-50 text-blue-600">Actual {{ formatAngka(casa?.nilai) }}</span>
                        <span class="metric-pill bg-slate-100 text-slate-600">RKA {{ formatAngka(casa?.target) }}</span>
                        <span class="metric-pill bg-emerald-50 text-emerald-600">Penc {{ formatPct(casa?.pencapaian) }}</span>
                        <span class="metric-pill bg-cyan-50 text-cyan-700">Rasio {{ formatPct(rasioCasa) }}</span>
                    </div>
                </div>
                <div class="mt-1 h-[170px]">
                    <LineChart v-if="chartCasa" :labels="chartCasa.labels" :datasets="chartCasa.datasets" variant="monthly-trend" :show-last-value-tag="true" />
                    <p v-else class="pt-16 text-center text-xs text-slate-400">Tidak ada data.</p>
                </div>
            </section>

            <!-- Segmentasi: tampilan disiapkan, backend tidak diubah -->
            <section class="dashboard-card overflow-hidden">
                <div class="border-b border-slate-100 px-4 py-3">
                    <h3 class="panel-title">Rincian Segmentasi</h3>
                    <p class="mt-0.5 text-[10px] text-slate-400">Rincian saldo aktual berdasarkan segmentasi pada filter aktif.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-[760px] w-full text-[11px]">
                        <thead>
                            <tr class="border-b border-slate-100 bg-slate-50/70 text-[9px] uppercase tracking-wide text-slate-400">
                                <th class="table-head text-left">Segmentasi</th>
                                <th class="table-head text-right">Total DPK</th>
                                <th class="table-head text-right">Tabungan</th>
                                <th class="table-head text-right">Giro</th>
                                <th class="table-head text-right">Deposito</th>
                                <th class="table-head text-right">CASA</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="s in barisSegmentasi" :key="s.nama" class="hover:bg-slate-50/60">
                                <td class="table-cell font-semibold text-slate-700">{{ s.nama }}</td>
                                <td class="table-cell text-right font-bold text-blue-600">{{ formatAngka(s.total_dpk ?? s.total) }}</td>
                                <td class="table-cell text-right text-slate-600">{{ formatAngka(s.tabungan) }}</td>
                                <td class="table-cell text-right text-slate-600">{{ formatAngka(s.giro) }}</td>
                                <td class="table-cell text-right text-slate-600">{{ formatAngka(s.deposito) }}</td>
                                <td class="table-cell text-right font-bold text-emerald-600">{{ formatAngka(s.casa) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Kinerja cabang -->
            <section v-if="scope.bolehLihatRanking.value" class="dashboard-card relative overflow-hidden">
                <LoadingOverlay :show="memuat.tabel" />
                <div class="flex flex-wrap items-end justify-between gap-3 border-b border-slate-100 px-4 py-3">
                    <div>
                        <h3 class="panel-title">
                            Kinerja Dana Pihak Ketiga {{ branch.grouping === 'uker' ? 'Unit Kerja' : 'Cabang' }}
                        </h3>
                        <p class="mt-0.5 text-[10px] text-slate-400">Posisi {{ tanggalPanjang(branch?.tanggal) }}</p>
                    </div>
                    <div class="flex flex-wrap items-end gap-2">
                        <label class="block">
                            <span class="mini-filter-label">Cabang</span>
                            <select v-model="rankingFilter.cabang_id" class="mini-filter-control">
                                <option :value="null">Semua Cabang</option>
                                <option v-for="c in opsi.cabang" :key="c.id" :value="c.id">{{ c.nama }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="mini-filter-label">Segmentasi</span>
                            <select v-model="rankingFilter.segmentasi" class="mini-filter-control">
                                <option :value="null">Semua Segmentasi</option>
                                <option v-for="s in daftarSegmentasi" :key="s" :value="s">{{ s }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="mini-filter-label">Produk</span>
                            <select v-model="rankingFilter.produk" class="mini-filter-control">
                                <option :value="null">Semua Produk</option>
                                <option v-for="p in opsi.produk" :key="p" :value="p">{{ p }}</option>
                            </select>
                        </label>
                        <button
                            type="button"
                            class="group inline-flex h-[2.4rem] items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-3.5 text-[11px] font-extrabold text-slate-600 shadow-sm transition hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700"
                            @click="resetRankingFilter"
                        >
                            <span class="flex h-5 w-5 items-center justify-center rounded-md bg-slate-100 transition group-hover:bg-blue-100">
                                <svg class="h-3.5 w-3.5 transition-transform duration-300 group-hover:-rotate-180" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M4.5 7.2A6.3 6.3 0 1 1 4 12" />
                                    <path d="M4.5 3.5v3.7h3.7" />
                                </svg>
                            </span>
                            Reset
                        </button>
                    </div>
                </div>
                <div class="h-[3px] w-full bg-[linear-gradient(90deg,#2E7BFF_0%,#34C2FF_22%,#34D399_48%,#F5B940_68%,#E879F9_84%,#C084FC_100%)]"></div>

                <div class="overflow-x-auto">
                    <table class="min-w-[1120px] w-full text-[10px]">
                        <thead>
                            <tr class="border-b border-slate-100 bg-slate-50/60 text-[8px] uppercase tracking-wide text-slate-400">
                                <th class="w-10 px-3 py-2 text-center font-semibold">#</th>
                                <th
                                    v-for="k in KOLOM"
                                    :key="k.key"
                                    class="cursor-pointer select-none border-t-2 px-3 py-2 font-semibold"
                                    :class="[
                                        k.kelas,
                                        k.key === 'nama' ? 'border-blue-500' : '',
                                        k.key === 'nilai' ? 'border-sky-400' : '',
                                        k.key === 'target' ? 'border-violet-400' : '',
                                        k.key === 'pencapaian' ? 'border-emerald-400' : '',
                                        k.key === 'gap' ? 'border-amber-400' : '',
                                    ]"
                                    @click="sort.urutkanKolom(k.key)"
                                >
                                    {{ k.key === 'nama' ? `Nama ${branch.grouping === 'uker' ? 'Unit Kerja' : 'Cabang'}` : k.label }} <SortArrow :arah="sort.arahUntuk(k.key)" />
                                </th>
                                <th v-for="d in DELTA" :key="d.key" class="border-t-2 border-fuchsia-300 px-3 py-2 text-right font-semibold">
                                    {{ d.label }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="(b, i) in barisTerurut" :key="b.id" class="transition hover:bg-blue-50/30">
                                <td class="px-3 py-2 text-center text-slate-400">{{ i + 1 }}</td>
                                <td class="px-3 py-2">
                                    <p class="font-bold text-slate-700">{{ b.nama }}</p>
                                    <p v-if="branch.grouping === 'uker' && b.cabang_nama" class="mt-1 text-[9px] font-semibold text-slate-500">Cabang: {{ b.cabang_nama }}</p>
                                    <p class="mt-0.5 text-[9px] font-semibold text-blue-600">
                                        Area Head: {{ b.area_nama ?? 'Belum terpetakan' }}
                                    </p>
                                </td>
                                <td class="px-3 py-2 text-right font-bold tabular-nums text-slate-700">{{ formatAngka(b.nilai) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-slate-500">{{ formatAngka(b.target) }}</td>
                                <td class="px-3 py-2 text-right">
                                    <span class="rounded-md px-1.5 py-0.5 font-bold tabular-nums" :class="pctBadgeClsArah(b.pencapaian, false)">
                                        {{ formatPct(b.pencapaian) }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-right font-bold tabular-nums" :class="deltaCls(b.gap)">{{ formatDelta(b.gap) }}</td>
                                <td v-for="d in DELTA" :key="d.key" class="px-3 py-2 text-right tabular-nums">
                                    <span class="block font-bold" :class="deltaCls(nilaiDeltaCabang(b, d.key))">
                                        {{ formatDelta(nilaiDeltaCabang(b, d.key)) }}
                                    </span>
                                    <span class="block text-[8px]" :class="deltaCls(nilaiDeltaCabang(b, d.key))">
                                        {{ formatDeltaPct(persenDeltaCabang(b, d.key)) }}
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="!barisTerurut.length">
                                <td colspan="10" class="px-3 py-8 text-center text-xs text-slate-400">Tidak ada data.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.dashboard-card {
    border-radius: 0.75rem;
    background: #ffffff;
    box-shadow: 0 1px 3px rgb(15 23 42 / 0.08);
    border: 1px solid rgb(226 232 240 / 0.8);
}

.panel-title {
    font-size: 0.68rem;
    line-height: 1rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.025em;
    color: #475569;
}

.filter-label,
.mini-filter-label {
    display: block;
    margin-bottom: 0.3rem;
    font-size: 0.68rem;
    line-height: 0.9rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.075em;
    color: #64748b;
}

.filter-control {
    display: block;
    width: 100%;
    height: 2.375rem;
    border-radius: 0.5rem;
    border-color: #e2e8f0;
    background-color: #fff;
    padding-left: 0.7rem;
    padding-right: 1.8rem;
    font-size: 0.72rem;
    line-height: 1rem;
    color: #475569;
    box-shadow: 0 1px 2px rgb(15 23 42 / 0.03);
}

.filter-control:focus,
.mini-filter-control:focus {
    border-color: #38bdf8;
    box-shadow: 0 0 0 2px rgb(56 189 248 / 0.15);
    outline: none;
}

.mini-filter-control {
    display: block;
    width: 10.75rem;
    height: 2.4rem;
    border-radius: 0.7rem;
    border-color: #cbd5e1;
    background-color: #fff;
    padding-left: 0.8rem;
    padding-right: 2rem;
    font-size: 0.75rem;
    line-height: 1rem;
    font-weight: 700;
    color: #334155;
    box-shadow: 0 1px 2px rgb(15 23 42 / 0.05);
}

.table-head {
    padding: 0.55rem 0.75rem;
    font-weight: 700;
    white-space: nowrap;
}

.table-cell {
    padding: 0.65rem 0.75rem;
    white-space: nowrap;
}

.metric-pill {
    display: inline-flex;
    align-items: center;
    border-radius: 0.4rem;
    padding: 0.3rem 0.5rem;
    white-space: nowrap;
}

@media (max-width: 639px) {
    .filter-control {
        font-size: 0.68rem;
    }

    .mini-filter-control {
        width: 9.75rem;
    }
}
</style>
