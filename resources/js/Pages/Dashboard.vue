<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import LoadingOverlay from '@/Components/LoadingOverlay.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import {
    fetchCabang,
    fetchFilterOptions,
    fetchRingkasan,
    fetchUker,
} from '@/services/ringkasanApi';
import { fetchSnapshot as fetchSimpananSnapshot } from '@/services/simpananApi';
import { fetchSnapshot as fetchPinjamanSnapshot } from '@/services/pinjamanApi';
import { fetchSnapshot as fetchRecoverySnapshot } from '@/services/recoveryApi';
import { formatAngka, formatDelta, formatPct } from '@/utils/formatAngka';
import { deltaCls, pctBadgeClsArah } from '@/utils/pencapaian';
import { useScope } from '@/utils/scope';

const props = defineProps({
    tanggalAwal: { type: String, required: true },
    filterAwal: { type: Object, default: () => ({}) },
});

const scope = useScope();
const user = computed(() => usePage().props.auth?.user ?? null);

// Nama pada banner Overview selalu mengikuti nama akun yang sedang login.
// Nilai ini tidak mengikuti filter Area/Cabang/Unit Kerja, sehingga tetap statis
// selama sesi login user tersebut.
const namaAkunOverview = computed(() =>
    String(user.value?.name ?? 'REGIONAL STRATEGY AND FINANCE').trim() || 'REGIONAL STRATEGY AND FINANCE',
);

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
const ringkasan = ref(null);
const memuat = ref(false);
const detailSnapshot = reactive({
    simpanan: null,
    pinjamanTotal: null,
    pinjamanSml: null,
    pinjamanNpl: null,
    recovery: null,
});

const KARTU_OVERVIEW = ['simpanan', 'pinjaman', 'recovery', 'laba'];
const RASIO_OVERVIEW = ['casa', 'ldr'];
const JUDUL_KARTU = {
    simpanan: 'DANA PIHAK KETIGA',
    pinjaman: 'PINJAMAN',
    recovery: 'RECOVERY EC',
    laba: 'LABA',
};

const kartu = computed(() =>
    (ringkasan.value?.kartu ?? []).filter((item) => KARTU_OVERVIEW.includes(item.key)),
);
const rasio = computed(() =>
    (ringkasan.value?.rasio ?? []).filter((item) => RASIO_OVERVIEW.includes(item.key)),
);
const judulKartu = (item) => JUDUL_KARTU[item.key] ?? item.judul;

const userUnitKerjaBadge = computed(() => {
    const uker = opsi.uker.find((item) => Number(item.id) === Number(user.value?.uker_id));
    if (uker?.nama) return uker.nama;

    const cabang = opsi.cabang.find((item) => Number(item.id) === Number(user.value?.cabang_id));
    if (cabang?.nama) return cabang.nama;

    if (user.value?.tipe === 'RO') return 'Region 7 Jakarta 2';

    return user.value?.tipe ?? 'Unit Kerja';
});

const cakupanDataText = computed(() => {
    const uker = opsi.uker.find((item) => Number(item.id) === Number(applied.uker_id));
    if (uker?.nama) return uker.nama;

    const cabang = opsi.cabang.find((item) => Number(item.id) === Number(applied.cabang_id));
    if (cabang?.nama) return cabang.nama;

    const area = opsi.area.find((item) => Number(item.id) === Number(applied.area_id));
    if (area?.nama) return area.nama;

    return 'Semua data Region 7 Jakarta 2';
});

const BULAN_LENGKAP = [
    'Januari',
    'Februari',
    'Maret',
    'April',
    'Mei',
    'Juni',
    'Juli',
    'Agustus',
    'September',
    'Oktober',
    'November',
    'Desember',
];

