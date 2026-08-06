<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import LineChart from '@/Components/LineChart.vue';
import LoadingOverlay from '@/Components/LoadingOverlay.vue';
import SortArrow from '@/Components/SortArrow.vue';
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import * as edcApi from '@/services/edcApi';
import * as qrisApi from '@/services/qrisApi';
import {
    formatAngka,
    formatDelta,
    formatDeltaJumlah,
    formatDeltaPct,
    formatJumlah,
    formatPct,
} from '@/utils/formatAngka';
import { deltaCls, pctBadgeClsArah } from '@/utils/pencapaian';
import { useScope } from '@/utils/scope';
import { useTableSort } from '@/utils/useTableSort';

const props = defineProps({
    tanggalAwal: { type: String, required: true },
    filterAwal: { type: Object, default: () => ({}) },
});

const scope = useScope();

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
    Object.keys(applied).some((key) => (pending[key] ?? null) !== (applied[key] ?? null)),
);

const opsi = reactive({ area: [], cabang: [], uker: [] });
const kpiMeta = ref([]);
const selectedKpi = ref(null);
const snapshot = ref(null);
const charts = reactive({});
const branch = ref({
    grouping: 'cabang',
    baris: [],
    rupiah: false,
    punya_target: false,
    inverse: false,
});
const drilldown = ref(null);
const memuat = reactive({ kartu: false, chart: false, tabel: false });
const sort = useTableSort('nilai', 'desc');

const kartuSemua = computed(() => snapshot.value?.kartu ?? []);
const kartuCompact = computed(() => {
    if (toggle.value !== 'edc') return [];
    const kode = ['TID', 'MID', 'EDC_PRODUKTIF', 'EDC_SV_0'];
    return kode.map((item) => kartuSemua.value.find((k) => k.kode === item)).filter(Boolean);
});
const kartuLebar = computed(() => {
    const compact = new Set(kartuCompact.value.map((k) => k.kode));
    return kartuSemua.value.filter((k) => !compact.has(k.kode));
});
const selectedMeta = computed(() => kpiMeta.value.find((k) => k.kode === selectedKpi.value) ?? null);
const barisTerurut = computed(() => sort.urutkan(branch.value.baris ?? []));

const NAMA_BULAN = [
    'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
];
function tanggalPanjang(tanggal) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(tanggal ?? '');
    return m ? `${Number(m[3])} ${NAMA_BULAN[Number(m[2]) - 1]} ${m[1]}` : '—';
}

function fmtNilai(kartu, nilai = kartu?.nilai) {
    return kartu?.rupiah ? formatAngka(nilai) : formatJumlah(nilai);
}
function fmtDelta(kartu, nilai) {
    return kartu?.rupiah ? formatDelta(nilai) : formatDeltaJumlah(nilai);
}
function deltaBaris(kartu) {
    return (kartu?.label_delta ?? []).map((item) => ({
        ...item,
        nilai: kartu?.delta?.[item.key]?.nilai ?? null,
        persen: kartu?.delta?.[item.key]?.persen ?? null,
    }));
}
function toneDelta(kartu, nilai) {
    if (nilai === null || nilai === undefined || Number(nilai) === 0) return 'text-white/65';
    const baik = kartu?.inverse ? Number(nilai) < 0 : Number(nilai) > 0;
    return baik ? 'text-emerald-300' : 'text-rose-300';
}

const WARNA_TREND = ['#7c8ea6', '#a855f7', '#5f95ff', '#31c48d', '#f0ad32', '#ff4d4f'];
function chartDataset(kode) {
    const data = charts[kode];
    const seri = data?.seri ?? [];
    if (!seri.length) return { labels: [], datasets: [] };

    const hariMaks = Math.max(1, ...seri.flatMap((s) => s.titik.map((t) => t.hari)));
    const labels = Array.from({ length: hariMaks }, (_, i) => String(i + 1));

    return {
        labels,
        datasets: seri.map((s, index) => {
            const terakhir = index === seri.length - 1;
            const warna = WARNA_TREND[index] ?? (terakhir ? '#ff4d4f' : '#60a5fa');
            return {
                label: `${s.nama} ${s.tahun}`,
                borderColor: warna,
                backgroundColor: warna,
                data: labels.map((hari) => s.titik.find((t) => t.hari === Number(hari))?.nilai ?? null),
                borderDash: terakhir ? [] : [5, 5],
                borderWidth: terakhir ? 2.8 : 1.8,
                pointRadius: 0,
                pointHoverRadius: terakhir ? 4 : 3,
                spanGaps: false,
                fill: false,
            };
        }),
    };
}
function formatChart(kode) {
    return charts[kode]?.rupiah ? formatAngka : formatJumlah;
}

