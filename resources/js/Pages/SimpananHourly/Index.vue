<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import LineChart from '@/Components/LineChart.vue';
import LoadingOverlay from '@/Components/LoadingOverlay.vue';
import SortArrow from '@/Components/SortArrow.vue';
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import {
    fetchBranch,
    fetchCabang,
    fetchChart,
    fetchFilterOptions,
    fetchSnapshot,
} from '@/services/simpananHourlyApi';
import { formatAngka, formatDelta, formatDeltaPct, formatPct } from '@/utils/formatAngka';
import { deltaCls, pctBadgeCls } from '@/utils/pencapaian';
import { useScope } from '@/utils/scope';
import { useTableSort } from '@/utils/useTableSort';

const props = defineProps({
    tanggalAwal: { type: String, required: true },
    intervalRefresh: { type: Number, default: 120 },
});

const scope = useScope();

const pending = reactive({
    area_id: null,
    cabang_id: null,
    uker_id: null,
    tanggal: props.tanggalAwal,
    jam: null,
    produk: null,
});
const applied = reactive({ ...pending });

const dirty = computed(() =>
    Object.keys(applied).some((key) => (pending[key] ?? null) !== (applied[key] ?? null)),
);

const opsi = reactive({ area: [], cabang: [], tanggal: [], produk: [] });
const snapshot = ref(null);
const chart = ref(null);
const branch = ref({ grouping: 'cabang', baris: [] });
const memuat = reactive({ kartu: false, chart: false, tabel: false });
const senyap = ref(false);
let timer = null;

const sort = useTableSort('nilai', 'desc');
const barisTerurut = computed(() => sort.urutkan(branch.value?.baris ?? []));
const jamTersedia = computed(() => snapshot.value?.jam_tersedia ?? []);

const NAMA_BULAN = [
    'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
];
const NAMA_BULAN_SINGKAT = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Ags', 'Sep', 'Okt', 'Nov', 'Des'];

function formatTanggal(tanggal, panjang = false) {
    if (!tanggal) return '–';
    const [tahun, bulan, hari] = String(tanggal).slice(0, 10).split('-').map(Number);
    if (!tahun || !bulan || !hari) return tanggal;

    return `${hari} ${panjang ? NAMA_BULAN[bulan - 1] : NAMA_BULAN_SINGKAT[bulan - 1]} ${tahun}`;
}

function formatJam(jam) {
    return jam === null || jam === undefined ? '–' : `${String(jam).padStart(2, '0')}:00`;
}

function kartu(key) {
    return snapshot.value?.kartu?.find((item) => item.key === key) ?? null;
}

function datasetUntuk(key, warna) {
    const seri = chart.value?.seri?.find((item) => item.key === key);
    if (!seri || !(chart.value?.label?.length)) return null;

    return {
        labels: chart.value.label,
        datasets: [
            {
                label: seri.judul,
                borderColor: warna,
                backgroundColor: warna,
                data: seri.titik,
                spanGaps: true,
            },
        ],
    };
}

const totalChart = computed(() => datasetUntuk('total', '#0b61d8'));
const tabunganChart = computed(() => datasetUntuk('tabungan', '#10b981'));
const giroChart = computed(() => datasetUntuk('giro', '#f59e0b'));
const depositoChart = computed(() => datasetUntuk('deposito', '#8b5cf6'));

const judulTabel = computed(() =>
    branch.value?.grouping === 'uker' ? 'KINERJA DPK UNIT KERJA' : 'KINERJA DPK CABANG',
);
const labelEntitas = computed(() =>
    branch.value?.grouping === 'uker' ? 'NAMA UNIT KERJA' : 'NAMA CABANG',
);
const labelBulanLalu = computed(() => formatTanggal(branch.value?.tanggal_referensi?.mtd));

