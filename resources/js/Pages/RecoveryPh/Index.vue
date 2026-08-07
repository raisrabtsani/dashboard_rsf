<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ComboChart from '@/Components/ComboChart.vue';
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
} from '@/services/recoveryPhApi';
import { formatAngka, formatDelta, formatDeltaPct } from '@/utils/formatAngka';
import { deltaCls } from '@/utils/pencapaian';
import { useScope } from '@/utils/scope';
import { useTableSort } from '@/utils/useTableSort';

const props = defineProps({
    periodeAwal: { type: String, required: true },
    modeAwal: { type: String, required: true },
    scope: { type: Array, default: () => [] },
});

const scopeAkses = useScope();
const mode = ref(props.modeAwal ?? 'ph');
const pending = reactive({
    periode: props.periodeAwal.slice(0, 7),
    area_id: null,
    cabang_id: null,
    uker_id: null,
});
const applied = reactive({ ...pending });
const dirty = computed(() =>
    Object.keys(applied).some((key) => (pending[key] ?? null) !== (applied[key] ?? null)),
);

const opsi = reactive({ area: [], cabang: [], uker: [] });
const snapshot = ref(null);
const chart = ref(null);
const branch = ref({ grouping: 'cabang', baris: [], periode_lalu: null, desember_lalu: null, periode: null });
const drilldown = ref(null);
const scopeTabel = ref('total');
const memuat = reactive({ kartu: false, chart: false, tabel: false });
const sort = useTableSort('nilai', 'desc');

const TOGGLE = [
    { key: 'ph', label: 'PH' },
    { key: 'netdg', label: 'NET DG' },
];

const SECTION_ORDER = ['total', 'micro', 'sme', 'consumer'];
const NAMA_BULAN = [
    'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
];

const opsiPeriode = computed(() => {
    const [tahunAwal, bulanAwal] = String(props.periodeAwal).slice(0, 7).split('-').map(Number);
    const akhir = new Date(tahunAwal, bulanAwal - 1, 1);
    const hasil = [];

    for (let mundur = 0; mundur < 48; mundur += 1) {
        const d = new Date(akhir.getFullYear(), akhir.getMonth() - mundur, 1);
        const tahun = d.getFullYear();
        const bulan = d.getMonth() + 1;
        hasil.push({
            value: `${tahun}-${String(bulan).padStart(2, '0')}`,
            label: labelPeriodeAkhirBulan(`${tahun}-${String(bulan).padStart(2, '0')}`),
        });
    }

    return hasil;
});

function labelPeriodeAkhirBulan(nilai) {
    const m = /^(\d{4})-(\d{2})$/.exec(nilai ?? '');
    if (!m) return nilai ?? '—';
    const tahun = Number(m[1]);
    const bulan = Number(m[2]);
    const hari = new Date(tahun, bulan, 0).getDate();
    return `${hari} ${NAMA_BULAN[bulan - 1]} ${tahun}`;
}

const kartuUrut = computed(() => {
    const semua = snapshot.value?.kartu ?? [];
    const peta = Object.fromEntries(semua.map((item) => [String(item.key).toLowerCase(), item]));

    return SECTION_ORDER.map((key) => peta[key]).filter(Boolean);
});

const barisTerurut = computed(() => sort.urutkan(branch.value.baris ?? []));

const judulHalaman = computed(() => 'PH & NET DG');
const judulTabel = computed(() => `KINERJA ${mode.value === 'ph' ? 'PH' : 'NET DG'} ${branch.value.grouping === 'uker' ? 'UNIT KERJA' : 'PER CABANG'}`);
const scopeTabelOptions = [
    { key: 'total', label: 'Semua Segmen' },
    { key: 'micro', label: 'PH Mikro' },
    { key: 'sme', label: 'PH SME' },
    { key: 'consumer', label: 'PH Consumer' },
];

function periodeUntukApi(nilai) {
    if (!nilai) return null;
    const [tahun, bulan] = nilai.split('-').map(Number);
    return new Date(tahun, bulan, 0).toISOString().slice(0, 10);
}

function bulanTahunPanjang(periode) {
    const m = /^(\d{4})-(\d{2})/.exec(periode ?? '');
    return m ? `${NAMA_BULAN[Number(m[2]) - 1]} ${m[1]}` : '—';
}