const KOLOM = computed(() => {
    const hasil = [
        { key: 'nomor', label: '#', kelas: 'text-center w-12', sortable: false },
        { key: 'nama', label: 'Nama', kelas: 'text-left min-w-[260px]', sortable: true },
        { key: 'nilai', label: selectedMeta.value?.label ?? 'Actual', kelas: 'text-right min-w-[110px]', sortable: true },
    ];

    if (branch.value.punya_target) {
        hasil.push(
            { key: 'target', label: 'Target', kelas: 'text-right min-w-[105px]', sortable: true },
            { key: 'pencapaian', label: 'Penc %', kelas: 'text-right min-w-[90px]', sortable: true },
        );
    }

    hasil.push(
        { key: 'dtd', label: 'D-1', kelas: 'text-right min-w-[95px]', sortable: true },
        { key: 'mtd', label: 'MTD', kelas: 'text-right min-w-[95px]', sortable: true },
        { key: 'ytd', label: 'YTD', kelas: 'text-right min-w-[95px]', sortable: true },
        { key: 'yoy', label: 'YOY', kelas: 'text-right min-w-[95px]', sortable: true },
    );

    return hasil;
});

const fmtBranchNilai = computed(() => (branch.value.rupiah ? formatAngka : formatJumlah));
const fmtBranchDelta = computed(() => (branch.value.rupiah ? formatDelta : formatDeltaJumlah));

