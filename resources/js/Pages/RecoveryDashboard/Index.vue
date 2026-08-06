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
const memuat = reactive({ kartu: false, chart: false, tabel: false });
const sort = useTableSort('nilai', 'desc');

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

function tanggalPanjang(tanggal) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(tanggal ?? '');
    return m ? `${Number(m[3])} ${NAMA_BULAN[Number(m[2]) - 1]} ${m[1]}` : '—';
}

function tanggalMini(tanggal) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(tanggal ?? '');
    return m ? `${Number(m[3])} ${m[2]}/${m[1].slice(2)}` : '—';
}

function deltaTone(delta) {
    return deltaCls(delta?.nilai ?? null);
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
            label: `${s.nama} ${data?.tahun ?? ''}`.trim(),
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
            cabang_id: drilldown.value ?? applied.cabang_id,
        });
    } finally {
        memuat.tabel = false;
    }
}

function muatSemua() {
    return Promise.all([muatKartu(), muatCharts(), muatTabel()]);
}

function terapkan() {
    Object.assign(applied, pending);
    drilldown.value = null;
    muatSemua();
}

watch(drilldown, () => muatTabel());
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
        <div class="py-6">
            <div class="mx-auto max-w-7xl space-y-5 px-4 sm:px-6 lg:px-8">
                <div>
                    <h1 class="text-[34px] font-extrabold tracking-tight text-[#0857C3]">RECOVERY EC</h1>
                </div>

                <div class="rounded-2xl bg-white px-4 py-4 shadow-sm ring-1 ring-black/5">
                    <div class="grid grid-cols-1 gap-3 lg:grid-cols-12">
                        <label class="lg:col-span-2">
                            <span class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Tanggal</span>
                            <input v-model="pending.tanggal" type="date" :min="opsi.tanggal_min ?? undefined" :max="opsi.tanggal_maks ?? undefined" class="mt-1 h-12 w-full rounded-xl border-slate-200 text-sm" />
                        </label>
                        <label v-if="scopeAkses.bolehPilihArea.value" class="lg:col-span-2">
                            <span class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Area</span>
                            <select v-model="pending.area_id" class="mt-1 h-12 w-full rounded-xl border-slate-200 text-sm">
                                <option :value="null">Semua Area</option>
                                <option v-for="a in opsi.area" :key="a.id" :value="a.id">{{ a.nama }}</option>
                            </select>
                        </label>
                        <label v-if="scopeAkses.bolehPilihCabang.value" class="lg:col-span-3">
                            <span class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Cabang</span>
                            <select v-model="pending.cabang_id" class="mt-1 h-12 w-full rounded-xl border-slate-200 text-sm">
                                <option :value="null">Semua Cabang</option>
                                <option v-for="c in opsi.cabang" :key="c.id" :value="c.id">{{ c.nama }}</option>
                            </select>
                        </label>
                        <label v-if="scopeAkses.bolehPilihUker.value" class="lg:col-span-3">
                            <span class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Unit Kerja</span>
                            <select v-model="pending.uker_id" class="mt-1 h-12 w-full rounded-xl border-slate-200 text-sm">
                                <option :value="null">Semua Uker</option>
                                <option v-for="u in opsi.uker" :key="u.id" :value="u.id">{{ u.nama }}</option>
                            </select>
                        </label>
                        <div class="flex items-end lg:col-span-2">
                            <button
                                type="button"
                                class="h-12 w-full rounded-xl bg-[#0857C3] px-4 text-sm font-bold text-white shadow-sm transition hover:bg-[#0648a4]"
                                :disabled="memuat.kartu || memuat.chart || memuat.tabel"
                                @click="terapkan"
                            >
                                Terapkan
                            </button>
                        </div>
                    </div>
                    <p v-if="dirty" class="mt-3 text-xs text-amber-600">Ada perubahan filter yang belum diterapkan.</p>
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
                        class="grid grid-cols-1 gap-4 lg:grid-cols-[360px_minmax(0,1fr)]"
                    >
                        <div class="overflow-hidden rounded-[22px] bg-gradient-to-br from-[#0857C3] to-[#2F8BFF] text-white shadow-lg">
                            <div class="flex items-start justify-between gap-3 px-5 pt-5">
                                <div>
                                    <p class="text-xs font-extrabold uppercase tracking-[0.22em] text-white/80">{{ card.judul }}</p>
                                    <p class="mt-2 text-6xl font-extrabold leading-none tracking-tight">{{ formatAngka(card.nilai) }}</p>
                                    <p class="mt-2 text-sm text-white/80">Posisi {{ tanggalPanjang(snapshot?.tanggal) }}</p>
                                </div>
                                <div class="rounded-2xl bg-white/16 px-4 py-3 text-right backdrop-blur">
                                    <p class="text-[10px] font-bold uppercase tracking-widest text-white/75">Target RKA</p>
                                    <p class="mt-1 text-2xl font-extrabold">{{ formatAngka(card.target) }}</p>
                                    <span class="mt-2 inline-flex rounded-full bg-white px-3 py-1 text-xs font-bold text-[#0857C3]">
                                        Penc {{ formatPct(card.pencapaian, 1) }}
                                    </span>
                                </div>
                            </div>

                            <div class="mt-5 grid grid-cols-3 border-t border-white/15">
                                <div class="px-5 py-4">
                                    <p class="text-[10px] font-bold uppercase tracking-widest text-white/70">D-1</p>
                                    <p class="mt-2 text-lg font-bold" :class="deltaTone(card.delta?.dtd)">{{ formatDelta(card.delta?.dtd?.nilai) }}</p>
                                    <p class="text-xs" :class="deltaTone(card.delta?.dtd)">{{ formatDeltaPct(card.delta?.dtd?.persen) }}</p>
                                </div>
                                <div class="border-x border-white/10 px-5 py-4">
                                    <p class="text-[10px] font-bold uppercase tracking-widest text-white/70">MTD</p>
                                    <p class="mt-2 text-lg font-bold" :class="deltaTone(card.delta?.mtd)">{{ formatDelta(card.delta?.mtd?.nilai) }}</p>
                                    <p class="text-xs" :class="deltaTone(card.delta?.mtd)">{{ formatDeltaPct(card.delta?.mtd?.persen) }}</p>
                                </div>
                                <div class="px-5 py-4">
                                    <p class="text-[10px] font-bold uppercase tracking-widest text-white/70">YOY</p>
                                    <p class="mt-2 text-lg font-bold" :class="deltaTone(card.delta?.yoy)">{{ formatDelta(card.delta?.yoy?.nilai) }}</p>
                                    <p class="text-xs" :class="deltaTone(card.delta?.yoy)">{{ formatDeltaPct(card.delta?.yoy?.persen) }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-[22px] bg-white p-4 shadow-sm ring-1 ring-black/5">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-bold uppercase tracking-wide text-slate-700">{{ card.judul }}</p>
                                    <p class="mt-1 text-xs text-slate-400">Tren harian multi-bulan tahun {{ charts[String(card.key).toLowerCase()]?.tahun ?? '—' }}</p>
                                </div>
                                <div class="rounded-full bg-[#F2F7FF] px-3 py-1 text-xs font-semibold text-[#0857C3]">
                                    Gap {{ formatDelta(card.gap) }}
                                </div>
                            </div>
                            <div class="mt-4 h-64">
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

                <div v-if="scopeAkses.bolehLihatRanking.value" class="relative overflow-hidden rounded-[22px] bg-white shadow-sm ring-1 ring-black/5">
                    <LoadingOverlay :show="memuat.tabel" />

                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
                        <div>
                            <h3 class="text-sm font-extrabold uppercase tracking-wide text-slate-700">{{ judulTabel }}</h3>
                            <p class="mt-1 text-xs text-slate-400">Posisi {{ tanggalPanjang(branch?.tanggal) }}</p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <select v-model="drilldown" class="h-10 rounded-xl border-slate-200 text-sm">
                                <option :value="null">Semua BO</option>
                                <option v-for="c in opsi.cabang" :key="c.id" :value="c.id">{{ c.nama }}</option>
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
                                    <th class="cursor-pointer px-4 py-3 text-right text-[11px] font-bold uppercase tracking-wider" @click="sort.urutkanKolom('nilai')">
                                        Actual
                                        <SortArrow :arah="sort.arahUntuk('nilai')" />
                                    </th>
                                    <th class="cursor-pointer px-4 py-3 text-right text-[11px] font-bold uppercase tracking-wider" @click="sort.urutkanKolom('target')">
                                        Target
                                        <SortArrow :arah="sort.arahUntuk('target')" />
                                    </th>
                                    <th class="cursor-pointer px-4 py-3 text-right text-[11px] font-bold uppercase tracking-wider" @click="sort.urutkanKolom('pencapaian')">
                                        Penc %
                                        <SortArrow :arah="sort.arahUntuk('pencapaian')" />
                                    </th>
                                    <th class="cursor-pointer px-4 py-3 text-right text-[11px] font-bold uppercase tracking-wider" @click="sort.urutkanKolom('gap')">
                                        Gap
                                        <SortArrow :arah="sort.arahUntuk('gap')" />
                                    </th>
                                    <th class="px-4 py-3 text-right text-[11px] font-bold uppercase tracking-wider">D-1</th>
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
                                    <td class="px-4 py-3 text-right font-bold text-slate-800">{{ formatAngka(row.nilai) }}</td>
                                    <td class="px-4 py-3 text-right text-slate-500">{{ formatAngka(row.target) }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold" :class="pctBadgeCls(row.pencapaian)">
                                            {{ formatPct(row.pencapaian, 1) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right font-semibold" :class="deltaCls(row.gap)">{{ formatDelta(row.gap) }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <p class="font-semibold" :class="deltaCls(row.dtd?.nilai)">{{ formatDelta(row.dtd?.nilai) }}</p>
                                        <p class="text-xs" :class="deltaCls(row.dtd?.nilai)">{{ formatDeltaPct(row.dtd?.persen) }}</p>
                                    </td>
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
