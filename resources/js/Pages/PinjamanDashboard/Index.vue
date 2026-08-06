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
    fetchProduk,
    fetchSnapshot,
    fetchUker,
} from '@/services/pinjamanApi';
import { formatAngka, formatDelta, formatDeltaPct, formatPct } from '@/utils/formatAngka';
import { deltaCls, pctBadgeClsArah, pctClsArah } from '@/utils/pencapaian';
import { useScope } from '@/utils/scope';
import { useTableSort } from '@/utils/useTableSort';
import { warnaBulan } from '@/utils/chartColors';

const props = defineProps({
    tanggalAwal: { type: String, required: true },
    tabAwal: { type: String, default: 'total' },
    filterAwal: { type: Object, default: () => ({}) },
});

const scope = useScope();

// Tiga kualitas ditampilkan BERSAMAAN sebagai hero (bukan tab tunggal).
const KUALITAS = [
    { key: 'total', label: 'Total Pinjaman', warna: 'from-brand-700 to-brand-500', inverse: false },
    { key: 'sml', label: 'SML', warna: 'from-amber-500 to-orange-500', inverse: true },
    { key: 'npl', label: 'NPL', warna: 'from-rose-600 to-red-500', inverse: true },
];

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
const snap = reactive({ total: null, sml: null, npl: null });
const grafik = reactive({ total: null, sml: null, npl: null });
const produk = ref({ baris: [] });
const branch = ref({ grouping: 'cabang', baris: [] });
const memuat = reactive({ kartu: false, chart: false, produk: false, tabel: false });
const drilldown = ref(null);

// Tab tabel cabang (Total/SML/NPL) — hanya memengaruhi tabel bawah.
const tabCabang = ref('total');
const sort = useTableSort('nilai', 'desc');

// --- Hero & segmen ------------------------------------------------------
const heroKartu = (q) => (snap[q]?.kartu ?? []).find((k) => k.key === 'total') ?? null;
const labelDelta = (q) =>
    snap[q]?.label_delta ?? [
        { key: 'dtd', label: 'D-1' },
        { key: 'mtd', label: 'MTD' },
        { key: 'ytd', label: 'YTD' },
        { key: 'yoy', label: 'YOY' },
    ];
const deltaList = (kartu, q) =>
    labelDelta(q).map((d) => ({
        ...d,
        nilai: kartu?.delta?.[d.key]?.nilai ?? null,
        persen: kartu?.delta?.[d.key]?.persen ?? null,
    }));

// Kartu per segmen: gabung nilai Total/SML/NPL dari tiga snapshot.
const segmenList = computed(() => {
    const total = (snap.total?.kartu ?? []).filter((k) => k.key !== 'total');
    return total.map((t) => {
        const cari = (q) => (snap[q]?.kartu ?? []).find((k) => k.key === t.key) ?? null;
        return { segmen: t.key, total: t, sml: cari('sml'), npl: cari('npl') };
    });
});

// --- Chart --------------------------------------------------------------
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
const chartQ = (q) => buildDataset(grafik[q]?.seri);

// --- Tabel cabang -------------------------------------------------------
const cabangInverse = computed(() => tabCabang.value !== 'total');
const KOLOM = [
    { key: 'nama', label: 'Nama Cabang', kelas: 'text-left' },
    { key: 'nilai', label: 'Total', kelas: 'text-right' },
    { key: 'target', label: 'Target', kelas: 'text-right' },
    { key: 'pencapaian', label: 'Penc %', kelas: 'text-right' },
    { key: 'gap', label: 'Gap', kelas: 'text-right' },
];
const barisTerurut = computed(() => sort.urutkan(branch.value.baris ?? []));

// --- Fetch --------------------------------------------------------------
const KEYS = ['total', 'sml', 'npl'];
async function muatOpsi() {
    const data = await fetchFilterOptions({ area_id: applied.area_id, cabang_id: applied.cabang_id });
    opsi.area = data.area ?? [];
    opsi.cabang = data.cabang ?? [];
    opsi.uker = data.uker ?? [];
}
async function muatKartuChart() {
    memuat.kartu = memuat.chart = true;
    try {
        await Promise.all(
            KEYS.flatMap((q) => [
                fetchSnapshot({ ...applied, tab: q }).then((r) => (snap[q] = r)),
                fetchChart({ ...applied, tab: q }).then((r) => (grafik[q] = r)),
            ]),
        );
    } finally {
        memuat.kartu = memuat.chart = false;
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
            ...applied,
            tab: tabCabang.value,
            cabang_id: drilldown.value ?? applied.cabang_id,
        });
    } finally {
        memuat.tabel = false;
    }
}
const muatSemua = () => Promise.all([muatKartuChart(), muatProduk(), muatTabel()]);

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
watch([drilldown, tabCabang], () => muatTabel());

