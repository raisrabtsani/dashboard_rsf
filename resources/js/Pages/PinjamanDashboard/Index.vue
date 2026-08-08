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
    fetchChartSegmen,
    fetchFilterOptions,
    fetchProduk,
    fetchSnapshot,
    fetchUker,
} from '@/services/pinjamanApi';
import { formatAngka, formatDelta, formatDeltaPct, formatPct } from '@/utils/formatAngka';
import { deltaCls, pctBadgeClsArah } from '@/utils/pencapaian';
import { useScope } from '@/utils/scope';
import { useTableSort } from '@/utils/useTableSort';
import { warnaBulan } from '@/utils/chartColors';

const props = defineProps({
    tanggalAwal: { type: String, required: true },
    tabAwal: { type: String, default: 'total' },
    filterAwal: { type: Object, default: () => ({}) },
});

const scope = useScope();

const KUALITAS = [
    {
        key: 'total',
        label: 'Total Pinjaman',
        chartLabel: 'Total Pinjaman',
        warna: 'from-[#075fc8] via-[#0758bf] to-[#034da9]',
        inverse: false,
    },
    {
        key: 'sml',
        label: 'SML',
        chartLabel: 'SML',
        warna: 'from-[#0966cf] via-[#0960c8] to-[#0755b7]',
        inverse: true,
    },
    {
        key: 'npl',
        label: 'NPL',
        chartLabel: 'NPL',
        warna: 'from-[#1680dd] via-[#1478d3] to-[#0d68c3]',
        inverse: true,
    },
];

const DELTA_FALLBACK = [
    { key: 'dtd', label: 'D-1' },
    { key: 'mtd', label: 'MTD' },
    { key: 'ytd', label: 'YTD' },
    { key: 'yoy', label: 'YOY' },
];

const pending = reactive({
    area_id: props.filterAwal.area_id ?? null,
    cabang_id: props.filterAwal.cabang_id ?? null,
    uker_id: props.filterAwal.uker_id ?? null,
    produk: props.filterAwal.produk ?? null,
    segmentasi: props.filterAwal.segmentasi ?? null,
    tanggal: props.tanggalAwal,
});
const applied = reactive({ ...pending });

const dirty = computed(() =>
    Object.keys(applied).some((key) => (pending[key] ?? null) !== (applied[key] ?? null)),
);

const opsi = reactive({ area: [], cabang: [], uker: [], produk: [], segmentasi: [], periode: [] });
const snap = reactive({ total: null, sml: null, npl: null });
const grafik = reactive({ total: null, sml: null, npl: null });
const grafikSegmen = reactive({ total: null, sml: null, npl: null });
const produkData = reactive({ total: null, sml: null, npl: null });
const branchData = reactive({
    total: { grouping: 'cabang', baris: [] },
    sml: { grouping: 'cabang', baris: [] },
    npl: { grouping: 'cabang', baris: [] },
});

const memuat = reactive({ kartu: false, chart: false, segmen: false, produk: false, tabel: false });
const rankingFilter = reactive({ area_id: null, segmentasi: null });
const tabTrendSegmen = ref('total');
const tabProduk = ref('total');
const tabCabang = ref(props.tabAwal ?? 'total');
const sort = useTableSort('nilai', 'desc');

const heroKartu = (q) => (snap[q]?.kartu ?? []).find((k) => k.key === 'total') ?? null;
const kartuSegmen = (q, segmen) =>
    (snap[q]?.kartu ?? []).find((k) => String(k.key).toLowerCase() === String(segmen).toLowerCase()) ?? null;

const labelDelta = (q) => snap[q]?.label_delta ?? DELTA_FALLBACK;
const deltaList = (kartu, q) =>
    labelDelta(q).map((item) => ({
        ...item,
        nilai: kartu?.delta?.[item.key]?.nilai ?? null,
        persen: kartu?.delta?.[item.key]?.persen ?? null,
    }));

const pencapaianProgress = (q) => {
    const nilai = Number(heroKartu(q)?.pencapaian ?? 0);
    return Math.min(100, Math.max(0, Number.isFinite(nilai) ? nilai : 0));
};

function heroDeltaCls(nilai, inverse = false) {
    if (nilai === null || nilai === undefined || Number.isNaN(Number(nilai)) || Number(nilai) === 0) {
        return 'text-white/75';
    }

    const naik = Number(nilai) > 0;
    const baik = inverse ? !naik : naik;

    return baik
        ? 'text-emerald-300'
        : 'text-rose-300';
}

const segmenList = computed(() => {
    const total = (snap.total?.kartu ?? []).filter((k) => k.key !== 'total');

    return total.map((kartu) => ({
        segmen: kartu.key,
        total: kartu,
        sml: kartuSegmen('sml', kartu.key),
        npl: kartuSegmen('npl', kartu.key),
    }));
});

function rasioKualitas(segmen, key) {
    const total = Number(segmen?.total?.nilai ?? 0);
    const nilai = Number(segmen?.[key]?.nilai ?? 0);
    return total > 0 ? (nilai / total) * 100 : null;
}

