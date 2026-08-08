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
} from '@/services/recoveryApi';
import { formatAngka, formatDelta, formatDeltaPct, formatPct } from '@/utils/formatAngka';
import { deltaCls, pctBadgeCls } from '@/utils/pencapaian';
import { useScope } from '@/utils/scope';
import { useTableSort } from '@/utils/useTableSort';

const props = defineProps({
    tanggalAwal: { type: String, required: true },
    filterAwal: { type: Object, default: () => ({}) },
});

const scopeAkses = useScope();
const pending = reactive({
    tanggal: props.tanggalAwal,
    area_id: props.filterAwal.area_id ?? null,
    cabang_id: props.filterAwal.cabang_id ?? null,
    uker_id: props.filterAwal.uker_id ?? null,
});
const applied = reactive({ ...pending });
const dirty = computed(() =>
    Object.keys(applied).some((key) => (pending[key] ?? null) !== (applied[key] ?? null)),
);

const opsi = reactive({ area: [], cabang: [], uker: [], tanggal_min: null, tanggal_maks: null });
const penyesuaianTanggal = ref(null);
const snapshot = ref(null);
const charts = reactive({});
const branch = ref({ grouping: 'cabang', baris: [], tanggal_referensi: {} });
const drilldown = ref(null);
const filterCabangTabel = ref(null);
const memuat = reactive({ kartu: false, chart: false, tabel: false });
const sort = useTableSort('pencapaian', 'desc');

const CARD_ORDER = ['total', 'micro', 'sme', 'consumer'];
const CARD_SCOPE = {
    total: 'total',
    micro: 'micro',
    sme: 'sme',
    consumer: 'consumer',
};

const NAMA_BULAN = [
    'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
];

const kartuUrut = computed(() => {
    const semua = snapshot.value?.kartu ?? [];
    const peta = Object.fromEntries(semua.map((item) => [String(item.key).toLowerCase(), item]));

    return CARD_ORDER.map((key) => peta[key]).filter(Boolean);
});

const barisTerurut = computed(() => sort.urutkan(branch.value.baris ?? []));

const judulTabel = computed(() => `KINERJA RECOVERY ${branch.value.grouping === 'uker' ? 'UNIT KERJA' : 'PER CABANG'}`);

const daftarPeriode = computed(() => {
    const min = opsi.tanggal_min ? new Date(`${opsi.tanggal_min}T00:00:00`) : null;
    const max = opsi.tanggal_maks ? new Date(`${opsi.tanggal_maks}T00:00:00`) : null;
    if (!min || !max || Number.isNaN(min.getTime()) || Number.isNaN(max.getTime())) {
        return pending.tanggal ? [pending.tanggal] : [];
    }

    const hasil = [];
    const cursor = new Date(max);
    let pengaman = 0;
    while (cursor >= min && pengaman < 1100) {
        const y = cursor.getFullYear();
        const m = String(cursor.getMonth() + 1).padStart(2, '0');
        const d = String(cursor.getDate()).padStart(2, '0');
        hasil.push(`${y}-${m}-${d}`);
        cursor.setDate(cursor.getDate() - 1);
        pengaman += 1;
    }

    return hasil;
});

const cakupanData = computed(() => {
    const uker = opsi.uker.find((item) => Number(item.id) === Number(applied.uker_id));
    if (uker) return uker.nama;

    const cabang = opsi.cabang.find((item) => Number(item.id) === Number(applied.cabang_id));
    if (cabang) return cabang.nama;

    const area = opsi.area.find((item) => Number(item.id) === Number(applied.area_id));
    if (area) return area.nama;

    return 'Semua data Region 7 Jakarta 2';
});

function tanggalPanjang(tanggal) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(tanggal ?? '');
    return m ? `${Number(m[3])} ${NAMA_BULAN[Number(m[2]) - 1]} ${m[1]}` : '—';
}

function tanggalMini(tanggal) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(tanggal ?? '');
    return m ? `${Number(m[3])} ${m[2]}/${m[1].slice(2)}` : '—';
}