function bulanPendek(periode) {
    const m = /^(\d{4})-(\d{2})/.exec(periode ?? '');
    return m ? `${NAMA_BULAN[Number(m[2]) - 1].slice(0, 3).toUpperCase()} ${m[1].slice(2)}` : '—';
}

function chartUntuk(key) {
    return chart.value?.seri?.[key] ?? null;
}

async function muatOpsi() {
    const data = await fetchFilterOptions({ area_id: applied.area_id, cabang_id: applied.cabang_id, mode: mode.value });
    opsi.area = data.area ?? [];
    opsi.cabang = data.cabang ?? [];
    opsi.uker = data.uker ?? [];
}

async function muatSnapshot() {
    memuat.kartu = true;
    try {
        snapshot.value = await fetchSnapshot({
            mode: mode.value,
            periode: periodeUntukApi(applied.periode),
            area_id: applied.area_id,
            cabang_id: applied.cabang_id,
            uker_id: applied.uker_id,
        });
    } finally {
        memuat.kartu = false;
    }
}

async function muatChart() {
    memuat.chart = true;
    try {
        chart.value = await fetchChart({
            mode: mode.value,
            periode: periodeUntukApi(applied.periode),
            area_id: applied.area_id,
            cabang_id: applied.cabang_id,
            uker_id: applied.uker_id,
        });
    } finally {
        memuat.chart = false;
    }
}

async function muatTabel() {
    if (!scopeAkses.bolehLihatRanking.value) {
        branch.value = { grouping: 'cabang', baris: [], periode_lalu: null, desember_lalu: null, periode: null };
        return;
    }

    memuat.tabel = true;
    try {
        branch.value = await fetchBranchPencapaian({
            mode: mode.value,
            periode: periodeUntukApi(applied.periode),
            area_id: applied.area_id,
            cabang_id: drilldown.value ?? applied.cabang_id,
            uker_id: applied.uker_id,
            scope: scopeTabel.value,
        });
    } finally {
        memuat.tabel = false;
    }
}

function muatSemua() {
    return Promise.all([muatSnapshot(), muatChart(), muatTabel()]);
}

async function terapkan() {
    Object.assign(applied, pending);
    drilldown.value = null;
    await muatOpsi();
    await muatSemua();
}

async function resetFilter() {
    pending.periode = String(props.periodeAwal).slice(0, 7);
    pending.area_id = null;
    pending.cabang_id = null;
    pending.uker_id = null;
    Object.assign(applied, pending);
    drilldown.value = null;
    opsi.uker = [];
    await muatOpsi();
    await muatSemua();
}

async function gantiMode(key) {
    if (mode.value === key) return;
    mode.value = key;
    await muatOpsi();
    await muatSemua();
}