function periodeTrend() {
    const tanggal = new Date(`${applied.tanggal}T00:00:00`);
    const tahun = tanggal.getFullYear();
    const bulan = tanggal.getMonth() + 1;
    const hasil = [{ tahun: tahun - 1, bulan: 12 }];

    // Samakan pola DPK: tampilkan Desember tahun sebelumnya + lima bulan
    // terakhir (termasuk bulan posisi), dengan deduplikasi lintas tahun.
    for (let mundur = 4; mundur >= 0; mundur -= 1) {
        const periode = new Date(tahun, bulan - 1 - mundur, 1);
        const item = { tahun: periode.getFullYear(), bulan: periode.getMonth() + 1 };
        if (!hasil.some((p) => p.tahun === item.tahun && p.bulan === item.bulan)) hasil.push(item);
    }

    return hasil;
}

function buildDataset(seri) {
    const sumber = seri ?? [];
    if (!sumber.length) return null;

    const periode = periodeTrend();
    const labels = Array.from({ length: 31 }, (_, i) => String(i + 1));
    const warnaTrend = ['#7c8ea6', '#5f95ff', '#31c48d', '#f0ad32', '#ff6b6b', '#14b8d4'];
    const namaBulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Ags', 'Sep', 'Okt', 'Nov', 'Des'];

    return {
        labels,
        datasets: periode
            .map((p, idx) => {
                const dataSeri = sumber.find((s) => Number(s.tahun) === p.tahun && Number(s.bulan) === p.bulan);
                if (!dataSeri) return null;

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
            })
            .filter(Boolean),
    };
}

const chartQ = (q) => buildDataset(grafik[q]?.seri);

const WARNA_TREND = {
    total: '#ef4444',
    sml: '#f59e0b',
    npl: '#e11d48',
};

function chartSegmenData(segmen) {
    const sumber = grafikSegmen[tabTrendSegmen.value]?.seri ?? [];
    const dataSegmen = sumber.find(
        (item) => String(item.segmen).toLowerCase() === String(segmen).toLowerCase(),
    );

    if (!dataSegmen?.seri?.length) return null;

    const periode = periodeTrend();
    const labels = Array.from({ length: 31 }, (_, i) => String(i + 1));
    const warnaTrend = ['#7c8ea6', '#5f95ff', '#31c48d', '#f0ad32', '#ff6b6b', '#14b8d4'];
    const namaBulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Ags', 'Sep', 'Okt', 'Nov', 'Des'];

    return {
        labels,
        datasets: periode
            .map((p, idx) => {
                const dataSeri = dataSegmen.seri.find(
                    (s) => Number(s.tahun) === p.tahun && Number(s.bulan) === p.bulan,
                );
                if (!dataSeri) return null;

                const terakhir = idx === periode.length - 1;
                const warna = warnaTrend[idx] ?? WARNA_TREND[tabTrendSegmen.value];

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
            })
            .filter(Boolean),
    };
}

const PRODUK_TABS = [
    { key: 'total', label: 'Total' },
    { key: 'sml', label: 'SML' },
    { key: 'sml_pct', label: '%SML' },
    { key: 'npl', label: 'NPL' },
    { key: 'npl_pct', label: '%NPL' },
];

// %SML/%NPL memakai data kualitasnya, dibagi Total di sisi klien.
const produkQualityKey = computed(() =>
    tabProduk.value === 'sml_pct' ? 'sml' : tabProduk.value === 'npl_pct' ? 'npl' : tabProduk.value,
);
const produkRatio = computed(() => ['sml_pct', 'npl_pct'].includes(tabProduk.value));
const produkInverse = computed(() => tabProduk.value !== 'total');
const produkLabelDelta = computed(() => produkData[produkQualityKey.value]?.label_delta ?? DELTA_FALLBACK);
const produkTanggal = computed(() => produkData.total?.tanggal ?? null);

function rasioBaris(baris, totalBaris) {
    const totalNilai = Number(totalBaris?.nilai ?? 0);
    const totalTarget = Number(totalBaris?.target ?? 0);
    const nilai = totalNilai > 0 ? (Number(baris?.nilai ?? 0) / totalNilai) * 100 : null;
    const target = totalTarget > 0 ? (Number(baris?.target ?? 0) / totalTarget) * 100 : null;

    return {
        ...baris,
        nilai,
        target,
        pencapaian: target > 0 ? (nilai / target) * 100 : null,
        gap: nilai !== null && target !== null ? nilai - target : null,
    };
}

const produkKelompok = computed(() => {
    const sumber = produkData[produkQualityKey.value]?.kelompok ?? [];

    if (!produkRatio.value) return sumber;

    // Mode rasio: bagi nilai/target kualitas dengan baris Total yang sepadan.
    const totalMap = new Map((produkData.total?.kelompok ?? []).map((k) => [k.segmen, k]));

    return sumber.map((k) => {
        const totalK = totalMap.get(k.segmen);
        const totalProdukMap = new Map((totalK?.produk ?? []).map((p) => [p.segmentasi, p]));

        return {
            segmen: k.segmen,
            total: rasioBaris(k.total, totalK?.total),
            produk: (k.produk ?? []).map((p) => rasioBaris(p, totalProdukMap.get(p.segmentasi))),
        };
    });
});

function produkNilai(nilai) {
    return produkRatio.value ? formatPct(nilai) : formatAngka(nilai);
}

function produkGap(nilai) {
    return produkRatio.value ? formatDeltaPct(nilai) : formatDelta(nilai);
}

const CABANG_TABS = [
    { key: 'total', label: 'Total' },
    { key: 'sml', label: 'SML' },
    { key: 'npl', label: 'NPL' },
    { key: 'sml_pct', label: 'SML %' },
    { key: 'npl_pct', label: 'NPL %' },
];