const KOLOM = computed(() => [
    { key: 'nama', label: labelEntitas.value, kelas: 'text-left min-w-[230px]' },
    { key: 'saldo_bulan_lalu', label: labelBulanLalu.value, kelas: 'text-right min-w-[120px]' },
    { key: 'nilai', label: `SALDO (${formatJam(branch.value?.jam)})`, kelas: 'text-right min-w-[120px]' },
    { key: 'target', label: 'RKA', kelas: 'text-right min-w-[105px]' },
    { key: 'pencapaian', label: 'PENC', kelas: 'text-right min-w-[90px]' },
    { key: 'h1', label: 'H-1', kelas: 'text-right min-w-[110px]' },
    { key: 'dtd', label: 'DTD', kelas: 'text-right min-w-[110px]' },
    { key: 'mtd', label: 'MTD', kelas: 'text-right min-w-[110px]' },
    { key: 'ytd', label: 'YTD', kelas: 'text-right min-w-[110px]' },
    { key: 'yoy', label: 'YOY', kelas: 'text-right min-w-[110px]' },
]);

async function muatOpsi() {
    const data = await fetchFilterOptions({ area_id: applied.area_id, cabang_id: applied.cabang_id });
    opsi.area = data.area ?? [];
    opsi.cabang = data.cabang ?? [];
    opsi.tanggal = data.tanggal ?? [];
    opsi.produk = data.produk ?? ['Tabungan', 'Giro', 'Deposito'];

    if (!opsi.tanggal.includes(pending.tanggal) && opsi.tanggal.length) {
        pending.tanggal = opsi.tanggal[0];
        applied.tanggal = opsi.tanggal[0];
    }
}

async function muatSemua({ diam = false } = {}) {
    senyap.value = diam;
    if (!diam) memuat.kartu = memuat.chart = memuat.tabel = true;

    try {
        const filter = { ...applied };
        const [dataSnapshot, dataChart, dataBranch] = await Promise.all([
            fetchSnapshot(filter),
            fetchChart(filter),
            fetchBranch(filter),
        ]);
        snapshot.value = dataSnapshot;
        chart.value = dataChart;
        branch.value = {
            ...dataBranch,
            baris: (dataBranch?.baris ?? []).map((baris) => ({
                ...baris,
                h1: baris.delta?.h1?.nilai ?? null,
                dtd: baris.delta?.dtd?.nilai ?? null,
                mtd: baris.delta?.mtd?.nilai ?? null,
                ytd: baris.delta?.ytd?.nilai ?? null,
                yoy: baris.delta?.yoy?.nilai ?? null,
            })),
        };
    } finally {
        memuat.kartu = memuat.chart = memuat.tabel = false;
        senyap.value = false;
    }
}

function terapkan() {
    Object.assign(applied, pending);
    muatSemua();
}

function mulaiAutoRefresh() {
    hentikanAutoRefresh();
    timer = setInterval(() => muatSemua({ diam: true }), props.intervalRefresh * 1000);
}

function hentikanAutoRefresh() {
    if (timer !== null) {
        clearInterval(timer);
        timer = null;
    }
}

watch(
    () => pending.area_id,
    async (areaId) => {
        pending.cabang_id = null;
        pending.uker_id = null;
        pending.jam = null;
        opsi.cabang = areaId ? await fetchCabang(areaId) : (await fetchFilterOptions({})).cabang;
    },
);
watch(
    () => pending.cabang_id,
    () => {
        pending.uker_id = null;
        pending.jam = null;
    },
);
watch(
    () => [pending.tanggal, pending.produk],
    () => {
        pending.jam = null;
    },
);

onMounted(async () => {
    await muatOpsi();
    await muatSemua();
    mulaiAutoRefresh();
});

onUnmounted(hentikanAutoRefresh);
</script>