onMounted(async () => {
    await muatOpsi();
    await muatSemua();
});

const BULAN_ID = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
function tanggalPanjang(iso) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso ?? '');
    return m ? `${Number(m[3])} ${BULAN_ID[Number(m[2]) - 1]} ${m[1]}` : iso;
}
</script>

<template>
    <Head title="Pinjaman (Kredit)" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-7xl space-y-5">
            <h1 class="text-2xl font-extrabold uppercase tracking-tight text-brand-700">Kredit</h1>

            <!-- Filter -->
            <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-100">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
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

            <!-- Hero kualitas + chart -->
            <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
                <!-- 3 hero -->
                <div class="space-y-4 lg:col-span-1">
                    <div
                        v-for="q in KUALITAS"
                        :key="q.key"
                        class="relative overflow-hidden rounded-2xl bg-gradient-to-br p-4 text-white shadow-lg"
                        :class="q.warna"
                    >
                        <LoadingOverlay :show="memuat.kartu" />
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-white/75">{{ q.label }}</p>
                                <p class="mt-0.5 text-3xl font-extrabold tabular-nums">{{ formatAngka(heroKartu(q.key)?.nilai) }}</p>
                                <p class="mt-0.5 text-[11px] text-white/70">Posisi {{ tanggalPanjang(snap[q.key]?.tanggal) }}</p>
                            </div>
                            <div class="shrink-0 rounded-lg bg-white/15 p-2 text-right">
                                <p class="text-[9px] font-semibold uppercase text-white/70">Target RKA</p>
                                <p class="text-sm font-bold tabular-nums">{{ formatAngka(heroKartu(q.key)?.target) }}</p>
                                <span class="mt-0.5 inline-block rounded bg-white/90 px-1.5 py-0.5 text-[11px] font-bold text-slate-800">Penc {{ formatPct(heroKartu(q.key)?.pencapaian) }}</span>
                                <p class="mt-0.5 text-[10px] text-white/80">Gap {{ formatDelta(heroKartu(q.key)?.gap) }}</p>
                            </div>
                        </div>
                        <div class="mt-3 grid grid-cols-4 gap-1.5">
                            <div v-for="d in deltaList(heroKartu(q.key), q.key)" :key="d.key" class="rounded-lg bg-white/10 p-1.5 text-center">
                                <p class="text-[9px] font-semibold uppercase text-white/60">{{ d.label }}</p>
                                <p class="text-[11px] font-bold tabular-nums">{{ formatDelta(d.nilai) }}</p>
                                <p class="text-[9px] tabular-nums text-white/70">{{ formatDeltaPct(d.persen) }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 3 chart -->
                <div class="space-y-4 lg:col-span-2">
                    <div v-for="q in KUALITAS" :key="q.key" class="relative rounded-2xl bg-white p-3 shadow-sm ring-1 ring-slate-100">
                        <LoadingOverlay :show="memuat.chart" />
                        <div class="flex items-center justify-between px-1">
                            <h3 class="text-xs font-bold uppercase text-slate-500">{{ q.label }}</h3>
                            <span class="text-[11px] text-slate-400">Tahun {{ grafik[q.key]?.tahun ?? '' }}</span>
                        </div>
                        <div class="mt-1 h-36">
                            <LineChart v-if="chartQ(q.key)" :labels="chartQ(q.key).labels" :datasets="chartQ(q.key).datasets" />
                            <p v-else class="pt-12 text-center text-xs text-slate-400">Tidak ada data.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Rincian per segmen -->
            <div>
                <h3 class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-500">Rincian per Segmen</h3>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <div v-for="s in segmenList" :key="s.segmen" class="relative rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-100">
                        <p class="text-sm font-bold text-slate-800">{{ s.segmen }}</p>
                        <div class="mt-3 space-y-2.5">
                            <div v-for="row in [{ q: 'total', k: s.total, inv: false, lbl: 'Total' }, { q: 'sml', k: s.sml, inv: true, lbl: 'SML' }, { q: 'npl', k: s.npl, inv: true, lbl: 'NPL' }]" :key="row.q" class="border-t border-slate-50 pt-2 first:border-0 first:pt-0">
                                <div class="flex items-baseline justify-between">
                                    <span class="text-[10px] font-semibold uppercase text-slate-400">{{ row.lbl }}</span>
                                    <span class="text-sm font-bold tabular-nums text-slate-900">{{ formatAngka(row.k?.nilai) }}</span>
                                </div>
                                <div class="mt-0.5 flex items-center justify-between text-[10px]">
                                    <span class="tabular-nums text-slate-400">RKA {{ formatAngka(row.k?.target) }}</span>
                                    <span class="rounded px-1 py-0.5 font-bold tabular-nums" :class="pctBadgeClsArah(row.k?.pencapaian, row.inv)">{{ formatPct(row.k?.pencapaian) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Rincian kualitas per segmen (dari endpoint produk) -->
            <div class="relative rounded-2xl bg-white shadow-sm ring-1 ring-slate-100">
                <LoadingOverlay :show="memuat.produk" />
                <div class="border-b border-slate-100 p-4">
                    <h3 class="text-sm font-bold text-slate-700">Rincian Kualitas per Segmen</h3>
                    <p class="text-xs text-slate-400">Posisi {{ tanggalPanjang(produk?.tanggal) }}</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-100 text-sm">
                        <thead class="bg-slate-50">
                            <tr class="text-[11px] uppercase text-slate-400">
                                <th class="px-3 py-2 text-left font-semibold">Segmen</th>
                                <th class="px-3 py-2 text-right font-semibold">Lancar</th>
                                <th class="px-3 py-2 text-right font-semibold">SML</th>
                                <th class="px-3 py-2 text-right font-semibold">NPL</th>
                                <th class="px-3 py-2 text-right font-semibold">OS</th>
                                <th class="px-3 py-2 text-right font-semibold">%SML</th>
                                <th class="px-3 py-2 text-right font-semibold">%NPL</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <tr v-for="b in produk.baris" :key="b.segmen" class="hover:bg-slate-50">
                                <td class="px-3 py-2.5 font-semibold text-slate-800">{{ b.segmen }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ formatAngka(b.lancar) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-amber-600">{{ formatAngka(b.sml) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-rose-600">{{ formatAngka(b.npl) }}</td>
                                <td class="px-3 py-2.5 text-right font-bold tabular-nums text-slate-900">{{ formatAngka(b.os) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-slate-500">{{ formatPct(b.pct_sml) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-slate-500">{{ formatPct(b.pct_npl) }}</td>
                            </tr>
                            <tr v-if="!produk.baris.length"><td colspan="7" class="px-3 py-6 text-center text-slate-400">Tidak ada data.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Kinerja cabang -->
            <div v-if="scope.bolehLihatRanking.value" class="relative rounded-2xl bg-white shadow-sm ring-1 ring-slate-100">
                <LoadingOverlay :show="memuat.tabel" />
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4">
                    <div>
                        <h3 class="text-sm font-bold text-slate-700">
                            Kinerja Kredit {{ branch.grouping === 'uker' ? 'Unit Kerja' : 'Cabang' }}
                        </h3>
                        <p class="text-xs text-slate-400">Posisi {{ tanggalPanjang(branch?.tanggal) }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <div class="inline-flex rounded-lg bg-slate-100 p-0.5">
                            <button
                                v-for="q in KUALITAS"
                                :key="q.key"
                                type="button"
                                class="rounded-md px-3 py-1 text-xs font-semibold transition"
                                :class="tabCabang === q.key ? 'bg-white text-brand-700 shadow' : 'text-slate-500'"
                                @click="tabCabang = q.key"
                            >
                                {{ q.key === 'total' ? 'Total' : q.label }}
                            </button>
                        </div>
                        <label class="flex items-center gap-2 text-xs text-slate-500">
                            Drill-down BO
                            <select v-model="drilldown" class="rounded-lg border-slate-200 text-sm">
                                <option :value="null">Semua BO</option>
                                <option v-for="c in opsi.cabang" :key="c.id" :value="c.id">{{ c.nama }}</option>
                            </select>
                        </label>
                    </div>
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
                                    <span class="rounded-md px-1.5 py-0.5 text-xs font-bold tabular-nums" :class="pctBadgeClsArah(b.pencapaian, cabangInverse)">{{ formatPct(b.pencapaian) }}</span>
                                </td>
                                <td class="px-3 py-2.5 text-right font-semibold tabular-nums" :class="deltaCls(b.gap, cabangInverse)">{{ formatDelta(b.gap) }}</td>
                            </tr>
                            <tr v-if="!barisTerurut.length"><td colspan="6" class="px-3 py-6 text-center text-slate-400">Tidak ada data.</td></tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="branch.grouping === 'cabang'" class="border-t border-slate-100 px-4 py-2 text-[11px] text-slate-400">
                    Segmen Menengah dikelola level Region dan tidak tampil sebagai baris cabang, sehingga jumlah tabel ini lebih kecil dari kartu Total.
                </p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