const branch = computed(() => {
    if (['total', 'sml', 'npl'].includes(tabCabang.value)) {
        return branchData[tabCabang.value];
    }

    const qualityKey = tabCabang.value === 'sml_pct' ? 'sml' : 'npl';
    const totalRows = branchData.total?.baris ?? [];
    const qualityMap = new Map((branchData[qualityKey]?.baris ?? []).map((row) => [String(row.id), row]));

    return {
        tanggal: branchData.total?.tanggal,
        tanggal_referensi: branchData.total?.tanggal_referensi ?? {},
        label_delta: branchData.total?.label_delta ?? DELTA_FALLBACK,
        grouping: branchData.total?.grouping ?? 'cabang',
        baris: totalRows.map((total) => {
            const quality = qualityMap.get(String(total.id));
            const nilai = Number(total.nilai ?? 0) > 0
                ? (Number(quality?.nilai ?? 0) / Number(total.nilai)) * 100
                : null;
            const target = Number(total.target ?? 0) > 0
                ? (Number(quality?.target ?? 0) / Number(total.target)) * 100
                : null;

            return {
                ...total,
                nilai,
                target,
                pencapaian: target > 0 ? (nilai / target) * 100 : null,
                gap: nilai !== null && target !== null ? nilai - target : null,
                dtd: rasioDeltaCabang(total, quality, 'dtd'),
                mtd: rasioDeltaCabang(total, quality, 'mtd'),
                ytd: rasioDeltaCabang(total, quality, 'ytd'),
                yoy: rasioDeltaCabang(total, quality, 'yoy'),
                ratio: true,
            };
        }),
    };
});

const cabangInverse = computed(() => tabCabang.value !== 'total');
const branchRatio = computed(() => ['sml_pct', 'npl_pct'].includes(tabCabang.value));
const barisTerurut = computed(() => sort.urutkan(branch.value?.baris ?? []));

const KOLOM = [
    { key: 'nama', label: 'Nama Cabang', kelas: 'text-left', border: 'border-blue-500' },
    { key: 'nilai', label: 'Actual', kelas: 'text-right', border: 'border-sky-400' },
    { key: 'target', label: 'Target', kelas: 'text-right', border: 'border-violet-400' },
    { key: 'pencapaian', label: 'Penc %', kelas: 'text-right', border: 'border-emerald-400' },
    { key: 'gap', label: 'Gap', kelas: 'text-right', border: 'border-amber-400' },
];

function formatBranchNilai(nilai) {
    return branchRatio.value ? formatPct(nilai) : formatAngka(nilai);
}

function formatBranchGap(nilai) {
    return branchRatio.value ? formatDeltaPct(nilai) : formatDelta(nilai);
}

function deskripsiEntitas(baris) {
    const rincian = [];
    if (branch.value?.grouping === 'uker' && baris?.cabang) rincian.push(`Cabang: ${baris.cabang}`);
    rincian.push(baris?.area_head ? `Area Head: ${baris.area_head}` : 'Area Head belum dipetakan');

    return rincian.join(' • ');
}

function nilaiDeltaCabang(baris, key) {
    const data = baris?.[key];
    if (data && typeof data === 'object') return data.nilai ?? null;
    return data ?? null;
}

function persenDeltaCabang(baris, key) {
    const data = baris?.[key];
    return data && typeof data === 'object' ? data.persen ?? null : null;
}

function rasioDeltaCabang(total, quality, key) {
    const totalSekarang = Number(total?.nilai ?? 0);
    const kualitasSekarang = Number(quality?.nilai ?? 0);
    const deltaTotal = nilaiDeltaCabang(total, key);
    const deltaKualitas = quality ? nilaiDeltaCabang(quality, key) : 0;

    if (deltaTotal === null || deltaTotal === undefined || deltaKualitas === null || deltaKualitas === undefined) {
        return { nilai: null, persen: null };
    }

    const totalPembanding = totalSekarang - Number(deltaTotal);
    const kualitasPembanding = kualitasSekarang - Number(deltaKualitas);
    const rasioSekarang = totalSekarang !== 0 ? (kualitasSekarang / totalSekarang) * 100 : null;
    const rasioPembanding = totalPembanding !== 0 ? (kualitasPembanding / totalPembanding) * 100 : null;

    if (rasioSekarang === null || rasioPembanding === null) {
        return { nilai: null, persen: null };
    }

    const selisih = rasioSekarang - rasioPembanding;

    return {
        nilai: selisih,
        persen: rasioPembanding !== 0 ? (selisih / Math.abs(rasioPembanding)) * 100 : null,
    };
}

const cabangDeltaColumns = computed(() => branch.value?.label_delta ?? DELTA_FALLBACK);

function simbolDelta(nilai) {
    if (nilai === null || nilai === undefined || Number.isNaN(Number(nilai))) return '';
    if (Number(nilai) > 0) return '▲';
    if (Number(nilai) < 0) return '▼';
    return '•';
}

function teksPersenDeltaCabang(baris, key) {
    const persen = persenDeltaCabang(baris, key);
    return persen === null || persen === undefined
        ? '–'
        : `${simbolDelta(persen)} ${formatDeltaPct(persen)}`;
}

const BULAN_ID = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
function tanggalPanjang(iso) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso ?? '');
    const hari = m ? String(Number(m[3])).padStart(2, '0') : '';
    return m ? `${hari} ${BULAN_ID[Number(m[2]) - 1]} ${m[1]}` : iso;
}