watch(drilldown, () => muatTabel());
watch(scopeTabel, () => muatTabel());
watch(
    () => pending.area_id,
    async (areaId) => {
        pending.cabang_id = null;
        pending.uker_id = null;
        opsi.uker = [];
        opsi.cabang = areaId ? await fetchCabang(areaId) : (await fetchFilterOptions({ mode: mode.value })).cabang;
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
    <Head title="PH & Net DG" />

    <AuthenticatedLayout>
        <div class="bg-[#eef3f8] py-4">
            <div class="mx-auto w-full max-w-[1880px] space-y-4 px-3 sm:px-4 lg:px-5">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <h1 class="text-[34px] font-extrabold tracking-tight text-[#0857C3]">{{ judulHalaman }}</h1>
                    <div class="inline-flex rounded-2xl bg-white p-1.5 shadow-sm ring-1 ring-black/5">
                        <button
                            v-for="item in TOGGLE"
                            :key="item.key"
                            type="button"
                            class="min-w-[120px] rounded-xl px-5 py-3 text-lg font-extrabold transition"
                            :class="mode === item.key ? 'bg-[#0857C3] text-white shadow' : 'text-slate-400'"
                            @click="gantiMode(item.key)"
                        >
                            {{ item.label }}
                        </button>
                    </div>
                </div>

                <div class="rounded-[22px] border border-slate-200 bg-white px-4 py-4 shadow-[0_8px_24px_rgba(15,23,42,0.06)] sm:px-5">
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <span class="font-semibold text-slate-400">Cakupan data:</span>
                            <span class="inline-flex rounded-full bg-[#eef6ff] px-3 py-1.5 text-xs font-extrabold text-[#0756bd] ring-1 ring-[#d6e8ff]">
                                Semua data Region 7 Jakarta 2
                            </span>
                        </div>

                        <span
                            class="inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-bold ring-1"
                            :class="dirty
                                ? 'bg-amber-50 text-amber-700 ring-amber-200'
                                : 'bg-emerald-50 text-emerald-700 ring-emerald-200'"
                        >
                            <span
                                class="flex h-4 w-4 items-center justify-center rounded-full border text-[10px]"
                                :class="dirty ? 'border-amber-400' : 'border-emerald-400'"
                            >
                                {{ dirty ? '!' : '✓' }}
                            </span>
                            {{ dirty ? 'Filter belum diterapkan' : 'Filter sudah diterapkan' }}
                        </span>
                    </div>

                    <div class="grid grid-cols-1 gap-3 lg:grid-cols-[1.15fr_1.15fr_1.35fr_1.35fr_auto_auto] lg:items-end">
                        <label class="block min-w-0">
                            <span class="text-[11px] font-extrabold uppercase tracking-[0.14em] text-slate-400">Periode</span>
                            <div class="relative mt-1.5">
                                <select
                                    v-model="pending.periode"
                                    class="h-12 w-full appearance-none rounded-xl border-slate-200 bg-white pl-4 pr-10 text-sm font-bold text-slate-800 shadow-sm focus:border-[#7db7ff] focus:ring-[#7db7ff]"
                                >
                                    <option v-for="periode in opsiPeriode" :key="periode.value" :value="periode.value">
                                        {{ periode.label }}
                                    </option>
                                </select>
                                <svg class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="m6 8 4 4 4-4" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </div>
                        </label>

                        <label v-if="scopeAkses.bolehPilihArea.value" class="block min-w-0">
                            <span class="text-[11px] font-extrabold uppercase tracking-[0.14em] text-slate-400">Area</span>
                            <div class="relative mt-1.5">
                                <select v-model="pending.area_id" class="h-12 w-full appearance-none rounded-xl border-slate-200 bg-white pl-4 pr-10 text-sm font-bold text-slate-800 shadow-sm focus:border-[#7db7ff] focus:ring-[#7db7ff]">
                                    <option :value="null">Semua Area</option>
                                    <option v-for="a in opsi.area" :key="a.id" :value="a.id">{{ a.nama }}</option>
                                </select>
                                <svg class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="m6 8 4 4 4-4" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </div>
                        </label>

                        <label v-if="scopeAkses.bolehPilihCabang.value" class="block min-w-0">
                            <span class="text-[11px] font-extrabold uppercase tracking-[0.14em] text-slate-400">Cabang</span>
                            <div class="relative mt-1.5">
                                <select v-model="pending.cabang_id" class="h-12 w-full appearance-none rounded-xl border-slate-200 bg-white pl-4 pr-10 text-sm font-bold text-slate-800 shadow-sm focus:border-[#7db7ff] focus:ring-[#7db7ff]">
                                    <option :value="null">Semua Cabang</option>
                                    <option v-for="c in opsi.cabang" :key="c.id" :value="c.id">{{ c.nama }}</option>
                                </select>
                                <svg class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="m6 8 4 4 4-4" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </div>
                        </label>

                        <label v-if="scopeAkses.bolehPilihUker.value" class="block min-w-0">
                            <span class="text-[11px] font-extrabold uppercase tracking-[0.14em] text-slate-400">Unit Kerja</span>
                            <div class="relative mt-1.5">
                                <select v-model="pending.uker_id" class="h-12 w-full appearance-none rounded-xl border-slate-200 bg-white pl-4 pr-10 text-sm font-bold text-slate-800 shadow-sm focus:border-[#7db7ff] focus:ring-[#7db7ff]">
                                    <option :value="null">Semua Unit Kerja</option>
                                    <option v-for="u in opsi.uker" :key="u.id" :value="u.id">{{ u.nama }}</option>
                                </select>
                                <svg class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="m6 8 4 4 4-4" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </div>
                        </label>

                        <button
                            type="button"
                            class="inline-flex h-12 min-w-[118px] items-center justify-center gap-2 rounded-xl border border-[#d8e5f5] bg-[#f4f8fd] px-4 text-sm font-extrabold text-[#4775ac] shadow-sm transition hover:bg-[#eaf3ff] disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="memuat.kartu || memuat.chart || memuat.tabel"
                            @click="resetFilter"
                        >
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M3 12a9 9 0 1 0 3-6.7" stroke-linecap="round" />
                                <path d="M3 4v6h6" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            Reset
                        </button>

                        <button
                            type="button"
                            class="inline-flex h-12 min-w-[190px] items-center justify-center gap-2 rounded-xl px-5 text-sm font-extrabold text-white shadow-sm transition disabled:cursor-not-allowed"
                            :class="dirty
                                ? 'bg-[#0865d7] hover:bg-[#0758bd]'
                                : 'bg-[#86bdf7]'"
                            :disabled="!dirty || memuat.kartu || memuat.chart || memuat.tabel"
                            @click="terapkan"
                        >
                            <svg v-if="!dirty" class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.3">
                                <path d="m4 10 4 4 8-9" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            {{ dirty ? 'Terapkan' : 'Sudah Diterapkan' }}
                        </button>
                    </div>
                </div>

                <div v-if="snapshot?.ph_kosong" class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
                    Data PH tahun berjalan belum tersedia lengkap. Perhitungan Net DG mengikuti data yang ada.
                </div>

                <div class="relative space-y-5">
                    <LoadingOverlay :show="memuat.kartu || memuat.chart" />

                    <section v-for="card in kartuUrut" :key="card.key" class="space-y-3">
                        <div class="text-sm font-extrabold uppercase tracking-wide text-[#0857C3]">
                            {{ mode === 'ph' ? 'PH ' : 'NET DG ' }}{{ card.judul }}
                        </div>

                        <div class="overflow-hidden rounded-[22px] bg-gradient-to-r from-[#0857C3] to-[#2F8BFF] text-white shadow-lg">
                            <div class="grid grid-cols-1 items-center gap-4 px-6 py-5 lg:grid-cols-[1fr_auto_auto]">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-[0.22em] text-white/75">{{ card.judul }}</p>
                                    <p class="mt-1 text-5xl font-extrabold leading-none tracking-tight">{{ formatAngka(card.akumulasi) }}</p>
                                    <p class="mt-2 text-sm text-white/80">Akumulasi s/d {{ bulanTahunPanjang(snapshot?.periode) }}</p>
                                </div>
                                <div class="rounded-2xl bg-white/10 px-5 py-4 text-right backdrop-blur">
                                    <p class="text-[10px] font-bold uppercase tracking-widest text-white/70">MTD</p>
                                    <p class="mt-1 text-3xl font-extrabold" :class="deltaCls(card.delta?.mom?.nilai)">{{ formatDelta(card.delta?.mom?.nilai) }}</p>
                                    <p class="text-sm" :class="deltaCls(card.delta?.mom?.nilai)">{{ formatDeltaPct(card.delta?.mom?.persen) }}</p>
                                </div>
                                <div class="rounded-2xl bg-white/10 px-5 py-4 text-right backdrop-blur">
                                    <p class="text-[10px] font-bold uppercase tracking-widest text-white/70">YOY</p>
                                    <p class="mt-1 text-3xl font-extrabold" :class="deltaCls(card.delta?.yoy?.nilai)">{{ formatDelta(card.delta?.yoy?.nilai) }}</p>
                                    <p class="text-sm" :class="deltaCls(card.delta?.yoy?.nilai)">{{ formatDeltaPct(card.delta?.yoy?.persen) }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-[22px] bg-white p-4 shadow-sm ring-1 ring-black/5">
                            <div class="mb-3 flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-sm font-bold uppercase tracking-wide text-slate-700">{{ mode === 'ph' ? 'Total ' : '' }}{{ card.judul }}</p>
                                    <p class="mt-1 text-xs text-slate-400">Perbandingan {{ chartUntuk(String(card.key).toLowerCase())?.tahun_lalu?.tahun }} vs {{ chartUntuk(String(card.key).toLowerCase())?.tahun_ini?.tahun }}</p>
                                </div>
                                <div class="text-xs text-slate-400">Periode {{ bulanTahunPanjang(snapshot?.periode) }}</div>
                            </div>
                            <div class="h-[360px]">
                                <ComboChart
                                    v-if="chartUntuk(String(card.key).toLowerCase())"
                                    :labels="chartUntuk(String(card.key).toLowerCase())?.label ?? []"
                                    :tahun-lalu="chartUntuk(String(card.key).toLowerCase())?.tahun_lalu"
                                    :tahun-ini="chartUntuk(String(card.key).toLowerCase())?.tahun_ini"
                                />
                                <p v-else class="pt-24 text-center text-sm text-slate-400">Tidak ada data.</p>
                            </div>
                        </div>
                    </section>
                </div>

                <div v-if="scopeAkses.bolehLihatRanking.value" class="relative overflow-hidden rounded-[22px] bg-white shadow-sm ring-1 ring-black/5">
                    <LoadingOverlay :show="memuat.tabel" />

                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
                        <div>
                            <h3 class="text-sm font-extrabold uppercase tracking-wide text-slate-700">{{ judulTabel }}</h3>
                            <p class="mt-1 text-xs text-slate-400">Posisi {{ bulanTahunPanjang(branch?.periode) }}</p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <select v-model="drilldown" class="h-10 rounded-xl border-slate-200 text-sm">
                                <option :value="null">Semua BO</option>
                                <option v-for="c in opsi.cabang" :key="c.id" :value="c.id">{{ c.nama }}</option>
                            </select>
                            <select v-model="scopeTabel" class="h-10 rounded-xl border-slate-200 text-sm">
                                <option v-for="opt in scopeTabelOptions" :key="opt.key" :value="opt.key">{{ opt.label }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-slate-50/80 text-slate-500">
                                <tr>
                                    <th class="px-4 py-3 text-center text-[11px] font-bold uppercase tracking-wider">#</th>
                                    <th class="cursor-pointer px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider" @click="sort.urutkanKolom('nama')">
                                        Nama Cabang
                                        <SortArrow :arah="sort.arahUntuk('nama')" />
                                    </th>
                                    <th class="cursor-pointer px-4 py-3 text-right text-[11px] font-bold uppercase tracking-wider" @click="sort.urutkanKolom('periode_lalu')">
                                        {{ bulanPendek(branch?.periode_lalu) }}
                                        <SortArrow :arah="sort.arahUntuk('periode_lalu')" />
                                    </th>
                                    <th class="cursor-pointer px-4 py-3 text-right text-[11px] font-bold uppercase tracking-wider" @click="sort.urutkanKolom('desember_lalu')">
                                        {{ bulanPendek(branch?.desember_lalu) }}
                                        <SortArrow :arah="sort.arahUntuk('desember_lalu')" />
                                    </th>
                                    <th class="cursor-pointer px-4 py-3 text-right text-[11px] font-bold uppercase tracking-wider" @click="sort.urutkanKolom('nilai')">
                                        {{ bulanPendek(branch?.periode) }}
                                        <SortArrow :arah="sort.arahUntuk('nilai')" />
                                    </th>
                                    <th class="px-4 py-3 text-right text-[11px] font-bold uppercase tracking-wider">MTD</th>
                                    <th class="px-4 py-3 text-right text-[11px] font-bold uppercase tracking-wider">YOY</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, index) in barisTerurut" :key="row.id" class="border-t border-slate-100 hover:bg-slate-50/60">
                                    <td class="px-4 py-3 text-center text-slate-400">{{ index + 1 }}</td>
                                    <td class="px-4 py-3">
                                        <p class="font-semibold text-slate-800">{{ row.nama }}</p>
                                        <p v-if="row.area_nama" class="text-xs text-slate-400">{{ row.area_nama }}</p>
                                    </td>
                                    <td class="px-4 py-3 text-right text-slate-500">{{ formatAngka(row.periode_lalu) }}</td>
                                    <td class="px-4 py-3 text-right text-slate-500">{{ formatAngka(row.desember_lalu) }}</td>
                                    <td class="px-4 py-3 text-right font-bold text-slate-800">{{ formatAngka(row.nilai) }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <p class="font-semibold" :class="deltaCls(row.mtd?.nilai)">{{ formatDelta(row.mtd?.nilai) }}</p>
                                        <p class="text-xs" :class="deltaCls(row.mtd?.nilai)">{{ formatDeltaPct(row.mtd?.persen) }}</p>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <p class="font-semibold" :class="deltaCls(row.yoy?.nilai)">{{ formatDelta(row.yoy?.nilai) }}</p>
                                        <p class="text-xs" :class="deltaCls(row.yoy?.nilai)">{{ formatDeltaPct(row.yoy?.persen) }}</p>
                                    </td>
                                </tr>
                                <tr v-if="!barisTerurut.length">
                                    <td colspan="7" class="px-4 py-8 text-center text-sm text-slate-400">Tidak ada data.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