<template>
    <Head title="DPK Hourly" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-[1500px] space-y-4">
            <!-- Judul -->
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-extrabold tracking-tight text-brand-700">DPK HOURLY</h1>
                <span class="rounded-full bg-amber-100 px-3 py-1 text-[11px] font-extrabold uppercase tracking-wide text-amber-700">Only EOM</span>
                <p class="ml-auto text-xs text-slate-400">
                    Auto-refresh {{ Math.round(intervalRefresh / 60) }} menit · terakhir {{ snapshot?.diperbarui ?? '–' }}
                </p>
            </div>

            <!-- Filter -->
            <section class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-[1.05fr_.62fr_1.05fr_1.05fr_1fr_auto]">
                    <label class="block">
                        <span class="mb-1.5 block text-[11px] font-bold uppercase tracking-wide text-slate-500">Tanggal</span>
                        <select v-model="pending.tanggal" class="h-11 w-full rounded-xl border-slate-200 bg-white text-sm font-semibold text-slate-700 shadow-sm focus:border-brand-500 focus:ring-brand-500">
                            <option v-for="tanggal in opsi.tanggal" :key="tanggal" :value="tanggal">{{ formatTanggal(tanggal) }}</option>
                        </select>
                    </label>

                    <label class="block">
                        <span class="mb-1.5 block text-[11px] font-bold uppercase tracking-wide text-slate-500">Jam</span>
                        <select v-model="pending.jam" class="h-11 w-full rounded-xl border-slate-200 bg-white text-sm font-semibold text-slate-700 shadow-sm focus:border-brand-500 focus:ring-brand-500">
                            <option :value="null">Terbaru</option>
                            <option v-for="jam in jamTersedia" :key="jam" :value="jam">{{ formatJam(jam) }}</option>
                        </select>
                    </label>

                    <label v-if="scope.bolehPilihArea.value" class="block">
                        <span class="mb-1.5 block text-[11px] font-bold uppercase tracking-wide text-slate-500">Area</span>
                        <select v-model="pending.area_id" class="h-11 w-full rounded-xl border-slate-200 bg-white text-sm font-semibold text-slate-700 shadow-sm focus:border-brand-500 focus:ring-brand-500">
                            <option :value="null">Semua Area</option>
                            <option v-for="area in opsi.area" :key="area.id" :value="area.id">{{ area.nama }}</option>
                        </select>
                    </label>

                    <label v-if="scope.bolehPilihCabang.value" class="block">
                        <span class="mb-1.5 block text-[11px] font-bold uppercase tracking-wide text-slate-500">Cabang</span>
                        <select v-model="pending.cabang_id" class="h-11 w-full rounded-xl border-slate-200 bg-white text-sm font-semibold text-slate-700 shadow-sm focus:border-brand-500 focus:ring-brand-500">
                            <option :value="null">Semua Cabang</option>
                            <option v-for="cabang in opsi.cabang" :key="cabang.id" :value="cabang.id">{{ cabang.nama }}</option>
                        </select>
                    </label>

                    <label class="block">
                        <span class="mb-1.5 block text-[11px] font-bold uppercase tracking-wide text-slate-500">Produk</span>
                        <select v-model="pending.produk" class="h-11 w-full rounded-xl border-slate-200 bg-white text-sm font-semibold text-slate-700 shadow-sm focus:border-brand-500 focus:ring-brand-500">
                            <option :value="null">Semua Produk</option>
                            <option v-for="produk in opsi.produk" :key="produk" :value="produk">{{ produk }}</option>
                        </select>
                    </label>

                    <div class="flex items-end">
                        <button
                            type="button"
                            class="inline-flex h-11 min-w-[140px] items-center justify-center gap-2 rounded-xl bg-brand-600 px-5 text-sm font-bold text-white shadow-md shadow-brand-600/20 transition hover:bg-brand-700"
                            :class="{ 'ring-2 ring-amber-300 ring-offset-2': dirty }"
                            @click="terapkan"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 103.9 9.4l3.3 3.3a1 1 0 001.4-1.4l-3.3-3.3A5.5 5.5 0 009 3.5zM5.5 9a3.5 3.5 0 117 0 3.5 3.5 0 01-7 0z" clip-rule="evenodd" /></svg>
                            Terapkan
                        </button>
                    </div>
                </div>
            </section>

            <!-- Tabel -->
            <section class="relative overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
                <LoadingOverlay :show="memuat.tabel" :senyap="senyap" />
                <div class="border-b border-slate-200 bg-gradient-to-r from-slate-50 to-white px-5 py-4">
                    <h2 class="text-sm font-extrabold tracking-wide text-slate-800">{{ judulTabel }}</h2>
                    <p class="mt-1 text-xs text-slate-500">
                        Posisi {{ formatTanggal(branch?.tanggal) }} · pukul {{ formatJam(branch?.jam) }}
                        <span v-if="branch?.jam_sebelum !== null && branch?.jam_sebelum !== undefined"> · H-1 pukul {{ formatJam(branch.jam_sebelum) }}</span>
                    </p>
                </div>

                <div class="max-h-[680px] overflow-auto">
                    <table class="min-w-full border-separate border-spacing-0 text-xs">
                        <thead class="sticky top-0 z-10 bg-slate-100/95 backdrop-blur">
                            <tr>
                                <th class="w-12 border-b border-slate-200 px-3 py-3 text-center font-bold text-slate-500">#</th>
                                <th
                                    v-for="kolom in KOLOM"
                                    :key="kolom.key"
                                    class="cursor-pointer select-none border-b border-slate-200 px-3 py-3 text-[10px] font-extrabold uppercase tracking-wide text-slate-500"
                                    :class="kolom.kelas"
                                    @click="sort.urutkanKolom(kolom.key)"
                                >
                                    {{ kolom.label }}
                                    <SortArrow :arah="sort.arahUntuk(kolom.key)" />
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(baris, index) in barisTerurut" :key="baris.id" class="group hover:bg-brand-50/40">
                                <td class="border-b border-slate-100 px-3 py-3 text-center font-medium text-slate-400">{{ index + 1 }}</td>
                                <td class="border-b border-slate-100 px-3 py-3 font-semibold text-slate-800">{{ baris.nama }}</td>
                                <td class="border-b border-slate-100 px-3 py-3 text-right font-semibold tabular-nums text-slate-700">{{ formatAngka(baris.saldo_bulan_lalu) }}</td>
                                <td class="border-b border-slate-100 px-3 py-3 text-right font-bold tabular-nums text-slate-900">{{ formatAngka(baris.nilai) }}</td>
                                <td class="border-b border-slate-100 px-3 py-3 text-right font-semibold tabular-nums text-slate-700">{{ formatAngka(baris.target) }}</td>
                                <td class="border-b border-slate-100 px-3 py-3 text-right">
                                    <span class="rounded-lg px-2 py-1 font-bold tabular-nums" :class="pctBadgeCls(baris.pencapaian)">{{ formatPct(baris.pencapaian) }}</span>
                                </td>
                                <td v-for="jenis in ['h1', 'dtd', 'mtd', 'ytd', 'yoy']" :key="jenis" class="border-b border-slate-100 px-3 py-3 text-right tabular-nums">
                                    <p class="font-bold" :class="deltaCls(baris.delta?.[jenis]?.nilai)">{{ formatDelta(baris.delta?.[jenis]?.nilai) }}</p>
                                    <p class="mt-0.5 text-[10px] font-semibold" :class="deltaCls(baris.delta?.[jenis]?.nilai)">{{ formatDeltaPct(baris.delta?.[jenis]?.persen) }}</p>
                                </td>
                            </tr>
                            <tr v-if="!barisTerurut.length">
                                <td colspan="11" class="px-4 py-12 text-center text-sm text-slate-400">Tidak ada data untuk filter ini.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Total DPK + chart -->
            <section class="relative grid overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 lg:grid-cols-[430px_1fr]">
                <LoadingOverlay :show="memuat.kartu || memuat.chart" :senyap="senyap" />
                <div class="relative overflow-hidden bg-gradient-to-br from-[#0960d2] to-[#0348ad] p-6 text-white">
                    <div class="pointer-events-none absolute -right-16 -top-16 h-52 w-52 rounded-full bg-white/10" />
                    <p class="relative text-xs font-bold uppercase tracking-[0.16em] text-white/80">Dana Pihak Ketiga</p>
                    <p class="relative mt-2 text-4xl font-extrabold tabular-nums">{{ formatAngka(kartu('total')?.nilai) }}</p>
                    <p class="relative mt-1 text-sm text-white/75">Posisi {{ formatTanggal(snapshot?.tanggal) }} · pukul {{ formatJam(snapshot?.jam) }}</p>

                    <div class="relative mt-5 grid grid-cols-[1fr_auto] items-center gap-4">
                        <div>
                            <p class="text-[10px] font-semibold uppercase text-white/60">RKA</p>
                            <p class="mt-1 text-xl font-bold tabular-nums">{{ formatAngka(kartu('total')?.target) }}</p>
                        </div>
                        <span class="rounded-xl bg-emerald-100 px-3 py-2 text-sm font-extrabold text-emerald-700">Penc {{ formatPct(kartu('total')?.pencapaian) }}</span>
                    </div>

                    <div class="relative mt-6 grid grid-cols-4 gap-2 border-t border-white/20 pt-4">
                        <div v-for="jenis in ['h1', 'dtd', 'mtd', 'ytd']" :key="jenis" class="text-center">
                            <p class="text-[10px] font-semibold uppercase text-white/60">{{ jenis === 'h1' ? 'H-1' : jenis.toUpperCase() }}</p>
                            <p class="mt-1 text-xs font-bold tabular-nums">{{ formatDelta(kartu('total')?.delta?.[jenis]?.nilai) }}</p>
                            <p class="mt-0.5 text-[10px] font-semibold text-white/75">{{ formatDeltaPct(kartu('total')?.delta?.[jenis]?.persen) }}</p>
                        </div>
                    </div>
                </div>

                <div class="p-5">
                    <div class="flex items-center justify-between">
                        <h3 class="text-xs font-extrabold uppercase tracking-wide text-slate-500">Kurva Intraday — Dana Pihak Ketiga</h3>
                        <span class="rounded-lg border border-brand-200 bg-brand-50 px-2 py-1 text-xs font-bold text-brand-700">{{ formatAngka(kartu('total')?.nilai) }}</span>
                    </div>
                    <div class="mt-3 h-64">
                        <LineChart v-if="totalChart" :labels="totalChart.labels" :datasets="totalChart.datasets" label-sumbu="Jam " :tampilkan-legenda="false" />
                        <div v-else class="flex h-full items-center justify-center text-sm text-slate-400">Tidak ada data intraday.</div>
                    </div>
                </div>
            </section>

            <!-- Produk -->
            <section class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <article
                    v-for="produk in [
                        { key: 'tabungan', chart: tabunganChart, warna: 'text-emerald-500', bg: 'bg-emerald-50' },
                        { key: 'giro', chart: giroChart, warna: 'text-amber-500', bg: 'bg-amber-50' },
                        { key: 'deposito', chart: depositoChart, warna: 'text-violet-500', bg: 'bg-violet-50' },
                    ]"
                    :key="produk.key"
                    class="relative overflow-hidden rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200"
                >
                    <LoadingOverlay :show="memuat.kartu || memuat.chart" :senyap="senyap" />
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wide text-slate-400">{{ kartu(produk.key)?.judul }}</p>
                            <p class="mt-2 text-3xl font-extrabold tabular-nums" :class="produk.warna">{{ formatAngka(kartu(produk.key)?.nilai) }}</p>
                            <p class="mt-1 text-xs text-slate-500">pukul {{ formatJam(snapshot?.jam) }}</p>
                        </div>
                        <div class="rounded-xl bg-slate-50 px-4 py-3 text-right ring-1 ring-slate-200">
                            <p class="text-[10px] font-semibold uppercase text-slate-400">RKA</p>
                            <p class="mt-1 text-sm font-bold tabular-nums text-slate-700">{{ formatAngka(kartu(produk.key)?.target) }}</p>
                            <p class="mt-1 text-xs font-extrabold" :class="kartu(produk.key)?.pencapaian >= 100 ? 'text-emerald-600' : 'text-rose-500'">Penc {{ formatPct(kartu(produk.key)?.pencapaian) }}</p>
                        </div>
                    </div>

                    <div class="mt-4 h-36">
                        <LineChart v-if="produk.chart" :labels="produk.chart.labels" :datasets="produk.chart.datasets" label-sumbu="Jam " :tampilkan-legenda="false" />
                        <div v-else class="flex h-full items-center justify-center text-xs text-slate-400">Tidak ada data.</div>
                    </div>

                    <div class="mt-4 grid grid-cols-4 gap-2 border-t border-slate-100 pt-3">
                        <div v-for="jenis in ['h1', 'dtd', 'mtd', 'ytd']" :key="jenis" class="text-center">
                            <p class="text-[9px] font-bold uppercase text-slate-400">{{ jenis === 'h1' ? 'H-1' : jenis.toUpperCase() }}</p>
                            <p class="mt-1 text-[11px] font-extrabold tabular-nums" :class="deltaCls(kartu(produk.key)?.delta?.[jenis]?.nilai)">{{ formatDelta(kartu(produk.key)?.delta?.[jenis]?.nilai) }}</p>
                            <p class="mt-0.5 text-[9px] font-semibold" :class="deltaCls(kartu(produk.key)?.delta?.[jenis]?.nilai)">{{ formatDeltaPct(kartu(produk.key)?.delta?.[jenis]?.persen) }}</p>
                        </div>
                    </div>
                </article>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
