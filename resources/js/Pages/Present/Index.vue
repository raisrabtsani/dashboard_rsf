<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import LoadingOverlay from '@/Components/LoadingOverlay.vue';
import LineChart from '@/Components/LineChart.vue';
import PresentCard from '@/Components/PresentCard.vue';
import PresentDetailTable from '@/Components/PresentDetailTable.vue';
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import { fetchArea, fetchDetail, fetchOverview } from '@/services/presentApi';
import { formatAngka, formatDelta, formatPct } from '@/utils/formatAngka';

const props = defineProps({
    tanggalAwal: { type: String, required: true },
    tanggalTersedia: { type: Array, default: () => [] },
});

const tanggal = ref(props.tanggalAwal);
const overview = ref(null);
const area = ref(null);
const detail = ref(null);
const memuat = ref(false);

const kartuRegion = computed(() => overview.value?.kartu ?? []);
const rasio = computed(() => overview.value?.rasio ?? []);
const blokArea = computed(() => (area.value?.area ?? []).filter((item) => {
    const nama = String(item?.nama ?? '').trim();
    return nama !== '' && !/\b(?:kanwil|region)\b/i.test(nama);
}));
const tabelDetail = computed(() => detail.value?.tabel ?? []);
const trend = computed(() => overview.value?.trend ?? {});

const NAMA_BULAN = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
const NAMA_BULAN_PENDEK = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Ags', 'Sep', 'Okt', 'Nov', 'Des'];

const tanggalLabel = computed(() => {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(tanggal.value);
    return m ? `${Number(m[3])} ${NAMA_BULAN[Number(m[2]) - 1]} ${m[1]}` : tanggal.value;
});

const opsiTanggal = computed(() => {
    const daftar = [tanggal.value, ...props.tanggalTersedia].filter(Boolean);
    return [...new Set(daftar)].sort((a, b) => b.localeCompare(a));
});

function formatTanggalOpsi(value) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
    return m ? `${Number(m[3])} ${NAMA_BULAN_PENDEK[Number(m[2]) - 1]} ${m[1]}` : value;
}

const inverse = (key) => ['sml', 'npl'].includes(key);
const cardByKey = (key) => kartuRegion.value.find((item) => item.key === key) ?? null;

const dpkCard = computed(() => cardByKey('dpk'));
const dpkProduk = computed(() => {
    const peta = new Map((dpkCard.value?.rincian ?? []).map((item) => [String(item.label).toLowerCase(), item]));
    return [
        { key: 'tabungan', label: 'Tabungan', ...(peta.get('tabungan') ?? {}) },
        { key: 'giro', label: 'Giro', ...(peta.get('giro') ?? {}) },
        { key: 'deposito', label: 'Deposito', ...(peta.get('deposito') ?? {}) },
    ];
});

const chartCards = computed(() => [
    { key: 'pinjaman', judul: 'Total Pinjaman', warna: '#2d7cff', kartu: cardByKey('pinjaman') },
    { key: 'sml', judul: 'SML', warna: '#f59e0b', kartu: cardByKey('sml') },
    { key: 'npl', judul: 'NPL', warna: '#ef4444', kartu: cardByKey('npl') },
]);

function periodeTrend() {
    const posisi = new Date(`${tanggal.value}T00:00:00`);
    const tahun = posisi.getFullYear();
    const bulan = posisi.getMonth() + 1;
    const hasil = [{ tahun: tahun - 1, bulan: 12 }];

    for (let mundur = 4; mundur >= 0; mundur -= 1) {
        const periode = new Date(tahun, bulan - 1 - mundur, 1);
        const item = { tahun: periode.getFullYear(), bulan: periode.getMonth() + 1 };
        if (!hasil.some((p) => p.tahun === item.tahun && p.bulan === item.bulan)) hasil.push(item);
    }

    return hasil;
}