function tanggalTanpaNol(iso) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso ?? '');
    return m ? `${Number(m[3])} ${BULAN_ID[Number(m[2]) - 1]} ${m[1]}` : iso;
}

function judulDeltaCabang(delta) {
    const referensi = branch.value?.tanggal_referensi?.[delta.key];
    if (!branch.value?.tanggal || !referensi) return delta.label;

    return `${delta.label}: ${tanggalTanpaNol(branch.value.tanggal)} - ${tanggalTanpaNol(referensi)}`;
}

function namaPilihan(list, id, fallback) {
    if (id === null || id === undefined || id === '') return fallback;
    return list.find((item) => String(item.id) === String(id))?.nama ?? fallback;
}

const filterKosong = computed(() =>
    !pending.area_id &&
    !pending.cabang_id &&
    !pending.uker_id &&
    !pending.produk &&
    !pending.segmentasi &&
    pending.tanggal === props.tanggalAwal,
);

const cakupanData = computed(() => {
    let organisasi = 'Semua data Region 7 Jakarta 2';
    if (applied.uker_id) organisasi = namaPilihan(opsi.uker, applied.uker_id, 'Unit Kerja terpilih');
    else if (applied.cabang_id) organisasi = namaPilihan(opsi.cabang, applied.cabang_id, 'Cabang terpilih');
    else if (applied.area_id) organisasi = namaPilihan(opsi.area, applied.area_id, 'Area terpilih');

    const rincian = [organisasi];
    if (applied.segmentasi) rincian.push(`Segmentasi: ${applied.segmentasi}`);
    if (applied.produk) rincian.push(`Produk: ${applied.produk}`);

    return rincian.join(' • ');
});

function resetFilter() {
    pending.area_id = null;
    pending.cabang_id = null;
    pending.uker_id = null;
    pending.produk = null;
    pending.segmentasi = null;
    pending.tanggal = props.tanggalAwal;
}

const rankingFilterKosong = computed(() => !rankingFilter.area_id && !rankingFilter.segmentasi);

function resetRankingFilter() {
    rankingFilter.area_id = null;
    rankingFilter.segmentasi = null;
}

async function muatOpsi() {
    const data = await fetchFilterOptions({ area_id: applied.area_id, cabang_id: applied.cabang_id });
    opsi.area = data.area ?? [];
    opsi.cabang = data.cabang ?? [];
    opsi.uker = data.uker ?? [];
    opsi.produk = data.produk ?? [];
    opsi.segmentasi = data.segmentasi ?? [];
    opsi.periode = data.periode ?? [];
}

async function muatKartuChart() {
    memuat.kartu = true;
    memuat.chart = true;
    memuat.segmen = true;

    try {
        await Promise.all(
            KUALITAS.flatMap((q) => [
                fetchSnapshot({ ...applied, tab: q.key }).then((hasil) => (snap[q.key] = hasil)),
                fetchChart({ ...applied, tab: q.key }).then((hasil) => (grafik[q.key] = hasil)),
                fetchChartSegmen({ ...applied, tab: q.key }).then((hasil) => (grafikSegmen[q.key] = hasil)),
            ]),
        );
    } finally {
        memuat.kartu = false;
        memuat.chart = false;
        memuat.segmen = false;
    }
}

async function muatProduk() {
    memuat.produk = true;
    try {
        const [total, sml, npl] = await Promise.all([
            fetchProduk({ ...applied, tab: 'total' }),
            fetchProduk({ ...applied, tab: 'sml' }),
            fetchProduk({ ...applied, tab: 'npl' }),
        ]);

        produkData.total = total;
        produkData.sml = sml;
        produkData.npl = npl;
    } finally {
        memuat.produk = false;
    }
}

async function muatTabel() {
    if (!scope.bolehLihatRanking.value) {
        branchData.total = { grouping: 'cabang', baris: [] };
        branchData.sml = { grouping: 'cabang', baris: [] };
        branchData.npl = { grouping: 'cabang', baris: [] };
        return;
    }

    memuat.tabel = true;
    try {
        const filter = {
            ...applied,
            // Filter ranking bersifat lokal untuk tabel. Saat kosong, tabel
            // tetap mengikuti filter utama dashboard.
            area_id: rankingFilter.area_id ?? applied.area_id,
            segmentasi: rankingFilter.segmentasi ?? applied.segmentasi,
        };

        const [total, sml, npl] = await Promise.all([
            fetchBranchPencapaian({ ...filter, tab: 'total' }),
            fetchBranchPencapaian({ ...filter, tab: 'sml' }),
            fetchBranchPencapaian({ ...filter, tab: 'npl' }),
        ]);

        branchData.total = total;
        branchData.sml = sml;
        branchData.npl = npl;
    } finally {
        memuat.tabel = false;
    }
}

const muatSemua = () => Promise.all([muatKartuChart(), muatProduk(), muatTabel()]);

async function terapkan() {
    Object.assign(applied, pending);
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
    () => [rankingFilter.area_id, rankingFilter.segmentasi],
    () => muatTabel(),
);

onMounted(async () => {
    await muatOpsi();
    await muatSemua();
});
</script>