const periodOptions = computed(() => {
    const akhir = new Date(`${props.tanggalAwal}T00:00:00`);
    const awal = new Date(akhir.getFullYear() - 1, 0, 1);
    const hasil = [];

    for (const tanggal = new Date(akhir); tanggal >= awal; tanggal.setDate(tanggal.getDate() - 1)) {
        const tahun = tanggal.getFullYear();
        const nomorBulan = tanggal.getMonth() + 1;
        const bulan = String(nomorBulan).padStart(2, '0');
        const hari = String(tanggal.getDate()).padStart(2, '0');

        hasil.push({
            value: `${tahun}-${bulan}-${hari}`,
            label: `${hari} ${BULAN_LENGKAP[nomorBulan - 1]} ${tahun}`,
        });
    }

    return hasil;
});

const IKON = {
    simpanan: '<circle cx="12" cy="12" r="9"/><path d="M12 7v10M9.5 9.5c0-1 1-1.6 2.5-1.6s2.5.7 2.5 1.7-1 1.5-2.5 1.9-2.5.9-2.5 1.9 1 1.7 2.5 1.7 2.5-.6 2.5-1.6"/>',
    pinjaman: '<rect x="4" y="4" width="16" height="16" rx="2"/><path d="M8 9h8M8 13h8M8 17h5"/>',
    recovery: '<circle cx="12" cy="12" r="9"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/>',
    laba: '<path d="M4 16l5-5 3 3 7-8"/><path d="M20 6v4h-4"/>',
    casa: '<path d="M12 3v9l7 3M12 3a9 9 0 100 18 9 9 0 000-18z"/>',
    ldr: '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/>',
};
const ikon = (key) => IKON[key] ?? IKON.simpanan;

const NAMA_BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Ags', 'Sep', 'Okt', 'Nov', 'Des'];
function formatPer(per) {
    if (!per) return null;
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(per);
    if (!m) return per;

    return `${Number(m[3])} ${NAMA_BULAN[Number(m[2]) - 1]} ${m[1]}`;
}

const deltaChips = (k) =>
    (k.label_delta ?? [
        { key: 'dtd', label: 'D-1' },
        { key: 'mtd', label: 'MTD' },
        { key: 'ytd', label: 'YTD' },
        { key: 'yoy', label: 'YoY' },
    ]).map((d) => ({ ...d, nilai: k.delta?.[d.key]?.nilai ?? null }));

const findSnapshotCard = (snapshot, key) =>
    (snapshot?.kartu ?? []).find((item) => String(item.key).toLowerCase() === String(key).toLowerCase()) ?? null;

const safePct = (pembilang, penyebut) => {
    const a = Number(pembilang);
    const b = Number(penyebut);

    if (!Number.isFinite(a) || !Number.isFinite(b) || b === 0) return null;

    return (a / b) * 100;
};

const domainDetailItems = (key) => {
    if (key === 'simpanan') {
        const tabungan = findSnapshotCard(detailSnapshot.simpanan, 'tabungan');
        const giro = findSnapshotCard(detailSnapshot.simpanan, 'giro');

        return [
            { label: 'TABUNGAN', nilai: tabungan?.nilai ?? null, tipe: 'uang', kelas: 'bg-blue-50 text-brand-700' },
            { label: 'GIRO', nilai: giro?.nilai ?? null, tipe: 'uang', kelas: 'bg-blue-50 text-brand-700' },
        ];
    }

    if (key === 'pinjaman') {
        const total = findSnapshotCard(detailSnapshot.pinjamanTotal, 'total')?.nilai ?? null;
        const sml = findSnapshotCard(detailSnapshot.pinjamanSml, 'total')?.nilai ?? null;
        const npl = findSnapshotCard(detailSnapshot.pinjamanNpl, 'total')?.nilai ?? null;

        return [
            { label: 'SML', nilai: safePct(sml, total), tipe: 'persen', kelas: 'bg-amber-50 text-amber-600' },
            { label: 'NPL', nilai: safePct(npl, total), tipe: 'persen', kelas: 'bg-rose-50 text-rose-600' },
        ];
    }

    if (key === 'recovery') {
        const micro = findSnapshotCard(detailSnapshot.recovery, 'micro');
        const sme = findSnapshotCard(detailSnapshot.recovery, 'sme');
        const consumer = findSnapshotCard(detailSnapshot.recovery, 'consumer');

        return [
            { label: 'MICRO', nilai: micro?.nilai ?? null, tipe: 'uang', kelas: 'bg-blue-50 text-brand-700' },
            { label: 'SME', nilai: sme?.nilai ?? null, tipe: 'uang', kelas: 'bg-blue-50 text-brand-700' },
            { label: 'CONSUMER', nilai: consumer?.nilai ?? null, tipe: 'uang', kelas: 'bg-blue-50 text-brand-700' },
        ];
    }

    return [];
};