function buildTrendChart(seri) {
    const sumber = seri ?? [];
    if (!sumber.length) return null;

    const periode = periodeTrend();
    const labels = Array.from({ length: 31 }, (_, i) => String(i + 1));
    const warnaTrend = ['#7c8ea6', '#5f95ff', '#31c48d', '#f0ad32', '#ff6b6b', '#14b8d4'];

    return {
        labels,
        datasets: periode.map((p, idx) => {
            const dataSeri = sumber.find((s) => Number(s.tahun) === p.tahun && Number(s.bulan) === p.bulan);
            const terakhir = idx === periode.length - 1;
            const warna = warnaTrend[idx] ?? '#14b8d4';

            return {
                label: `${NAMA_BULAN_PENDEK[p.bulan - 1]} ${p.tahun}`,
                borderColor: warna,
                backgroundColor: warna,
                data: labels.map((hari) => dataSeri?.titik?.find((t) => t.hari === Number(hari))?.nilai ?? null),
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

const chartMap = computed(() => ({
    dpk: buildTrendChart(trend.value?.dpk?.seri),
    dpkTabungan: buildTrendChart(trend.value?.dpk?.seri_produk?.tabungan),
    dpkGiro: buildTrendChart(trend.value?.dpk?.seri_produk?.giro),
    pinjaman: buildTrendChart(trend.value?.pinjaman?.seri),
    sml: buildTrendChart(trend.value?.sml?.seri),
    npl: buildTrendChart(trend.value?.npl?.seri),
    recovery: buildTrendChart(trend.value?.recovery?.seri),
}));

async function muat() {
    memuat.value = true;
    try {
        const filter = { tanggal: tanggal.value };
        [overview.value, area.value, detail.value] = await Promise.all([
            fetchOverview(filter),
            fetchArea(filter),
            fetchDetail(filter),
        ]);
    } finally {
        memuat.value = false;
    }
}

onMounted(muat);
</script>

<template>
    <Head title="Present RSF" />

    <AuthenticatedLayout>
        <div class="relative -mx-4 bg-[#f4f8ff] px-4 py-4 sm:-mx-6 sm:px-6">
            <div class="mx-auto max-w-[1800px] space-y-4 pb-8">
                <LoadingOverlay :show="memuat" />

                <section class="relative overflow-hidden rounded-[20px] border border-[#2a73d6] bg-gradient-to-r from-[#0654bf] via-[#0a66d3] to-[#1f7ce0] px-4 py-4 text-white shadow-[0_14px_34px_rgba(10,82,181,0.25)] sm:px-5">
                    <div class="pointer-events-none absolute inset-0 opacity-[0.08]" style="background-image: linear-gradient(135deg, transparent 0 46%, rgba(255,255,255,.45) 47% 48%, transparent 49% 100%); background-size: 140px 140px;" />
                    <div class="pointer-events-none absolute -right-10 -top-10 h-40 w-40 rounded-full bg-white/10" />
                    <div class="relative flex items-center">
                        <div class="flex items-center gap-3">
                            <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl bg-white/10 p-2 shadow-[inset_0_1px_0_rgba(255,255,255,0.2)] ring-1 ring-white/20">
                                <img src="/overview-logo.png" alt="RSF" class="h-full w-full object-contain" />
                            </div>
                            <div>
                                <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-cyan-100/85">Highlight Kinerja</p>
                                <h1 class="mt-1 text-2xl font-black leading-none tracking-tight sm:text-[2rem]">Region 7 Jakarta 2</h1>
                                <div class="mt-2 inline-flex items-center gap-2 rounded-xl bg-white/12 p-1 pl-3 ring-1 ring-white/15 backdrop-blur">
                                    <label for="present-tanggal" class="text-[10px] font-black uppercase tracking-[0.08em] text-white">Posisi</label>
                                    <div class="relative">
                                        <select
                                            id="present-tanggal"
                                            v-model="tanggal"
                                            class="h-8 min-w-[145px] appearance-none rounded-lg border border-white/20 bg-white/10 py-1 pl-3 pr-8 text-xs font-bold text-white shadow-sm focus:border-cyan-200 focus:ring-cyan-200"
                                            aria-label="Pilih tanggal posisi"
                                            @change="muat"
                                        >
                                            <option
                                                v-for="opsi in opsiTanggal"
                                                :key="opsi"
                                                :value="opsi"
                                                class="bg-white text-slate-800"
                                            >
                                                {{ formatTanggalOpsi(opsi) }}
                                            </option>
                                        </select>
                                        <svg class="pointer-events-none absolute right-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-white" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                            <path d="m6 8 4 4 4-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="space-y-3">
                    <div class="grid grid-cols-1 gap-2 md:grid-cols-2 xl:grid-cols-5">
                        <PresentCard
                            v-for="k in kartuRegion"
                            :key="`region-${k.key}`"
                            :metric-key="k.key"
                            :judul="k.judul"
                            :nilai="k.nilai"
                            :delta="k.delta"
                            :target="k.target"
                            :pencapaian="k.pencapaian"
                            :gap="k.gap"
                            :per="k.per"
                            :inverse="inverse(k.key)"
                            :rincian="k.rincian ?? []"
                            :rasio="k.rasio ?? null"
                            :rasio-detail="k.rasio_detail ?? null"
                        />
                    </div>

                    <div class="grid grid-cols-1 gap-2 lg:grid-cols-2">
                        <div
                            v-for="r in rasio"
                            :key="r.key"
                            class="relative overflow-hidden rounded-[16px] bg-gradient-to-r from-[#0a56be] to-[#0a6bd5] px-4 py-3 text-white shadow-[0_10px_24px_rgba(11,93,196,0.22)]"
                        >
                            <span class="pointer-events-none absolute -right-5 -top-10 h-28 w-28 rounded-full bg-white/10" />
                            <div class="relative flex flex-col justify-between gap-3 sm:flex-row sm:items-end">
                                <div>
                                    <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-white/70">{{ r.judul }}</p>
                                    <p class="mt-1 text-[2.15rem] font-black leading-none tabular-nums">{{ formatPct(r.nilai) }}</p>
                                    <p class="mt-2 text-xs font-medium text-white/75">{{ r.deskripsi }}</p>
                                </div>
                                <div class="rounded-xl bg-white/12 px-3 py-2 text-left ring-1 ring-white/15 sm:text-right">
                                    <p class="text-[10px] font-semibold uppercase text-white/60">Komposisi</p>
                                    <p class="mt-1 text-sm font-black tabular-nums">{{ formatAngka(r.pembilang) }} / {{ formatAngka(r.penyebut) }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section v-if="blokArea.length" class="space-y-2">
                    <div
                        v-for="a in blokArea"
                        :key="a.area_id"
                        class="rounded-[18px] border border-[#2b72d4] bg-gradient-to-r from-[#0a57c0] via-[#0e66cf] to-[#1670d6] p-2.5 shadow-[0_12px_26px_rgba(10,87,192,0.18)]"
                    >
                        <div class="mb-2 flex items-center justify-between gap-3 px-1 text-white">
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-cyan-100/80">Area Head</p>
                                <h3 class="text-sm font-black uppercase tracking-wide">{{ a.nama }}</h3>
                            </div>
                            <span class="rounded-lg bg-white/12 px-2.5 py-1 text-[10px] font-semibold ring-1 ring-white/15">Posisi {{ tanggalLabel }}</span>
                        </div>
                        <div class="grid grid-cols-1 gap-2 md:grid-cols-2 xl:grid-cols-5">
                            <PresentCard
                                v-for="k in a.kartu"
                                :key="`${a.area_id}-${k.key}`"
                                :metric-key="k.key"
                                :judul="k.judul"
                                :nilai="k.nilai"
                                :delta="k.delta"
                                :target="k.target"
                                :pencapaian="k.pencapaian"
                                :gap="k.gap"
                                :per="k.per"
                                :inverse="inverse(k.key)"
                                :rincian="k.rincian ?? []"
                                :rasio="k.rasio ?? null"
                                :rasio-detail="k.rasio_detail ?? null"
                                compact
                            />
                        </div>
                    </div>
                </section>

                <section class="space-y-3">
                    <div class="rounded-[18px] border border-slate-200 bg-white p-3 shadow-[0_8px_24px_rgba(15,23,42,0.06)]">
                        <div class="mb-2 flex items-center justify-between gap-3">
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-slate-400">Trend Kinerja</p>
                                <h3 class="text-sm font-black uppercase tracking-wide text-[#0756bd]">Dana Pihak Ketiga</h3>
                            </div>
                            <span class="rounded-full bg-blue-50 px-3 py-1 text-[10px] font-semibold text-blue-700 ring-1 ring-blue-100">6 periode terakhir</span>
                        </div>

                        <div class="grid grid-cols-1 gap-3 xl:grid-cols-[minmax(330px,0.82fr)_minmax(0,1.28fr)]">
                            <div v-if="dpkCard" class="relative self-start overflow-hidden rounded-xl bg-gradient-to-br from-[#1264ce] via-[#0757c6] to-[#0049ad] p-4 text-white shadow-md">
                                <div class="pointer-events-none absolute -right-16 -top-20 h-64 w-64 rounded-full bg-white/5" />
                                <div class="pointer-events-none absolute right-12 top-2 h-40 w-40 rounded-full border border-white/5" />

                                <div class="relative flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-white/75">Total Dana Pihak Ketiga</p>
                                        <p class="mt-1 text-3xl font-extrabold leading-none tabular-nums sm:text-[34px]">{{ formatAngka(dpkCard.nilai) }}</p>
                                        <p class="mt-2 text-xs font-semibold text-cyan-100">Posisi {{ tanggalLabel }}</p>
                                    </div>

                                    <div class="w-36 shrink-0 rounded-xl bg-white/10 p-3 text-right ring-1 ring-white/10 backdrop-blur-sm sm:w-40">
                                        <p class="text-[9px] font-bold uppercase tracking-wide text-white/65">RKA</p>
                                        <p class="mt-0.5 text-xl font-extrabold tabular-nums">{{ formatAngka(dpkCard.target) }}</p>
                                        <span class="mt-2 inline-flex rounded-md bg-emerald-50 px-2 py-1 text-[11px] font-extrabold text-emerald-700">
                                            Penc {{ formatPct(dpkCard.pencapaian) }}
                                        </span>
                                        <p class="mt-1.5 text-[11px] font-semibold text-white/85">Gap {{ formatDelta(dpkCard.gap) }}</p>
                                    </div>
                                </div>

                                <div class="relative mt-4 grid grid-cols-3 gap-2">
                                    <div v-for="item in dpkProduk" :key="item.key" class="rounded-lg bg-white/10 px-3 py-2 ring-1 ring-white/10">
                                        <p class="text-[9px] font-bold uppercase tracking-wide text-white/70">{{ item.label }}</p>
                                        <p class="mt-1 text-sm font-extrabold tabular-nums text-white">{{ formatAngka(item.nilai) }}</p>
                                        <p class="mt-0.5 text-[10px] font-bold text-emerald-200">{{ formatPct(item.pencapaian) }}</p>
                                    </div>
                                </div>

                                <div class="relative mt-4 grid grid-cols-2 divide-x divide-white/10 border-t border-white/10 pt-3">
                                    <div class="px-1.5 text-center first:pl-0 last:pr-0">
                                        <p class="text-[9px] font-bold uppercase tracking-wider text-white/55">MTD</p>
                                        <p class="mt-1 text-sm font-extrabold tabular-nums">{{ formatDelta(dpkCard.delta?.mtd?.nilai) }}</p>
                                        <p class="mt-0.5 text-[9px] font-semibold tabular-nums" :class="Number(dpkCard.delta?.mtd?.nilai ?? 0) >= 0 ? 'text-emerald-300' : 'text-rose-300'">
                                            {{ formatPct(dpkCard.delta?.mtd?.persen) }}
                                        </p>
                                    </div>
                                    <div class="px-1.5 text-center first:pl-0 last:pr-0">
                                        <p class="text-[9px] font-bold uppercase tracking-wider text-white/55">YTD</p>
                                        <p class="mt-1 text-sm font-extrabold tabular-nums">{{ formatDelta(dpkCard.delta?.ytd?.nilai) }}</p>
                                        <p class="mt-0.5 text-[9px] font-semibold tabular-nums" :class="Number(dpkCard.delta?.ytd?.nilai ?? 0) >= 0 ? 'text-emerald-300' : 'text-rose-300'">
                                            {{ formatPct(dpkCard.delta?.ytd?.persen) }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="rounded-[16px] border border-slate-200 p-3">
                                <div class="flex items-center justify-between gap-2">
                                    <h3 class="text-sm font-black uppercase tracking-wide text-slate-600">Total Trend</h3>
                                    <span class="text-[10px] text-slate-300">Tahun {{ trend?.tahun ?? '' }}</span>
                                </div>
                                <div class="mt-1 h-[260px]">
                                    <LineChart v-if="chartMap.dpk" :labels="chartMap.dpk.labels" :datasets="chartMap.dpk.datasets" variant="monthly-trend" :show-last-value-tag="true" />
                                    <p v-else class="pt-24 text-center text-xs text-slate-400">Tidak ada data.</p>
                                </div>
                            </div>
                        </div>

                        <div class="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-2">
                            <div class="rounded-[16px] border border-slate-200 p-3">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <h3 class="text-sm font-black uppercase tracking-wide text-slate-600">Tabungan</h3>
                                        <p class="mt-0.5 text-xs font-extrabold tabular-nums text-slate-700">{{ formatAngka(dpkProduk[0]?.nilai) }}</p>
                                    </div>
                                    <span class="rounded-full bg-slate-50 px-2 py-1 text-[9px] font-bold text-slate-500 ring-1 ring-slate-100">{{ formatPct(dpkProduk[0]?.pencapaian) }}</span>
                                </div>
                                <div class="mt-1 h-[170px]">
                                    <LineChart v-if="chartMap.dpkTabungan" :labels="chartMap.dpkTabungan.labels" :datasets="chartMap.dpkTabungan.datasets" variant="monthly-trend" :show-last-value-tag="true" />
                                    <p v-else class="pt-16 text-center text-[10px] text-slate-400">Tidak ada data.</p>
                                </div>
                            </div>

                            <div class="rounded-[16px] border border-slate-200 p-3">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <h3 class="text-sm font-black uppercase tracking-wide text-slate-600">Giro</h3>
                                        <p class="mt-0.5 text-xs font-extrabold tabular-nums text-slate-700">{{ formatAngka(dpkProduk[1]?.nilai) }}</p>
                                    </div>
                                    <span class="rounded-full bg-slate-50 px-2 py-1 text-[9px] font-bold text-slate-500 ring-1 ring-slate-100">{{ formatPct(dpkProduk[1]?.pencapaian) }}</span>
                                </div>
                                <div class="mt-1 h-[170px]">
                                    <LineChart v-if="chartMap.dpkGiro" :labels="chartMap.dpkGiro.labels" :datasets="chartMap.dpkGiro.datasets" variant="monthly-trend" :show-last-value-tag="true" />
                                    <p v-else class="pt-16 text-center text-[10px] text-slate-400">Tidak ada data.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-[18px] border border-slate-200 bg-white p-3 shadow-[0_8px_24px_rgba(15,23,42,0.06)]">
                        <div class="mb-2 flex items-center justify-between gap-3">
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-slate-400">Trend Kinerja</p>
                                <h3 class="text-sm font-black uppercase tracking-wide text-[#0756bd]">Pinjaman, SML, dan NPL</h3>
                            </div>
                            <span class="rounded-full bg-blue-50 px-3 py-1 text-[10px] font-semibold text-blue-700 ring-1 ring-blue-100">Posisi {{ tanggalLabel }}</span>
                        </div>
                        <div class="grid grid-cols-1 items-stretch gap-3 xl:grid-cols-3">
                            <div
                                v-for="c in chartCards.filter((item) => item.kartu)"
                                :key="`chart-${c.key}`"
                                class="flex h-full flex-col rounded-[16px] border border-slate-200 p-3"
                            >
                                <div class="grid h-full grid-cols-1 gap-3 lg:grid-cols-[220px_minmax(0,1fr)] xl:grid-cols-1 xl:grid-rows-[286px_190px]">
                                    <PresentCard
                                        compact
                                        stretch
                                        class="h-full"
                                        :metric-key="c.kartu.key"
                                        :judul="c.kartu.judul"
                                        :nilai="c.kartu.nilai"
                                        :delta="c.kartu.delta"
                                        :target="c.kartu.target"
                                        :pencapaian="c.kartu.pencapaian"
                                        :gap="c.kartu.gap"
                                        :per="c.kartu.per"
                                        :inverse="inverse(c.kartu.key)"
                                        :rincian="c.kartu.rincian ?? []"
                                        :rasio="c.kartu.rasio ?? null"
                                        :rasio-detail="c.kartu.rasio_detail ?? null"
                                        summary
                                    />
                                    <div class="h-[190px] rounded-[14px] border border-slate-100 p-2">
                                        <LineChart
                                            v-if="chartMap[c.key]"
                                            :labels="chartMap[c.key].labels"
                                            :datasets="chartMap[c.key].datasets"
                                            variant="monthly-trend"
                                            :show-last-value-tag="true"
                                        />
                                        <p v-else class="pt-16 text-center text-[10px] text-slate-400">Tidak ada data.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="space-y-3">
                    <PresentDetailTable
                        v-for="t in tabelDetail"
                        :key="t.key"
                        :judul="t.judul"
                        :baris="t.baris"
                        :kolom="t.kolom"
                        :tanggal="t.tanggal"
                        :inverse="t.inverse"
                    />
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