async function muatOpsi() {
    const data = await api.value.fetchFilterOptions({
        area_id: applied.area_id,
        cabang_id: applied.cabang_id,
    });
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

async function muatSemuaChart() {
    memuat.chart = true;
    try {
        const hasil = await Promise.all(
            kpiMeta.value.map(async (kpi) => [
                kpi.kode,
                await api.value.fetchChart({ ...applied, kpi: kpi.kode }),
            ]),
        );
        Object.keys(charts).forEach((key) => delete charts[key]);
        hasil.forEach(([kode, data]) => { charts[kode] = data; });
    } finally {
        memuat.chart = false;
    }
}

async function muatTabel() {
    if (!scope.bolehLihatRanking.value || !selectedKpi.value) {
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
    return Promise.all([muatKartu(), muatSemuaChart(), muatTabel()]);
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
    snapshot.value = null;
    Object.keys(charts).forEach((kode) => delete charts[kode]);
    await muatOpsi();
    await muatSemua();
}

watch(selectedKpi, () => muatTabel());
watch(drilldown, () => muatTabel());
watch(
    () => pending.area_id,
    async (areaId) => {
        pending.cabang_id = null;
        pending.uker_id = null;
        opsi.uker = [];
        opsi.cabang = areaId
            ? await api.value.fetchCabang(areaId)
            : (await api.value.fetchFilterOptions({})).cabang;
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
    <Head title="Merchant" />

    <AuthenticatedLayout>
        <div class="-mx-4 bg-[#eef3f8] px-4 py-5 sm:-mx-6 sm:px-6">
            <div class="mx-auto max-w-[1600px] space-y-4">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <h1 class="text-2xl font-black uppercase tracking-tight text-[#0756bd]">Merchant</h1>

                    <div class="inline-flex self-start rounded-2xl bg-white p-1.5 shadow-sm ring-1 ring-slate-200 lg:self-auto">
                        <button
                            v-for="item in TOGGLE"
                            :key="item.key"
                            type="button"
                            class="min-w-[112px] rounded-xl px-6 py-2.5 text-lg font-black transition"
                            :class="toggle === item.key
                                ? 'bg-[#075dcc] text-white shadow-[0_7px_16px_rgba(7,93,204,0.28)]'
                                : 'text-slate-300 hover:bg-slate-50 hover:text-slate-500'"
                            @click="gantiToggle(item.key)"
                        >
                            {{ item.label }}
                        </button>
                    </div>
                </div>

                <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-[1fr_1fr_1fr_1fr_auto]">
                        <label class="block">
                            <span class="mb-1.5 block text-[10px] font-black uppercase tracking-[0.14em] text-slate-500">Tanggal</span>
                            <input v-model="pending.tanggal" type="date" class="h-12 w-full rounded-xl border-slate-200 text-sm font-semibold text-slate-700 shadow-sm focus:border-brand-500 focus:ring-brand-500" />
                        </label>

                        <label v-if="scope.bolehPilihArea.value" class="block">
                            <span class="mb-1.5 block text-[10px] font-black uppercase tracking-[0.14em] text-slate-500">Area</span>
                            <select v-model="pending.area_id" class="h-12 w-full rounded-xl border-slate-200 text-sm font-semibold text-slate-700 shadow-sm focus:border-brand-500 focus:ring-brand-500">
                                <option :value="null">Semua Area</option>
                                <option v-for="a in opsi.area" :key="a.id" :value="a.id">{{ a.nama }}</option>
                            </select>
                        </label>

                        <label v-if="scope.bolehPilihCabang.value" class="block">
                            <span class="mb-1.5 block text-[10px] font-black uppercase tracking-[0.14em] text-slate-500">Cabang</span>
                            <select v-model="pending.cabang_id" class="h-12 w-full rounded-xl border-slate-200 text-sm font-semibold text-slate-700 shadow-sm focus:border-brand-500 focus:ring-brand-500">
                                <option :value="null">Semua Cabang</option>
                                <option v-for="c in opsi.cabang" :key="c.id" :value="c.id">{{ c.nama }}</option>
                            </select>
                        </label>

                        <label v-if="scope.bolehPilihUker.value" class="block">
                            <span class="mb-1.5 block text-[10px] font-black uppercase tracking-[0.14em] text-slate-500">Unit Kerja</span>
                            <select v-model="pending.uker_id" class="h-12 w-full rounded-xl border-slate-200 text-sm font-semibold text-slate-700 shadow-sm focus:border-brand-500 focus:ring-brand-500">
                                <option :value="null">Semua Unit Kerja</option>
                                <option v-for="u in opsi.uker" :key="u.id" :value="u.id">{{ u.nama }}</option>
                            </select>
                        </label>

                        <div class="flex items-end">
                            <button
                                type="button"
                                class="inline-flex h-12 w-full min-w-[145px] items-center justify-center gap-2 rounded-xl bg-[#075dcc] px-5 text-sm font-black text-white shadow-[0_8px_18px_rgba(7,93,204,0.25)] transition hover:bg-[#064fac]"
                                :class="dirty ? 'ring-2 ring-amber-300' : ''"
                                @click="terapkan"
                            >
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="8.5" cy="8.5" r="5.5"/><path d="m12.5 12.5 4 4"/></svg>
                                Terapkan
                            </button>
                        </div>
                    </div>
                </section>

                <section class="relative">
                    <LoadingOverlay :show="memuat.kartu || memuat.chart" />

                    <div v-if="kartuCompact.length" class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                        <article v-for="k in kartuCompact" :key="k.kode" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                            <div class="bg-gradient-to-r from-[#0754b9] via-[#0b66d0] to-[#278ae4] p-4 text-white">
                                <div class="flex flex-col gap-4 sm:flex-row sm:items-stretch">
                                    <div class="min-w-[170px] flex-1">
                                        <p class="text-sm font-black uppercase tracking-[0.12em]">{{ k.judul }}</p>
                                        <p class="mt-1 text-[2rem] font-black leading-none tabular-nums">{{ fmtNilai(k) }}</p>
                                        <p class="mt-2 text-[10px] font-semibold text-cyan-100">Posisi {{ tanggalPanjang(snapshot?.tanggal) }}</p>
                                    </div>

                                    <div v-if="k.punya_target" class="min-w-[105px] border-l border-white/15 pl-4 text-center">
                                        <p class="text-[8px] font-bold uppercase tracking-wide text-white/55">Target</p>
                                        <p class="mt-1 text-sm font-black tabular-nums">{{ fmtNilai(k, k.target) }}</p>
                                        <p class="mt-1 text-[9px] font-bold text-white/70">Gap {{ fmtDelta(k, k.gap) }}</p>
                                        <span class="mt-1 inline-flex rounded-md px-2 py-1 text-[9px] font-black" :class="pctBadgeClsArah(k.pencapaian, k.inverse)">
                                            {{ formatPct(k.pencapaian) }}
                                        </span>
                                    </div>

                                    <div class="grid flex-[1.5] grid-cols-2 gap-1 sm:grid-cols-4">
                                        <div v-for="d in deltaBaris(k)" :key="d.key" class="border-l border-white/15 px-2 text-center first:border-l-0 sm:first:border-l">
                                            <p class="text-[8px] font-bold uppercase tracking-wide text-white/55">{{ d.label }}</p>
                                            <p class="mt-1 text-[11px] font-black tabular-nums" :class="toneDelta(k, d.nilai)">{{ fmtDelta(k, d.nilai) }}</p>
                                            <p class="mt-0.5 text-[9px] font-bold tabular-nums" :class="toneDelta(k, d.nilai)">{{ formatDeltaPct(d.persen) }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="h-[190px] p-3">
                                <LineChart
                                    v-if="chartDataset(k.kode).datasets.length"
                                    :labels="chartDataset(k.kode).labels"
                                    :datasets="chartDataset(k.kode).datasets"
                                    :format-nilai="formatChart(k.kode)"
                                    variant="monthly-trend"
                                    :show-last-value-tag="true"
                                />
                                <p v-else class="pt-16 text-center text-xs text-slate-400">Tidak ada data.</p>
                            </div>
                        </article>
                    </div>

                    <div class="mt-4 space-y-4">
                        <article v-for="k in kartuLebar" :key="k.kode" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                            <div class="grid grid-cols-1 xl:grid-cols-[360px_minmax(0,1fr)]">
                                <div class="relative overflow-hidden bg-gradient-to-br from-[#0754b9] via-[#0b67d0] to-[#2f8de5] p-5 text-white">
                                    <span class="pointer-events-none absolute -right-12 -top-16 h-52 w-52 rounded-full bg-white/8" />
                                    <div class="relative flex items-start justify-between gap-3">
                                        <div>
                                            <p class="text-sm font-black uppercase tracking-[0.12em]">{{ k.judul }}</p>
                                            <p class="mt-2 text-[2.6rem] font-black leading-none tracking-tight tabular-nums">{{ fmtNilai(k) }}</p>
                                            <p class="mt-2 text-[10px] font-semibold text-cyan-100">Posisi {{ tanggalPanjang(snapshot?.tanggal) }}</p>
                                        </div>
                                        <div v-if="k.punya_target" class="rounded-xl bg-white/10 px-3 py-2 text-right ring-1 ring-white/15">
                                            <p class="text-[8px] font-bold uppercase tracking-wide text-white/55">Target RKA</p>
                                            <p class="mt-1 text-sm font-black tabular-nums">{{ fmtNilai(k, k.target) }}</p>
                                            <span class="mt-2 inline-flex rounded-md px-2 py-1 text-[9px] font-black" :class="pctBadgeClsArah(k.pencapaian, k.inverse)">
                                                Penc {{ formatPct(k.pencapaian) }}
                                            </span>
                                        </div>
                                    </div>

                                    <div class="relative mt-6 grid gap-1 border-t border-white/15 pt-4" :class="deltaBaris(k).length >= 4 ? 'grid-cols-4' : 'grid-cols-3'">
                                        <div v-for="d in deltaBaris(k)" :key="d.key" class="border-l border-white/15 px-2 text-center first:border-l-0 first:pl-0">
                                            <p class="text-[8px] font-bold uppercase tracking-wide text-white/55">{{ d.label }}</p>
                                            <p class="mt-1 text-sm font-black tabular-nums" :class="toneDelta(k, d.nilai)">{{ fmtDelta(k, d.nilai) }}</p>
                                            <p class="mt-0.5 text-[9px] font-bold tabular-nums" :class="toneDelta(k, d.nilai)">{{ formatDeltaPct(d.persen) }}</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="h-[235px] p-4">
                                    <LineChart
                                        v-if="chartDataset(k.kode).datasets.length"
                                        :labels="chartDataset(k.kode).labels"
                                        :datasets="chartDataset(k.kode).datasets"
                                        :format-nilai="formatChart(k.kode)"
                                        variant="monthly-trend"
                                        :show-last-value-tag="true"
                                    />
                                    <p v-else class="pt-20 text-center text-xs text-slate-400">Tidak ada data.</p>
                                </div>
                            </div>
                        </article>
                    </div>
                </section>

                <section v-if="scope.bolehLihatRanking.value" class="relative overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <LoadingOverlay :show="memuat.tabel" />

                    <div class="flex flex-col gap-3 border-b border-slate-200 px-4 py-3 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h2 class="text-sm font-black uppercase tracking-wide text-slate-700">
                                Kinerja {{ toggle.toUpperCase() }} {{ branch.grouping === 'uker' ? 'Unit Kerja' : 'Cabang' }}
                            </h2>
                            <p class="mt-0.5 text-[10px] font-medium text-slate-400">Posisi {{ tanggalPanjang(branch.tanggal) }}</p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <select v-model="drilldown" class="h-10 min-w-[180px] rounded-xl border-slate-200 text-xs font-semibold text-slate-700">
                                <option :value="null">Semua BO</option>
                                <option v-for="c in opsi.cabang" :key="c.id" :value="c.id">{{ c.nama }}</option>
                            </select>
                            <select v-model="selectedKpi" class="h-10 min-w-[170px] rounded-xl border-slate-200 text-xs font-semibold text-slate-700">
                                <option v-for="k in kpiMeta" :key="k.kode" :value="k.kode">{{ k.label }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full border-collapse text-[11px]">
                            <thead class="bg-slate-50 text-slate-500">
                                <tr>
                                    <th
                                        v-for="kolom in KOLOM"
                                        :key="kolom.key"
                                        class="border-b border-slate-200 px-3 py-2.5 font-black uppercase tracking-wide"
                                        :class="[kolom.kelas, kolom.sortable ? 'cursor-pointer select-none' : '']"
                                        @click="kolom.sortable && sort.urutkanKolom(kolom.key)"
                                    >
                                        {{ kolom.label }}
                                        <SortArrow v-if="kolom.sortable" :arah="sort.arahUntuk(kolom.key)" />
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="(b, index) in barisTerurut" :key="b.id" class="transition hover:bg-blue-50/50">
                                    <td class="px-3 py-2.5 text-center font-semibold text-slate-400">{{ index + 1 }}</td>
                                    <td class="px-3 py-2.5 font-bold text-slate-700">{{ b.nama }}</td>
                                    <td class="px-3 py-2.5 text-right font-black tabular-nums text-slate-800">{{ fmtBranchNilai(b.nilai) }}</td>
                                    <template v-if="branch.punya_target">
                                        <td class="px-3 py-2.5 text-right font-semibold tabular-nums text-slate-500">{{ fmtBranchNilai(b.target) }}</td>
                                        <td class="px-3 py-2.5 text-right">
                                            <span class="rounded-md px-2 py-1 font-black tabular-nums" :class="pctBadgeClsArah(b.pencapaian, branch.inverse)">{{ formatPct(b.pencapaian) }}</span>
                                        </td>
                                    </template>
                                    <td v-for="key in ['dtd', 'mtd', 'ytd', 'yoy']" :key="`${b.id}-${key}`" class="px-3 py-2.5 text-right font-bold tabular-nums" :class="deltaCls(b[key], branch.inverse)">
                                        {{ fmtBranchDelta(b[key]) }}
                                    </td>
                                </tr>
                                <tr v-if="!barisTerurut.length">
                                    <td :colspan="KOLOM.length" class="px-4 py-10 text-center text-slate-400">Tidak ada data.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