const formatDetailValue = (item) =>
    item.tipe === 'persen' ? formatPct(item.nilai, 2) : formatAngka(item.nilai);

function ratioDelta(numerator, denominator, key) {
    const nilaiPembilang = numerator?.nilai;
    const nilaiPenyebut = denominator?.nilai;
    const deltaPembilang = numerator?.delta?.[key]?.nilai;
    const deltaPenyebut = denominator?.delta?.[key]?.nilai;
    const rasioSekarang = safePct(nilaiPembilang, nilaiPenyebut);

    if (
        rasioSekarang === null ||
        deltaPembilang === null ||
        deltaPembilang === undefined ||
        deltaPenyebut === null ||
        deltaPenyebut === undefined
    ) return null;

    const rasioSebelumnya = safePct(
        Number(nilaiPembilang) - Number(deltaPembilang),
        Number(nilaiPenyebut) - Number(deltaPenyebut),
    );

    return rasioSebelumnya === null ? null : rasioSekarang - rasioSebelumnya;
}

const ratioViews = computed(() => {
    const simpananTotal = findSnapshotCard(detailSnapshot.simpanan, 'total');
    const simpananCasa = findSnapshotCard(detailSnapshot.simpanan, 'casa');
    const pinjamanTotal = findSnapshotCard(detailSnapshot.pinjamanTotal, 'total');

    return Object.fromEntries(
        rasio.value.map((item) => {
            const numerator = item.key === 'casa' ? simpananCasa : pinjamanTotal;
            const denominator = simpananTotal;
            const nilai = safePct(numerator?.nilai, denominator?.nilai) ?? item.nilai;
            const target = safePct(numerator?.target, denominator?.target);
            const pencapaian = safePct(nilai, target);
            const per = item.key === 'casa'
                ? detailSnapshot.simpanan?.tanggal
                : (detailSnapshot.pinjamanTotal?.tanggal ?? detailSnapshot.simpanan?.tanggal);

            return [item.key, {
                ...item,
                nilai,
                target,
                pencapaian,
                per,
                delta: ['mtd', 'ytd', 'yoy'].map((key) => ({
                    key,
                    label: key.toUpperCase(),
                    nilai: ratioDelta(numerator, denominator, key),
                })),
            }];
        }),
    );
});

const formatRasioDelta = (nilai) => {
    if (nilai === null || nilai === undefined || Number.isNaN(nilai)) return '–';

    const tanda = nilai > 0 ? '+' : nilai < 0 ? '−' : '';
    return `${tanda}${new Intl.NumberFormat('id-ID', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Math.abs(nilai))}`;
};

async function muatOpsi() {
    const data = await fetchFilterOptions({ area_id: applied.area_id, cabang_id: applied.cabang_id });
    opsi.area = data.area ?? [];
    opsi.cabang = data.cabang ?? [];
    opsi.uker = data.uker ?? [];
}

async function muat() {
    memuat.value = true;
    try {
        ringkasan.value = await fetchRingkasan({ ...applied });

        const kartuMap = Object.fromEntries(
            (ringkasan.value?.kartu ?? []).map((item) => [item.key, item]),
        );
        const filterDasar = { ...applied };
        const tugas = [
            fetchSimpananSnapshot({ ...filterDasar, tanggal: kartuMap.simpanan?.per ?? applied.tanggal }),
            fetchPinjamanSnapshot({ ...filterDasar, tanggal: kartuMap.pinjaman?.per ?? applied.tanggal, tab: 'total' }),
            fetchPinjamanSnapshot({ ...filterDasar, tanggal: kartuMap.pinjaman?.per ?? applied.tanggal, tab: 'sml' }),
            fetchPinjamanSnapshot({ ...filterDasar, tanggal: kartuMap.pinjaman?.per ?? applied.tanggal, tab: 'npl' }),
            fetchRecoverySnapshot({ ...filterDasar, tanggal: kartuMap.recovery?.per ?? applied.tanggal }),
        ];
        const hasil = await Promise.allSettled(tugas);
        const nama = ['simpanan', 'pinjamanTotal', 'pinjamanSml', 'pinjamanNpl', 'recovery'];

        nama.forEach((key, index) => {
            detailSnapshot[key] = hasil[index].status === 'fulfilled' ? hasil[index].value : null;
        });
    } finally {
        memuat.value = false;
    }
}