<template>
    <Head title="Pinjaman (Kredit)" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-none space-y-3 pb-4">
            <h1 class="text-lg font-extrabold uppercase tracking-tight text-brand-700 sm:text-xl">Kredit</h1>

            <!-- Filter -->
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

                <div class="grid grid-cols-2 gap-2.5 p-3 sm:grid-cols-3 xl:grid-cols-[1.05fr_1fr_1fr_1fr_1fr_1fr_auto_auto]">
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
                        <span class="filter-label">Produk</span>
                        <select v-model="pending.produk" class="filter-control">
                            <option :value="null">Semua Produk</option>
                            <option v-for="produk in opsi.produk" :key="produk" :value="produk">{{ produk }}</option>
                        </select>
                    </label>
                    <label class="block min-w-0">
                        <span class="filter-label">Segmentasi</span>
                        <select v-model="pending.segmentasi" class="filter-control">
                            <option :value="null">Semua Segmentasi</option>
                            <option v-for="segmentasi in opsi.segmentasi" :key="segmentasi" :value="segmentasi">
                                {{ segmentasi }}
                            </option>
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

            <!-- Hero dan trend -->
            <section class="grid grid-cols-1 gap-3 xl:grid-cols-[minmax(300px,0.68fr)_minmax(0,1.32fr)]">
                <div class="space-y-3">
                    <article
                        v-for="q in KUALITAS"
                        :key="q.key"
                        class="relative min-h-[176px] overflow-hidden rounded-xl bg-gradient-to-br p-4 text-white shadow-md"
                        :class="q.warna"
                    >
                        <LoadingOverlay :show="memuat.kartu" />
                        <div class="pointer-events-none absolute -right-12 -top-16 h-52 w-52 rounded-full bg-white/5" />
                        <div class="pointer-events-none absolute right-8 top-3 h-32 w-32 rounded-full border border-white/5" />

                        <div class="relative flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-[9px] font-bold uppercase tracking-[0.13em] text-white/75">{{ q.label }}</p>
                                <p class="mt-1 text-[30px] font-extrabold leading-none tabular-nums">{{ formatAngka(heroKartu(q.key)?.nilai) }}</p>
                                <p v-if="q.key !== 'total'" class="mt-1 text-[10px] font-semibold text-white/75">
                                    {{ q.label }} {{ formatPct(heroKartu(q.key)?.nilai && heroKartu('total')?.nilai ? (heroKartu(q.key).nilai / heroKartu('total').nilai) * 100 : null) }}
                                </p>
                                <p class="mt-2 text-[10px] font-semibold text-cyan-100">Posisi {{ tanggalPanjang(snap[q.key]?.tanggal) }}</p>
                            </div>

                            <div class="w-28 shrink-0 rounded-xl bg-white/10 p-2.5 text-right ring-1 ring-white/10 backdrop-blur-sm">
                                <p class="text-[8px] font-bold uppercase tracking-wide text-white/65">Target RKA</p>
                                <p class="mt-0.5 text-base font-extrabold tabular-nums">{{ formatAngka(heroKartu(q.key)?.target) }}</p>
                                <span
                                    class="mt-1.5 inline-flex rounded-md bg-white/90 px-2 py-0.5 text-[9px] font-extrabold"
                                    :class="pctBadgeClsArah(heroKartu(q.key)?.pencapaian, q.inverse)"
                                >
                                    Penc {{ formatPct(heroKartu(q.key)?.pencapaian) }}
                                </span>
                                <p
                                    class="mt-1.5 text-[9px] font-extrabold tabular-nums"
                                    :class="heroDeltaCls(heroKartu(q.key)?.gap, q.inverse)"
                                >
                                    Gap {{ formatDelta(heroKartu(q.key)?.gap) }}
                                </p>
                            </div>
                        </div>

                        <div class="relative mt-3 h-1 overflow-hidden rounded-full bg-cyan-950/20">
                            <div class="h-full rounded-full bg-cyan-200 transition-all" :style="{ width: `${pencapaianProgress(q.key)}%` }" />
                        </div>

                        <div class="relative mt-3 grid grid-cols-4 divide-x divide-white/10 border-t border-white/10 pt-2.5">
                            <div v-for="d in deltaList(heroKartu(q.key), q.key)" :key="d.key" class="px-1 text-center">
                                <p class="text-[8px] font-bold uppercase tracking-wider text-white/55">{{ d.label }}</p>
                                <p class="mt-1 text-[11px] font-extrabold tabular-nums">{{ formatDelta(d.nilai) }}</p>
                                <p
                                    class="mt-0.5 text-[9px] font-black tabular-nums"
                                    :class="heroDeltaCls(d.nilai, q.inverse)"
                                >
                                    {{ formatDeltaPct(d.persen) }}
                                </p>
                            </div>
                        </div>
                    </article>
                </div>

                <div class="space-y-3">
                    <article v-for="q in KUALITAS" :key="q.key" class="dashboard-card relative p-3.5">
                        <LoadingOverlay :show="memuat.chart" />
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="panel-title" :class="q.key === 'sml' ? 'text-amber-500' : q.key === 'npl' ? 'text-rose-500' : ''">
                                {{ q.chartLabel }}
                            </h3>
                            <span class="text-[9px] text-slate-300">Tahun {{ grafik[q.key]?.tahun ?? '' }}</span>
                        </div>
                        <div class="mt-1 h-[151px]">
                            <LineChart v-if="chartQ(q.key)" :labels="chartQ(q.key).labels" :datasets="chartQ(q.key).datasets" variant="monthly-trend" :show-last-value-tag="true" />
                            <p v-else class="pt-14 text-center text-xs text-slate-400">Tidak ada data.</p>
                        </div>
                    </article>
                </div>
            </section>

            <!-- Rincian segmen -->
            <section>
                <div class="mb-2 flex items-center justify-between">
                    <h3 class="panel-title">Rincian Per Segmen</h3>
                    <span class="text-[9px] text-slate-400">Segmen tersedia dari data terpilih</span>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-5">
                    <article v-for="s in segmenList" :key="s.segmen" class="dashboard-card relative overflow-hidden p-3">
                        <LoadingOverlay :show="memuat.kartu" />
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <h4 class="text-xs font-extrabold text-slate-700">{{ s.segmen }}</h4>
                                <p class="mt-3 text-[8px] font-bold uppercase tracking-wide text-blue-500">Total</p>
                                <p class="mt-0.5 text-xl font-extrabold tabular-nums text-blue-700">{{ formatAngka(s.total?.nilai) }}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-[8px] font-bold uppercase tracking-wide text-slate-400">Target</p>
                                <p class="text-[10px] font-bold text-slate-500">{{ formatAngka(s.total?.target) }}</p>
                                <span class="mt-1 inline-flex rounded px-1.5 py-0.5 text-[8px] font-bold" :class="pctBadgeClsArah(s.total?.pencapaian, false)">
                                    {{ formatPct(s.total?.pencapaian) }}
                                </span>
                            </div>
                        </div>

                        <div class="mt-3 grid grid-cols-4 gap-1 border-t border-slate-100 pt-2">
                            <div v-for="d in deltaList(s.total, 'total')" :key="d.key" class="text-center">
                                <p class="text-[7px] font-bold uppercase text-slate-400">{{ d.label }}</p>
                                <p class="mt-0.5 text-[8px] font-bold tabular-nums" :class="deltaCls(d.nilai)">{{ formatDelta(d.nilai) }}</p>
                            </div>
                        </div>

                        <div class="mt-3 border-t border-slate-100 pt-2">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <p class="text-[8px] font-bold uppercase text-amber-500">SML</p>
                                    <p class="mt-0.5 text-base font-extrabold tabular-nums text-amber-500">{{ formatAngka(s.sml?.nilai) }}</p>
                                </div>
                                <div class="text-right text-[8px]">
                                    <p class="font-semibold text-slate-400">SML {{ formatPct(rasioKualitas(s, 'sml')) }}</p>
                                    <p class="mt-0.5 font-bold" :class="pctBadgeClsArah(s.sml?.pencapaian, true)">Penc {{ formatPct(s.sml?.pencapaian) }}</p>
                                </div>
                            </div>
                            <div class="mt-1.5 grid grid-cols-4 gap-1">
                                <div v-for="d in deltaList(s.sml, 'sml')" :key="d.key" class="text-center">
                                    <p class="text-[7px] font-bold uppercase text-slate-400">{{ d.label }}</p>
                                    <p class="text-[8px] font-bold" :class="deltaCls(d.nilai, true)">{{ formatDelta(d.nilai) }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="mt-2 border-t border-slate-100 pt-2">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <p class="text-[8px] font-bold uppercase text-rose-500">NPL</p>
                                    <p class="mt-0.5 text-base font-extrabold tabular-nums text-rose-500">{{ formatAngka(s.npl?.nilai) }}</p>
                                </div>
                                <div class="text-right text-[8px]">
                                    <p class="font-semibold text-slate-400">NPL {{ formatPct(rasioKualitas(s, 'npl')) }}</p>
                                    <p class="mt-0.5 font-bold" :class="pctBadgeClsArah(s.npl?.pencapaian, true)">Penc {{ formatPct(s.npl?.pencapaian) }}</p>
                                </div>
                            </div>
                            <div class="mt-1.5 grid grid-cols-4 gap-1">
                                <div v-for="d in deltaList(s.npl, 'npl')" :key="d.key" class="text-center">
                                    <p class="text-[7px] font-bold uppercase text-slate-400">{{ d.label }}</p>
                                    <p class="text-[8px] font-bold" :class="deltaCls(d.nilai, true)">{{ formatDelta(d.nilai) }}</p>
                                </div>
                            </div>
                        </div>
                    </article>
                </div>
            </section>

            <!-- Trend segmen -->
            <section>
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="panel-title">Trend Per Segmen</h3>
                    <div class="inline-flex rounded-lg bg-white p-0.5 shadow-sm ring-1 ring-slate-200">
                        <button
                            v-for="q in KUALITAS"
                            :key="q.key"
                            type="button"
                            class="rounded-md px-3 py-1 text-[9px] font-bold transition"
                            :class="tabTrendSegmen === q.key ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-400 hover:text-slate-700'"
                            @click="tabTrendSegmen = q.key"
                        >
                            {{ q.key === 'total' ? 'Total' : q.label }}
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-3 xl:grid-cols-2">
                    <article v-for="s in segmenList" :key="`trend-${s.segmen}`" class="dashboard-card relative p-3.5">
                        <LoadingOverlay :show="memuat.segmen" />
                        <div class="flex items-center justify-between">
                            <h4 class="panel-title">{{ s.segmen }}</h4>
                            <span class="text-[9px] text-slate-300">{{ String(applied.tanggal).slice(0, 4) }}</span>
                        </div>
                        <div class="mt-1 h-[170px]">
                            <LineChart
                                v-if="chartSegmenData(s.segmen)"
                                :labels="chartSegmenData(s.segmen).labels"
                                :datasets="chartSegmenData(s.segmen).datasets"
                                variant="monthly-trend"
                                :show-last-value-tag="true"
                            />
                            <p v-else class="pt-16 text-center text-xs text-slate-400">Tidak ada data.</p>
                        </div>
                    </article>
                </div>
            </section>

            <!-- Rincian per produk -->
            <section class="dashboard-card relative overflow-hidden">
                <LoadingOverlay :show="memuat.produk" />
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3">
                    <div>
                        <h3 class="panel-title">Rincian Per Produk</h3>
                        <p class="mt-0.5 text-[9px] text-slate-400">Posisi {{ tanggalPanjang(produkTanggal) }}</p>
                    </div>
                    <div class="inline-flex rounded-lg bg-slate-100 p-0.5">
                        <button
                            v-for="tab in PRODUK_TABS"
                            :key="tab.key"
                            type="button"
                            class="rounded-md px-3 py-1 text-[8px] font-bold transition"
                            :class="tabProduk === tab.key ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-400 hover:text-slate-700'"
                            @click="tabProduk = tab.key"
                        >
                            {{ tab.label }}
                        </button>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-[1060px] w-full text-[9px]">
                        <thead>
                            <tr class="border-b border-slate-100 bg-slate-50/70 text-[8px] uppercase tracking-wide text-slate-400">
                                <th class="table-head text-left">Segmen</th>
                                <th class="table-head text-left">Produk</th>
                                <th class="table-head text-right">Total</th>
                                <th class="table-head text-right">Target</th>
                                <th class="table-head text-right">Penc %</th>
                                <th class="table-head text-right">Gap</th>
                                <th v-for="d in produkLabelDelta" :key="d.key" class="table-head text-right">{{ d.label }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="kelompok in produkKelompok" :key="kelompok.segmen">
                                <tr class="border-t border-blue-100 bg-blue-50/70">
                                    <td class="table-cell font-extrabold text-slate-700">{{ kelompok.segmen }}</td>
                                    <td class="table-cell font-semibold text-blue-600">Total Segmen</td>
                                    <td class="table-cell text-right font-extrabold text-blue-700">{{ produkNilai(kelompok.total?.nilai) }}</td>
                                    <td class="table-cell text-right font-semibold text-slate-500">{{ produkNilai(kelompok.total?.target) }}</td>
                                    <td class="table-cell text-right">
                                        <span class="rounded px-1.5 py-0.5 font-bold" :class="pctBadgeClsArah(kelompok.total?.pencapaian, produkInverse)">
                                            {{ formatPct(kelompok.total?.pencapaian) }}
                                        </span>
                                    </td>
                                    <td class="table-cell text-right font-bold" :class="deltaCls(kelompok.total?.gap, produkInverse)">
                                        {{ produkGap(kelompok.total?.gap) }}
                                    </td>
                                    <td
                                        v-for="d in produkLabelDelta"
                                        :key="d.key"
                                        class="table-cell text-right font-bold"
                                        :class="deltaCls(kelompok.total?.delta?.[d.key]?.nilai, produkInverse)"
                                    >
                                        {{ formatDelta(kelompok.total?.delta?.[d.key]?.nilai) }}
                                    </td>
                                </tr>
                                <tr
                                    v-for="row in kelompok.produk"
                                    :key="`${kelompok.segmen}-${row.segmentasi}`"
                                    class="border-t border-slate-100 transition hover:bg-slate-50/60"
                                >
                                    <td class="table-cell text-slate-300">—</td>
                                    <td class="table-cell pl-8 font-semibold text-slate-600">{{ row.segmentasi || 'Lainnya' }}</td>
                                    <td class="table-cell text-right font-bold text-slate-700">{{ produkNilai(row.nilai) }}</td>
                                    <td class="table-cell text-right text-slate-400">{{ produkNilai(row.target) }}</td>
                                    <td class="table-cell text-right">
                                        <span class="rounded px-1.5 py-0.5 font-bold" :class="pctBadgeClsArah(row.pencapaian, produkInverse)">
                                            {{ formatPct(row.pencapaian) }}
                                        </span>
                                    </td>
                                    <td class="table-cell text-right font-semibold" :class="deltaCls(row.gap, produkInverse)">
                                        {{ produkGap(row.gap) }}
                                    </td>
                                    <td
                                        v-for="d in produkLabelDelta"
                                        :key="d.key"
                                        class="table-cell text-right font-semibold"
                                        :class="deltaCls(row.delta?.[d.key]?.nilai, produkInverse)"
                                    >
                                        {{ formatDelta(row.delta?.[d.key]?.nilai) }}
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="!produkKelompok.length">
                                <td colspan="10" class="px-3 py-8 text-center text-xs text-slate-400">Tidak ada data.</td>
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
                            Kinerja Kredit {{ branch.grouping === 'uker' ? 'Unit Kerja' : 'Cabang' }}
                        </h3>
                        <p class="mt-0.5 text-[9px] text-slate-400">Posisi {{ tanggalPanjang(branch?.tanggal) }}</p>
                    </div>
                    <div class="flex flex-wrap items-end gap-2.5">
                        <label class="block">
                            <span class="mini-filter-label">Area Head</span>
                            <select
                                v-model="rankingFilter.area_id"
                                class="mini-filter-control"
                                :disabled="Boolean(applied.cabang_id || applied.uker_id)"
                            >
                                <option :value="null">Semua Area Head</option>
                                <option v-for="a in opsi.area" :key="a.id" :value="a.id">{{ a.nama }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="mini-filter-label">Segmentasi</span>
                            <select v-model="rankingFilter.segmentasi" class="mini-filter-control">
                                <option :value="null">Semua Segmentasi</option>
                                <option v-for="segmentasi in opsi.segmentasi" :key="segmentasi" :value="segmentasi">
                                    {{ segmentasi }}
                                </option>
                            </select>
                        </label>
                        <button
                            type="button"
                            class="inline-flex h-10 items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-4 text-xs font-bold text-slate-600 shadow-sm transition hover:border-slate-400 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
                            :disabled="rankingFilterKosong"
                            @click="resetRankingFilter"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path d="M4 5v4h4M16 15v-4h-4" />
                                <path d="M5.6 14.4A7 7 0 0115 5.7M14.4 5.6A7 7 0 015 14.3" />
                            </svg>
                            Reset
                        </button>
                        <div class="inline-flex h-8 items-center rounded-lg bg-slate-100 p-0.5">
                            <button
                                v-for="tab in CABANG_TABS"
                                :key="tab.key"
                                type="button"
                                class="h-7 rounded-md px-2.5 text-[8px] font-bold transition"
                                :class="tabCabang === tab.key ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-400 hover:text-slate-700'"
                                @click="tabCabang = tab.key"
                            >
                                {{ tab.label }}
                            </button>
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-[1120px] w-full text-[9px]">
                        <thead>
                            <tr class="border-b border-slate-100 bg-slate-50/60 text-[8px] uppercase tracking-wide text-slate-400">
                                <th class="w-10 px-3 py-2 text-center font-semibold">#</th>
                                <th
                                    v-for="k in KOLOM"
                                    :key="k.key"
                                    class="cursor-pointer select-none border-t-2 px-3 py-2 font-semibold"
                                    :class="[k.kelas, k.border]"
                                    @click="sort.urutkanKolom(k.key)"
                                >
                                    {{ k.label }} <SortArrow :arah="sort.arahUntuk(k.key)" />
                                </th>
                                <th
                                    v-for="d in cabangDeltaColumns"
                                    :key="d.key"
                                    class="border-t-2 border-fuchsia-300 px-3 py-2 text-right font-semibold"
                                    :title="judulDeltaCabang(d)"
                                >
                                    {{ d.label }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="(b, i) in barisTerurut" :key="b.id" class="transition hover:bg-blue-50/30">
                                <td class="px-3 py-2 text-center text-slate-400">{{ i + 1 }}</td>
                                <td class="px-3 py-2">
                                    <p class="font-bold text-slate-700">{{ b.nama }}</p>
                                    <p class="mt-0.5 text-[8px] font-medium text-blue-500">{{ deskripsiEntitas(b) }}</p>
                                </td>
                                <td class="px-3 py-2 text-right font-bold tabular-nums text-slate-700">{{ formatBranchNilai(b.nilai) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-slate-500">{{ formatBranchNilai(b.target) }}</td>
                                <td class="px-3 py-2 text-right">
                                    <span class="rounded-md px-1.5 py-0.5 font-bold tabular-nums" :class="pctBadgeClsArah(b.pencapaian, cabangInverse)">
                                        {{ formatPct(b.pencapaian) }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-right font-bold tabular-nums" :class="deltaCls(b.gap, cabangInverse)">
                                    {{ formatBranchGap(b.gap) }}
                                </td>
                                <td v-for="d in cabangDeltaColumns" :key="d.key" class="px-3 py-2 text-right tabular-nums">
                                    <span class="block font-bold" :class="deltaCls(nilaiDeltaCabang(b, d.key), cabangInverse)">
                                        {{ branchRatio ? formatDeltaPct(nilaiDeltaCabang(b, d.key)) : formatDelta(nilaiDeltaCabang(b, d.key)) }}
                                    </span>
                                    <span class="block text-[8px] font-semibold" :class="deltaCls(nilaiDeltaCabang(b, d.key), cabangInverse)">
                                        {{ teksPersenDeltaCabang(b, d.key) }}
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="!barisTerurut.length">
                                <td colspan="10" class="px-3 py-8 text-center text-xs text-slate-400">Tidak ada data.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p v-if="branch.grouping === 'cabang'" class="border-t border-slate-100 px-4 py-2 text-[9px] text-slate-400">
                    Segmen Menengah dikelola level Region dan tidak tampil sebagai baris cabang.
                </p>
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
    font-size: 0.66rem;
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
    background-color: #ffffff;
    padding-left: 0.7rem;
    padding-right: 1.8rem;
    font-size: 0.7rem;
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
    height: 2.5rem;
    border-radius: 0.75rem;
    border-color: #cbd5e1;
    background-color: #ffffff;
    padding-left: 0.8rem;
    padding-right: 2rem;
    font-size: 0.75rem;
    line-height: 1rem;
    font-weight: 700;
    color: #1e293b;
    box-shadow: 0 1px 3px rgb(15 23 42 / 0.08);
    transition: border-color 150ms ease, box-shadow 150ms ease;
}

.mini-filter-control:hover {
    border-color: #94a3b8;
}

.table-head {
    padding: 0.55rem 0.75rem;
    font-weight: 700;
    white-space: nowrap;
}

.table-cell {
    padding: 0.58rem 0.75rem;
    white-space: nowrap;
}

@media (max-width: 639px) {
    .filter-control {
        font-size: 0.66rem;
    }

    .mini-filter-control {
        width: 9.75rem;
    }
}
</style>