function deltaTone(delta) {
    const nilai = Number(delta?.nilai ?? 0);
    return nilai >= 0 ? 'text-emerald-200' : 'text-rose-200';
}

function cardGradient(key) {
    return {
        total: 'from-[#064aa8] via-[#0758bd] to-[#0b67cf]',
        micro: 'from-[#2f96e8] via-[#2b86dc] to-[#1b70cd]',
        sme: 'from-[#287fd6] via-[#1d72cb] to-[#0f62bd]',
        consumer: 'from-[#064aa8] via-[#0758bd] to-[#0b67cf]',
    }[String(key).toLowerCase()] ?? 'from-[#0757c6] to-[#2F8BFF]';
}

function progressWidth(card) {
    const persen = Number(card?.pencapaian ?? 0);
    if (!Number.isFinite(persen) || persen <= 0) return '0%';
    return `${Math.min(persen, 100)}%`;
}

function chartDataset(key) {
    const data = charts[key];
    const seri = data?.seri ?? [];
    if (!seri.length) return { labels: [], datasets: [] };

    const hariMaks = Math.max(1, ...seri.flatMap((s) => s.titik.map((t) => t.hari)));
    const labels = Array.from({ length: hariMaks }, (_, i) => String(i + 1));
    const warna = ['#7c8ea6', '#a855f7', '#5f95ff', '#31c48d', '#f0ad32', '#ff4d4f'];

    return {
        labels,
        datasets: seri.map((s, index) => ({
            label: `${s.nama} ${s.tahun ?? data?.tahun ?? ''}`.trim(),
            borderColor: warna[index] ?? '#60a5fa',
            backgroundColor: warna[index] ?? '#60a5fa',
            data: labels.map((hari) => s.titik.find((t) => t.hari === Number(hari))?.nilai ?? null),
            borderDash: index === seri.length - 1 ? [] : [5, 5],
            borderWidth: index === seri.length - 1 ? 2.8 : 1.8,
            pointRadius: 0,
            pointHoverRadius: index === seri.length - 1 ? 4 : 3,
            spanGaps: false,
            fill: false,
        })),
    };
}

async function muatOpsi() {
    const data = await fetchFilterOptions({ area_id: applied.area_id, cabang_id: applied.cabang_id });
    opsi.area = data.area ?? [];
    opsi.cabang = data.cabang ?? [];
    opsi.uker = data.uker ?? [];
    opsi.tanggal_min = data.tanggal_min ?? null;
    opsi.tanggal_maks = data.tanggal_maks ?? null;

    if (opsi.tanggal_maks && pending.tanggal > opsi.tanggal_maks) {
        penyesuaianTanggal.value = { dari: pending.tanggal, ke: opsi.tanggal_maks };
        pending.tanggal = opsi.tanggal_maks;
        applied.tanggal = opsi.tanggal_maks;
    } else if (opsi.tanggal_min && pending.tanggal < opsi.tanggal_min) {
        penyesuaianTanggal.value = { dari: pending.tanggal, ke: opsi.tanggal_min };
        pending.tanggal = opsi.tanggal_min;
        applied.tanggal = opsi.tanggal_min;
    }
}

async function muatKartu() {
    memuat.kartu = true;
    try {
        const diminta = applied.tanggal;
        const data = await fetchSnapshot({ ...applied });
        snapshot.value = data;

        if (data?.tanggal && data.tanggal !== diminta) {
            penyesuaianTanggal.value = { dari: diminta, ke: data.tanggal };
            applied.tanggal = data.tanggal;
            pending.tanggal = data.tanggal;
        }
    } finally {
        memuat.kartu = false;
    }
}

async function muatCharts() {
    memuat.chart = true;
    try {
        const hasil = await Promise.all(
            Object.entries(CARD_SCOPE).map(async ([key, scope]) => [
                key,
                await fetchChart({ ...applied, scope }),
            ]),
        );
        Object.keys(charts).forEach((key) => delete charts[key]);
        hasil.forEach(([key, data]) => {
            charts[key] = data;
        });
    } finally {
        memuat.chart = false;
    }
}