function terapkan() {
    Object.assign(applied, pending);
    muat();
}

async function resetFilter() {
    pending.area_id = props.filterAwal.area_id ?? null;
    pending.cabang_id = props.filterAwal.cabang_id ?? null;
    pending.uker_id = props.filterAwal.uker_id ?? null;
    pending.tanggal = props.tanggalAwal;

    Object.assign(applied, pending);
    await muatOpsi();
    await muat();
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

onMounted(async () => {
    await muatOpsi();
    await muat();
});
</script>

<template>
    <Head title="Overview" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-[1400px] space-y-6">
            <!-- Banner selamat datang -->
            <div class="relative overflow-hidden rounded-[26px] bg-gradient-to-r from-brand-700 via-brand-600 to-[#5f84ea] px-6 py-6 text-white shadow-[0_12px_28px_rgba(8,87,195,0.18)] sm:px-8">
                <div class="pointer-events-none absolute -right-10 -top-10 h-52 w-52 rounded-full bg-white/10" aria-hidden="true" />
                <div class="pointer-events-none absolute inset-0 opacity-[0.06]" style="background-image: radial-gradient(circle at 1px 1px, rgba(255,255,255,0.18) 1px, transparent 0); background-size: 28px 28px;" aria-hidden="true" />
                <div class="relative flex items-center gap-5">
                    <div class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-[20px] bg-white/10 shadow-[inset_0_1px_0_rgba(255,255,255,0.12),0_8px_20px_rgba(3,37,108,0.16)] backdrop-blur">
                        <img src="/overview-logo.png" alt="RSF Region 7 Jakarta 2" class="h-14 w-14 object-contain" />
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.22em] text-white/70 sm:text-sm">Selamat datang</p>
                        <h1 class="mt-1.5 text-2xl font-extrabold uppercase leading-tight tracking-tight sm:text-3xl lg:text-[42px]">{{ namaAkunOverview }}</h1>
                        <div class="mt-3 flex flex-wrap items-center gap-3 text-sm">
                            <span class="inline-flex max-w-full items-center truncate rounded-xl bg-white/15 px-3 py-1 text-xs font-semibold text-white shadow-sm backdrop-blur sm:text-sm">
                                {{ userUnitKerjaBadge }}
                            </span>
                            <span class="inline-flex items-center gap-2 text-white/90">
                                <span class="h-2.5 w-2.5 rounded-full bg-emerald-400 shadow-[0_0_12px_rgba(52,211,153,0.8)]" />
                                Online
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter seperti mockup -->
            <section class="rounded-[22px] border border-slate-200/80 bg-white p-4 shadow-[0_8px_26px_rgba(15,23,42,0.08)] sm:p-5">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <span class="text-xs font-semibold text-slate-400">Cakupan data:</span>
                        <span class="inline-flex items-center rounded-full bg-brand-50 px-3 py-1 text-xs font-semibold text-brand-700 ring-1 ring-brand-100">{{ cakupanDataText }}</span>
                    </div>
                    <div
                        class="inline-flex items-center gap-2 self-start rounded-full border px-3 py-1.5 text-xs font-semibold transition"
                        :class="dirty
                            ? 'border-amber-200 bg-amber-50 text-amber-700'
                            : 'border-emerald-200 bg-emerald-50 text-emerald-700'"
                    >
                        <span
                            class="flex h-4 w-4 items-center justify-center rounded-full border"
                            :class="dirty
                                ? 'border-amber-300 text-amber-600'
                                : 'border-emerald-300 text-emerald-600'"
                        >
                            <svg v-if="dirty" class="h-2.5 w-2.5" viewBox="0 0 20 20" fill="currentColor"><path d="M9 4a1 1 0 112 0v6a1 1 0 11-2 0V4zm1 11a1.25 1.25 0 100 2.5A1.25 1.25 0 0010 15z" /></svg>
                            <svg v-else class="h-2.5 w-2.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0L3.3 9.7a1 1 0 111.4-1.4l3.3 3.3 6.8-6.8a1 1 0 011.4 0z" clip-rule="evenodd" /></svg>
                        </span>
                        {{ dirty ? 'Filter belum diterapkan' : 'Filter sudah diterapkan' }}
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-3 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_auto_auto]">
                    <div class="space-y-1.5">
                        <label class="block text-[11px] font-bold uppercase tracking-[0.14em] text-slate-400">Periode</label>
                        <div class="relative">
                            <select
                                v-model="pending.tanggal"
                                class="h-12 w-full appearance-none rounded-2xl border border-slate-200 bg-white px-4 pr-11 text-sm font-semibold text-slate-700 shadow-sm outline-none transition focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                            >
                                <option v-for="periode in periodOptions" :key="periode.value" :value="periode.value">
                                    {{ periode.label }}
                                </option>
                            </select>
                            <span class="pointer-events-none absolute inset-y-0 right-4 flex items-center text-slate-400">
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.3 7.3a1 1 0 011.4 0L10 10.6l3.3-3.3a1 1 0 111.4 1.4l-4 4a1 1 0 01-1.4 0l-4-4a1 1 0 010-1.4z" clip-rule="evenodd" /></svg>
                            </span>
                        </div>
                    </div>

                    <div v-if="scope.bolehPilihArea.value" class="space-y-1.5">
                        <label class="block text-[11px] font-bold uppercase tracking-[0.14em] text-slate-400">Area</label>
                        <select v-model="pending.area_id" class="h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 shadow-sm outline-none transition focus:border-brand-500 focus:ring-2 focus:ring-brand-100">
                            <option :value="null">Semua Area</option>
                            <option v-for="a in opsi.area" :key="a.id" :value="a.id">{{ a.nama }}</option>
                        </select>
                    </div>

                    <div v-if="scope.bolehPilihCabang.value" class="space-y-1.5">
                        <label class="block text-[11px] font-bold uppercase tracking-[0.14em] text-slate-400">Cabang</label>
                        <select v-model="pending.cabang_id" class="h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 shadow-sm outline-none transition focus:border-brand-500 focus:ring-2 focus:ring-brand-100">
                            <option :value="null">Semua Cabang</option>
                            <option v-for="c in opsi.cabang" :key="c.id" :value="c.id">{{ c.nama }}</option>
                        </select>
                    </div>

                    <div v-if="scope.bolehPilihUker.value" class="space-y-1.5">
                        <label class="block text-[11px] font-bold uppercase tracking-[0.14em] text-slate-400">Unit Kerja</label>
                        <select v-model="pending.uker_id" class="h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 shadow-sm outline-none transition focus:border-brand-500 focus:ring-2 focus:ring-brand-100">
                            <option :value="null">Semua Unit Kerja</option>
                            <option v-for="u in opsi.uker" :key="u.id" :value="u.id">{{ u.nama }}</option>
                        </select>
                    </div>

                    <div class="flex items-end">
                        <button
                            type="button"
                            class="group inline-flex h-12 items-center justify-center gap-2.5 rounded-2xl border border-brand-100 bg-brand-50 px-5 text-sm font-bold text-brand-700 shadow-sm transition hover:border-brand-200 hover:bg-brand-100 hover:shadow-md active:scale-[0.98]"
                            @click="resetFilter"
                        >
                            <span class="flex h-7 w-7 items-center justify-center rounded-full bg-white text-brand-600 shadow-sm ring-1 ring-brand-100 transition group-hover:-rotate-45">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M3 12a9 9 0 109-9 9.8 9.8 0 00-6.7 2.7L3 8" />
                                    <path d="M3 3v5h5" />
                                </svg>
                            </span>
                            Reset
                        </button>
                    </div>

                    <div class="flex items-end">
                        <button
                            type="button"
                            class="inline-flex h-12 min-w-[190px] items-center justify-center gap-2 rounded-2xl px-5 text-sm font-bold text-white transition active:scale-[0.98]"
                            :class="dirty
                                ? 'bg-brand-600 shadow-[0_10px_24px_rgba(8,87,195,0.32)] hover:bg-brand-700'
                                : 'cursor-default bg-[#8cc0fb] shadow-[0_10px_24px_rgba(96,165,250,0.28)]'"
                            :disabled="!dirty"
                            @click="terapkan"
                        >
                            <svg v-if="dirty" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 103.9 9.4l3.3 3.3a1 1 0 001.4-1.4l-3.3-3.3A5.5 5.5 0 009 3.5zM5.5 9a3.5 3.5 0 117 0 3.5 3.5 0 01-7 0z" clip-rule="evenodd" /></svg>
                            <svg v-else class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0L3.3 9.7a1 1 0 111.4-1.4l3.3 3.3 6.8-6.8a1 1 0 011.4 0z" clip-rule="evenodd" /></svg>
                            {{ dirty ? 'Terapkan' : 'Sudah Diterapkan' }}
                        </button>
                    </div>
                </div>
            </section>

            <!-- Grid kartu seperti referensi -->
            <div class="relative">
                <LoadingOverlay :show="memuat" />
                <div class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
                    <!-- Kartu domain -->
                    <Link
                        v-for="k in kartu"
                        :key="k.key"
                        :href="route(k.route)"
                        class="group relative flex min-h-[242px] flex-col overflow-hidden rounded-[22px] border border-slate-200/80 bg-white px-5 pb-5 pt-6 shadow-[0_8px_22px_rgba(15,23,42,0.08)] transition hover:-translate-y-0.5 hover:shadow-[0_12px_28px_rgba(15,23,42,0.12)]"
                        :class="{
                            'order-1': k.key === 'simpanan',
                            'order-2': k.key === 'pinjaman',
                            'order-3': k.key === 'recovery',
                            'order-6': k.key === 'laba',
                        }"
                    >
                        <span
                            class="absolute inset-x-0 top-0 h-1"
                            :class="k.key === 'laba' ? 'bg-gradient-to-r from-sky-400 to-cyan-300' : 'bg-gradient-to-r from-brand-700 to-blue-500'"
                        />

                        <div class="flex items-center justify-between">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-brand-600">
                                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" v-html="ikon(k.key)" />
                                </span>
                                <span class="truncate text-[15px] font-bold uppercase tracking-wide text-slate-500">{{ judulKartu(k) }}</span>
                            </div>
                            <svg class="h-5 w-5 shrink-0 text-slate-300 transition group-hover:translate-x-1 group-hover:text-brand-500" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.3 4.3a1 1 0 011.4 0l5 5a1 1 0 010 1.4l-5 5a1 1 0 01-1.4-1.4L11.6 10 7.3 5.7a1 1 0 010-1.4z" clip-rule="evenodd" /></svg>
                        </div>

                        <div class="mt-4 flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <p class="text-[42px] font-extrabold leading-none tracking-tight text-brand-700 tabular-nums">{{ formatAngka(k.nilai) }}</p>
                                <p class="mt-2 text-sm font-medium text-slate-400">{{ k.key === 'laba' ? (formatPer(k.per) ?? '—') : `Posisi ${formatPer(k.per) ?? '—'}` }}</p>
                            </div>
                            <div v-if="k.tampilkan_target !== false" class="shrink-0 pt-1 text-right">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Target</p>
                                <p class="mt-1 text-sm font-semibold tabular-nums text-slate-500">{{ formatAngka(k.target) }}</p>
                                <span
                                    v-if="k.pencapaian !== null && k.pencapaian !== undefined"
                                    class="mt-2 inline-flex rounded-xl px-3 py-1.5 text-base font-extrabold tabular-nums"
                                    :class="pctBadgeClsArah(k.pencapaian, k.inverse)"
                                >
                                    {{ formatPct(k.pencapaian, 1) }}
                                </span>
                            </div>
                        </div>

                        <div
                            v-if="domainDetailItems(k.key).length"
                            class="mt-auto grid gap-2 pt-5"
                            :class="domainDetailItems(k.key).length === 3 ? 'grid-cols-3' : 'grid-cols-2'"
                        >
                            <div
                                v-for="item in domainDetailItems(k.key)"
                                :key="item.label"
                                class="rounded-xl px-3 py-2.5"
                                :class="item.kelas"
                            >
                                <p class="text-[10px] font-bold uppercase tracking-wide opacity-90">{{ item.label }}</p>
                                <p class="mt-0.5 whitespace-nowrap text-sm font-extrabold tabular-nums">{{ formatDetailValue(item) }}</p>
                            </div>
                        </div>
                    </Link>

                    <!-- Kartu rasio -->
                    <div
                        v-for="r in rasio"
                        :key="r.key"
                        class="relative flex min-h-[242px] flex-col overflow-hidden rounded-[22px] border border-slate-200/80 bg-white px-5 pb-5 pt-6 shadow-[0_8px_22px_rgba(15,23,42,0.08)]"
                        :class="{
                            'order-4': r.key === 'casa',
                            'order-5': r.key === 'ldr',
                        }"
                    >
                        <span class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-brand-700 to-blue-500" />

                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-50 text-brand-600">
                                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" v-html="ikon(r.key)" />
                                </span>
                                <span class="text-[15px] font-bold uppercase tracking-wide text-slate-500">{{ r.judul }}</span>
                            </div>
                            <svg class="h-5 w-5 text-slate-300" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.3 4.3a1 1 0 011.4 0l5 5a1 1 0 010 1.4l-5 5a1 1 0 01-1.4-1.4L11.6 10 7.3 5.7a1 1 0 010-1.4z" clip-rule="evenodd" /></svg>
                        </div>

                        <div class="mt-4 flex items-start justify-between gap-4">
                            <div>
                                <p class="text-[42px] font-extrabold leading-none tracking-tight text-brand-700 tabular-nums">{{ formatPct(ratioViews[r.key]?.nilai, 2) }}</p>
                                <p class="mt-2 text-sm font-medium text-slate-400">Posisi {{ formatPer(ratioViews[r.key]?.per) ?? '—' }}</p>
                            </div>
                            <div class="shrink-0 pt-1 text-right">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Target</p>
                                <p class="mt-1 text-sm font-semibold tabular-nums text-slate-500">{{ formatPct(ratioViews[r.key]?.target, 2) }}</p>
                                <span
                                    v-if="ratioViews[r.key]?.pencapaian !== null && ratioViews[r.key]?.pencapaian !== undefined"
                                    class="mt-2 inline-flex rounded-xl px-3 py-1.5 text-base font-extrabold tabular-nums"
                                    :class="pctBadgeClsArah(ratioViews[r.key]?.pencapaian)"
                                >
                                    {{ formatPct(ratioViews[r.key]?.pencapaian, 1) }}
                                </span>
                            </div>
                        </div>

                        <div class="mt-auto grid grid-cols-3 gap-1.5 pt-5">
                            <div
                                v-for="d in ratioViews[r.key]?.delta ?? []"
                                :key="d.key"
                                class="rounded-xl bg-blue-50 px-2 py-2 text-center"
                            >
                                <p class="text-[9px] font-bold uppercase tracking-wide text-brand-600">{{ d.label }}</p>
                                <p class="mt-0.5 text-xs font-extrabold tabular-nums" :class="deltaCls(d.nilai)">
                                    <span v-if="d.nilai > 0">▲</span>
                                    <span v-else-if="d.nilai < 0">▼</span>
                                    {{ formatRasioDelta(d.nilai) }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