async function muatTabel() {
    if (!scopeAkses.bolehLihatRanking.value) {
        branch.value = { grouping: 'cabang', baris: [], tanggal_referensi: {} };
        return;
    }

    memuat.tabel = true;
    try {
        branch.value = await fetchBranchPencapaian({
            ...applied,
            cabang_id: filterCabangTabel.value ?? drilldown.value ?? applied.cabang_id,
            uker_id: filterCabangTabel.value ? null : applied.uker_id,
        });
    } finally {
        memuat.tabel = false;
    }
}

function muatSemua() {
    return Promise.all([muatKartu(), muatCharts(), muatTabel()]);
}

async function terapkan() {
    if (!dirty.value) return;
    Object.assign(applied, pending);
    drilldown.value = null;
    penyesuaianTanggal.value = null;
    await muatSemua();
}

async function resetFilter() {
    pending.tanggal = opsi.tanggal_maks ?? props.tanggalAwal;
    pending.area_id = null;
    pending.cabang_id = null;
    pending.uker_id = null;

    Object.assign(applied, pending);
    drilldown.value = null;
    filterCabangTabel.value = null;
    penyesuaianTanggal.value = null;

    await muatOpsi();
    await muatSemua();
}

function resetFilterTabel() {
    filterCabangTabel.value = null;
}

watch(drilldown, () => muatTabel());
watch(filterCabangTabel, () => muatTabel());
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
});
</script>

<template>
    <Head title="Recovery EC" />

    <AuthenticatedLayout>
        <div class="bg-[#eef3f8] py-4">
            <div class="mx-auto w-full max-w-[1880px] space-y-4 px-3 sm:px-4 lg:px-5">
                <div>
                    <h1 class="text-[28px] font-extrabold tracking-tight text-[#0857C3]">RECOVERY EC</h1>
                </div>

                <div class="rounded-[20px] bg-white px-4 py-3.5 shadow-[0_7px_22px_rgba(15,23,42,0.06)] ring-1 ring-slate-200/80">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2 text-xs font-semibold text-slate-400">
                            <span>Cakupan data:</span>
                            <span class="rounded-full border border-blue-100 bg-blue-50 px-3 py-1 font-bold text-[#0756bd]">
                                {{ cakupanData }}
                            </span>
                        </div>

                        <div
                            v-if="!dirty"
                            class="inline-flex items-center gap-1.5 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-bold text-emerald-700"
                        >
                            <span class="inline-flex h-4 w-4 items-center justify-center rounded-full border border-emerald-400 text-[10px]">✓</span>
                            Filter sudah diterapkan
                        </div>
                        <div
                            v-else
                            class="inline-flex items-center gap-1.5 rounded-full border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-bold text-amber-700"
                        >
                            Filter belum diterapkan
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-3 lg:grid-cols-[1.05fr_1.05fr_1.05fr_1.05fr_auto_auto] lg:items-end">
                        <label>
                            <span class="text-[11px] font-extrabold uppercase tracking-[0.14em] text-slate-400">Periode</span>
                            <div class="relative mt-1.5">
                                <select
                                    v-model="pending.tanggal"
                                    class="h-12 w-full appearance-none rounded-2xl border-slate-200 bg-white pl-4 pr-10 text-sm font-bold text-slate-800 shadow-sm focus:border-blue-400 focus:ring-blue-400"
                                >
                                    <option v-for="tgl in daftarPeriode" :key="tgl" :value="tgl">{{ tanggalPanjang(tgl) }}</option>
                                </select>
                                <svg class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="m6 8 4 4 4-4" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </div>
                        </label>

                        <label v-if="scopeAkses.bolehPilihArea.value">
                            <span class="text-[11px] font-extrabold uppercase tracking-[0.14em] text-slate-400">Area</span>
                            <select v-model="pending.area_id" class="mt-1.5 h-12 w-full rounded-2xl border-slate-200 bg-white px-4 text-sm font-semibold text-slate-800 shadow-sm focus:border-blue-400 focus:ring-blue-400">
                                <option :value="null">Semua Area</option>
                                <option v-for="a in opsi.area" :key="a.id" :value="a.id">{{ a.nama }}</option>
                            </select>
                        </label>

                        <label v-if="scopeAkses.bolehPilihCabang.value">
                            <span class="text-[11px] font-extrabold uppercase tracking-[0.14em] text-slate-400">Cabang</span>
                            <select v-model="pending.cabang_id" class="mt-1.5 h-12 w-full rounded-2xl border-slate-200 bg-white px-4 text-sm font-semibold text-slate-800 shadow-sm focus:border-blue-400 focus:ring-blue-400">
                                <option :value="null">Semua Cabang</option>
                                <option v-for="c in opsi.cabang" :key="c.id" :value="c.id">{{ c.nama }}</option>
                            </select>
                        </label>

                        <label v-if="scopeAkses.bolehPilihUker.value">
                            <span class="text-[11px] font-extrabold uppercase tracking-[0.14em] text-slate-400">Unit Kerja</span>
                            <select v-model="pending.uker_id" class="mt-1.5 h-12 w-full rounded-2xl border-slate-200 bg-white px-4 text-sm font-semibold text-slate-800 shadow-sm focus:border-blue-400 focus:ring-blue-400">
                                <option :value="null">Semua Unit Kerja</option>
                                <option v-for="u in opsi.uker" :key="u.id" :value="u.id">{{ u.nama }}</option>
                            </select>
                        </label>

                        <button
                            type="button"
                            class="inline-flex h-12 items-center justify-center gap-2 rounded-2xl border border-blue-100 bg-blue-50 px-5 text-sm font-bold text-[#0756bd] shadow-sm transition hover:bg-blue-100 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="memuat.kartu || memuat.chart || memuat.tabel"
                            @click="resetFilter"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 12a9 9 0 1 0 3-6.7L3 8" />
                                <path d="M3 3v5h5" />
                            </svg>
                            Reset
                        </button>

                        <button
                            type="button"
                            class="inline-flex h-12 min-w-[170px] items-center justify-center gap-2 rounded-2xl px-5 text-sm font-extrabold shadow-sm transition"
                            :class="dirty ? 'bg-[#0756bd] text-white hover:bg-[#064ba7]' : 'bg-[#8fc5ff] text-white cursor-default'"
                            :disabled="memuat.kartu || memuat.chart || memuat.tabel || !dirty"
                            @click="terapkan"
                        >
                            <svg v-if="dirty" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="m21 21-4.35-4.35" stroke-linecap="round" />
                                <circle cx="11" cy="11" r="7" />
                            </svg>
                            <svg v-else class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <path d="m5 12 4 4L19 6" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            {{ dirty ? 'Terapkan' : 'Sudah Diterapkan' }}
                        </button>
                    </div>

                    <div
                        v-if="penyesuaianTanggal"
                        class="mt-3 flex items-start gap-2 rounded-xl border border-blue-100 bg-blue-50 px-3 py-2 text-xs text-blue-700"
                    >
                        <span class="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-blue-100 font-bold">i</span>
                        <span>
                            Data Recovery belum tersedia pada {{ tanggalPanjang(penyesuaianTanggal.dari) }}.
                            Sistem otomatis memakai posisi terakhir yang tersedia: <strong>{{ tanggalPanjang(penyesuaianTanggal.ke) }}</strong>.
                        </span>
                    </div>
                </div>

                <div class="relative space-y-4">
                    <LoadingOverlay :show="memuat.kartu || memuat.chart" />

                    <div
                        v-for="card in kartuUrut"
                        :key="card.key"
                        class="grid grid-cols-1 gap-3 xl:grid-cols-[400px_minmax(0,1fr)]"
                    >
                        <div
                            class="relative overflow-hidden rounded-[16px] bg-gradient-to-br text-white shadow-[0_8px_22px_rgba(15,82,170,0.22)]"
                            :class="cardGradient(card.key)"
                        >
                            <div class="pointer-events-none absolute -right-10 -top-10 h-40 w-40 rounded-full bg-white/[0.06]" />
                            <div class="pointer-events-none absolute right-4 top-3 h-28 w-28 rounded-full border border-white/[0.05]" />
                            <div class="relative flex items-start justify-between gap-3 px-4 pt-4">
                                <div class="min-w-0 flex-1">
                                    <p class="text-[11px] font-extrabold uppercase tracking-[0.17em] text-white/80">{{ card.judul }}</p>
                                    <p class="mt-1.5 whitespace-nowrap text-[40px] font-extrabold leading-none tracking-tight tabular-nums">{{ formatAngka(card.nilai) }}</p>
                                    <p class="mt-2 text-xs font-medium text-white/80">Posisi {{ tanggalPanjang(snapshot?.tanggal) }}</p>
                                </div>
                                <div class="relative w-[128px] shrink-0 rounded-xl bg-white/10 px-3 py-2.5 text-right ring-1 ring-white/10 backdrop-blur">
                                    <p class="text-[10px] font-bold uppercase tracking-widest text-white/75">Target RKA</p>
                                    <p class="mt-1 text-lg font-extrabold">{{ formatAngka(card.target) }}</p>
                                    <span
                                        class="mt-1.5 inline-flex rounded-md px-2 py-1 text-[10px] font-extrabold"
                                        :class="pctBadgeCls(card.pencapaian)"
                                    >
                                        Penc {{ formatPct(card.pencapaian, 1) }}
                                    </span>
                                </div>
                            </div>

                            <div class="relative mx-4 mt-3 h-1.5 overflow-hidden rounded-full bg-white/15">
                                <div class="h-full rounded-full bg-cyan-200 transition-all" :style="{ width: progressWidth(card) }" />
                            </div>

                            <div class="relative mt-3 grid grid-cols-3 border-t border-white/10">
                                <div class="px-3 py-3">
                                    <p class="text-[10px] font-bold uppercase tracking-widest text-white/70">D-1</p>
                                    <p class="mt-1 text-sm font-extrabold" :class="deltaTone(card.delta?.dtd)">{{ formatDelta(card.delta?.dtd?.nilai) }}</p>
                                    <p class="text-[10px] font-bold" :class="deltaTone(card.delta?.dtd)">{{ formatDeltaPct(card.delta?.dtd?.persen) }}</p>
                                </div>
                                <div class="border-x border-white/10 px-3 py-3">
                                    <p class="text-[10px] font-bold uppercase tracking-widest text-white/70">MTD</p>
                                    <p class="mt-1 text-sm font-extrabold" :class="deltaTone(card.delta?.mtd)">{{ formatDelta(card.delta?.mtd?.nilai) }}</p>
                                    <p class="text-[10px] font-bold" :class="deltaTone(card.delta?.mtd)">{{ formatDeltaPct(card.delta?.mtd?.persen) }}</p>
                                </div>
                                <div class="px-3 py-3">
                                    <p class="text-[10px] font-bold uppercase tracking-widest text-white/70">YOY</p>
                                    <p class="mt-1 text-sm font-extrabold" :class="deltaTone(card.delta?.yoy)">{{ formatDelta(card.delta?.yoy?.nilai) }}</p>
                                    <p class="text-[10px] font-bold" :class="deltaTone(card.delta?.yoy)">{{ formatDeltaPct(card.delta?.yoy?.persen) }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-[16px] bg-white p-3 shadow-sm ring-1 ring-black/5">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-bold uppercase tracking-wide text-slate-700">{{ card.judul }}</p>
                                    <p class="mt-1 text-xs text-slate-400">Tren harian multi-bulan tahun {{ charts[String(card.key).toLowerCase()]?.tahun ?? '—' }}</p>
                                </div>
                                <div class="rounded-full bg-[#F2F7FF] px-3 py-1 text-xs font-semibold text-[#0857C3]">
                                    Gap {{ formatDelta(card.gap) }}
                                </div>
                            </div>
                            <div class="mt-2 h-[210px]">
                                <LineChart
                                    v-if="chartDataset(String(card.key).toLowerCase()).datasets.length"
                                    :labels="chartDataset(String(card.key).toLowerCase()).labels"
                                    :datasets="chartDataset(String(card.key).toLowerCase()).datasets"
                                    :format-nilai="formatAngka"
                                    variant="monthly-trend"
                                    :show-last-value-tag="true"
                                />
                                <p v-else class="pt-24 text-center text-sm text-slate-400">Tidak ada data.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="scopeAkses.bolehLihatRanking.value" class="relative overflow-hidden rounded-[16px] bg-white shadow-sm ring-1 ring-black/5">
                    <LoadingOverlay :show="memuat.tabel" />

                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
                        <div>
                            <h3 class="text-sm font-extrabold uppercase tracking-wide text-slate-700">{{ judulTabel }}</h3>
                            <p class="mt-1 text-xs text-slate-400">Posisi {{ tanggalPanjang(branch?.tanggal) }}</p>
                        </div>

                        <div class="flex flex-wrap items-end gap-2">
                            <label class="min-w-[220px]">
                                <span class="mb-1 block text-[10px] font-extrabold uppercase tracking-[0.12em] text-slate-400">Cabang</span>
                                <div class="relative">
                                    <select
                                        v-model="filterCabangTabel"
                                        class="h-10 w-full appearance-none rounded-xl border-slate-200 bg-white pl-3 pr-9 text-xs font-bold text-slate-700 shadow-sm focus:border-blue-400 focus:ring-blue-400"
                                    >
                                        <option :value="null">Semua Cabang</option>
                                        <option v-for="c in opsi.cabang" :key="c.id" :value="c.id">{{ c.nama }}</option>
                                    </select>
                                    <svg class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="m6 8 4 4 4-4" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </div>
                            </label>

                            <button
                                type="button"
                                class="inline-flex h-10 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-xs font-bold text-slate-600 shadow-sm transition hover:bg-slate-50 disabled:cursor-default disabled:opacity-45"
                                :disabled="!filterCabangTabel"
                                @click="resetFilterTabel"
                            >
                                <svg class="h-4 w-4 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M3 12a9 9 0 1 0 3-6.7L3 8" />
                                    <path d="M3 3v5h5" />
                                </svg>
                                Reset
                            </button>
                        </div>
                    </div>

                    <div class="h-[3px] w-full bg-[linear-gradient(90deg,#2E7BFF_0%,#34C2FF_22%,#34D399_48%,#F5B940_68%,#E879F9_84%,#C084FC_100%)]"></div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-slate-50/90 text-[#91A2BF]">
                                <tr class="border-b border-slate-100">
                                    <th class="px-3 py-3 text-center text-[10px] font-extrabold uppercase tracking-[0.14em]">#</th>
                                    <th class="cursor-pointer px-3 py-3 text-left text-[10px] font-extrabold uppercase tracking-[0.14em]" @click="sort.urutkanKolom('nama')">
                                        <span class="inline-flex items-center gap-1.5">Nama {{ branch.grouping === 'uker' ? 'Unit Kerja' : 'Cabang' }} <SortArrow :arah="sort.arahUntuk('nama')" /></span>
                                    </th>
                                    <th class="cursor-pointer px-3 py-3 text-right text-[10px] font-extrabold uppercase tracking-[0.14em]" @click="sort.urutkanKolom('nilai')">
                                        <span class="inline-flex items-center gap-1.5">Actual <SortArrow :arah="sort.arahUntuk('nilai')" /></span>
                                    </th>
                                    <th class="cursor-pointer px-3 py-3 text-right text-[10px] font-extrabold uppercase tracking-[0.14em]" @click="sort.urutkanKolom('target')">
                                        <span class="inline-flex items-center gap-1.5">Target <SortArrow :arah="sort.arahUntuk('target')" /></span>
                                    </th>
                                    <th class="cursor-pointer px-3 py-3 text-right text-[10px] font-extrabold uppercase tracking-[0.14em]" @click="sort.urutkanKolom('pencapaian')">
                                        <span class="inline-flex items-center gap-1.5">Penc % <SortArrow :arah="sort.arahUntuk('pencapaian')" /></span>
                                    </th>
                                    <th class="cursor-pointer px-3 py-3 text-right text-[10px] font-extrabold uppercase tracking-[0.14em]" @click="sort.urutkanKolom('gap')">
                                        <span class="inline-flex items-center gap-1.5">Gap <SortArrow :arah="sort.arahUntuk('gap')" /></span>
                                    </th>
                                    <th class="px-3 py-3 text-right text-[10px] font-extrabold uppercase tracking-[0.14em]">D-1</th>
                                    <th class="px-3 py-3 text-right text-[10px] font-extrabold uppercase tracking-[0.14em]">MTD</th>
                                    <th class="px-3 py-3 text-right text-[10px] font-extrabold uppercase tracking-[0.14em]">YOY</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, index) in barisTerurut" :key="row.id" class="border-t border-slate-100 hover:bg-slate-50/60">
                                    <td class="px-3 py-2.5 text-center text-xs text-slate-400">{{ index + 1 }}</td>
                                    <td class="px-3 py-2.5">
                                        <p class="font-semibold text-slate-800">{{ row.nama }}</p>
                                        <p v-if="branch.grouping === 'uker' && row.cabang_nama" class="mt-0.5 text-[10px] font-semibold text-slate-500">Cabang: {{ row.cabang_nama }}</p>
                                        <p v-if="row.area_nama" class="mt-0.5 text-[10px] font-semibold text-[#2f72d8]">Area Head: {{ row.area_nama }}</p>
                                    </td>
                                    <td class="px-3 py-2.5 text-right text-xs font-bold text-slate-800">{{ formatAngka(row.nilai) }}</td>
                                    <td class="px-3 py-2.5 text-right text-xs text-slate-500">{{ formatAngka(row.target) }}</td>
                                    <td class="px-3 py-2.5 text-right text-xs">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold" :class="pctBadgeCls(row.pencapaian)">
                                            {{ formatPct(row.pencapaian, 1) }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2.5 text-right text-xs font-semibold" :class="deltaCls(row.gap)">{{ formatDelta(row.gap) }}</td>
                                    <td class="px-3 py-2.5 text-right text-xs">
                                        <p class="font-semibold" :class="deltaCls(row.dtd?.nilai)">{{ formatDelta(row.dtd?.nilai) }}</p>
                                        <p class="text-xs" :class="deltaCls(row.dtd?.nilai)">{{ formatDeltaPct(row.dtd?.persen) }}</p>
                                    </td>
                                    <td class="px-3 py-2.5 text-right text-xs">
                                        <p class="font-semibold" :class="deltaCls(row.mtd?.nilai)">{{ formatDelta(row.mtd?.nilai) }}</p>
                                        <p class="text-xs" :class="deltaCls(row.mtd?.nilai)">{{ formatDeltaPct(row.mtd?.persen) }}</p>
                                    </td>
                                    <td class="px-3 py-2.5 text-right text-xs">
                                        <p class="font-semibold" :class="deltaCls(row.yoy?.nilai)">{{ formatDelta(row.yoy?.nilai) }}</p>
                                        <p class="text-xs" :class="deltaCls(row.yoy?.nilai)">{{ formatDeltaPct(row.yoy?.persen) }}</p>
                                    </td>
                                </tr>
                                <tr v-if="!barisTerurut.length">
                                    <td colspan="9" class="px-4 py-8 text-center text-sm text-slate-400">Tidak ada data.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
