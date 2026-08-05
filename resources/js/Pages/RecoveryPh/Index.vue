<script setup>
import ApplyButton from '@/Components/ApplyButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ComboChart from '@/Components/ComboChart.vue';
import KpiCard from '@/Components/KpiCard.vue';
import LoadingOverlay from '@/Components/LoadingOverlay.vue';
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import {
    fetchCabang,
    fetchChart,
    fetchFilterOptions,
    fetchSnapshot,
    fetchUker,
} from '@/services/recoveryPhApi';
import { useScope } from '@/utils/scope';

const props = defineProps({
    periodeAwal: { type: String, required: true },
    modeAwal: { type: String, default: 'ph' },
    scope: { type: Array, required: true },
});

const scopeAkses = useScope();

const MODE = [
    { key: 'ph', label: 'PH', judul: 'Pinjaman Hapus Buku' },
    { key: 'netdg', label: 'NET DG', judul: 'Net Downgrade' },
];

/** Toggle mode berlaku LANGSUNG (seperti Merchant), tidak menunggu Terapkan. */
const mode = ref(props.modeAwal);

const pending = reactive({
    area_id: null,
    cabang_id: null,
    uker_id: null,
    periode: props.periodeAwal,
});

const applied = reactive({ ...pending });

const dirty = computed(() =>
    Object.keys(applied).some((k) => (pending[k] ?? null) !== (applied[k] ?? null)),
);

const opsi = reactive({ area: [], cabang: [], uker: [] });
const snapshot = ref(null);
const chart = ref(null);
const memuat = reactive({ kartu: false, chart: false });

const modeAktif = computed(() => MODE.find((m) => m.key === mode.value) ?? MODE[0]);

// PH & Net DG TIDAK punya RKA — kartu dirender tanpa blok target/pencapaian/gap.
const LABEL_DELTA = [
    { key: 'mom', label: 'MoM' },
    { key: 'yoy', label: 'YoY' },
];

const kartu = computed(() => snapshot.value?.kartu ?? []);

const seriUntuk = (key) => chart.value?.seri?.[key] ?? null;

async function muatOpsi() {
    const data = await fetchFilterOptions({ area_id: applied.area_id, cabang_id: applied.cabang_id });
    opsi.area = data.area ?? [];
    opsi.cabang = data.cabang ?? [];
    opsi.uker = data.uker ?? [];
}

async function muatKartu() {
    memuat.kartu = true;
    try {
        snapshot.value = await fetchSnapshot({ ...applied, mode: mode.value });
    } finally {
        memuat.kartu = false;
    }
}

async function muatChart() {
    memuat.chart = true;
    try {
        chart.value = await fetchChart({ ...applied, mode: mode.value });
    } finally {
        memuat.chart = false;
    }
}

const muatSemua = () => Promise.all([muatKartu(), muatChart()]);

function terapkan() {
    Object.assign(applied, pending);
    muatSemua();
}

watch(mode, () => muatSemua());

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
    <Head title="Recovery EC & PH" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                {{ modeAktif.judul }}
            </h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <!-- Toggle PH | NET DG -->
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="inline-flex rounded-lg bg-gray-100 p-1">
                        <button
                            v-for="m in MODE"
                            :key="m.key"
                            type="button"
                            class="rounded-md px-4 py-1.5 text-sm font-semibold transition"
                            :class="
                                mode === m.key
                                    ? 'bg-white text-brand-700 shadow'
                                    : 'text-gray-500 hover:text-gray-700'
                            "
                            @click="mode = m.key"
                        >
                            {{ m.label }}
                        </button>
                    </div>

                    <p class="text-xs text-gray-500">
                        Domain ini tidak punya RKA — tidak ada pencapaian maupun gap.
                    </p>
                </div>

                <!-- Filter bar -->
                <div class="rounded-lg bg-white p-4 shadow ring-1 ring-gray-100">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        <label v-if="scopeAkses.bolehPilihArea.value" class="block">
                            <span class="text-xs font-medium text-gray-500">Area</span>
                            <select v-model="pending.area_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                <option :value="null">Semua Area</option>
                                <option v-for="a in opsi.area" :key="a.id" :value="a.id">{{ a.nama }}</option>
                            </select>
                        </label>

                        <label v-if="scopeAkses.bolehPilihCabang.value" class="block">
                            <span class="text-xs font-medium text-gray-500">Cabang (BO)</span>
                            <select v-model="pending.cabang_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                <option :value="null">Semua Cabang</option>
                                <option v-for="c in opsi.cabang" :key="c.id" :value="c.id">{{ c.nama }}</option>
                            </select>
                        </label>

                        <label v-if="scopeAkses.bolehPilihUker.value" class="block">
                            <span class="text-xs font-medium text-gray-500">Unit Kerja</span>
                            <select v-model="pending.uker_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                <option :value="null">Semua Uker</option>
                                <option v-for="u in opsi.uker" :key="u.id" :value="u.id">{{ u.nama }}</option>
                            </select>
                        </label>

                        <label class="block">
                            <span class="text-xs font-medium text-gray-500">Periode (bulan posisi)</span>
                            <input v-model="pending.periode" type="date" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                        </label>

                        <div class="flex items-end">
                            <ApplyButton :dirty="dirty" :loading="memuat.kartu || memuat.chart" @click="terapkan" />
                        </div>
                    </div>

                    <p v-if="dirty" class="mt-2 text-xs text-amber-600">
                        Ada perubahan filter yang belum diterapkan.
                    </p>
                </div>

                <!-- Peringatan data -->
                <div
                    v-if="snapshot?.ph_kosong"
                    class="rounded-md bg-amber-50 p-4 text-sm text-amber-800 ring-1 ring-amber-200"
                >
                    Belum ada data PH untuk tahun {{ snapshot.tahun }}. Net DG dihitung tanpa
                    komponen hapus buku, sehingga nilainya kemungkinan understated.
                </div>

                <!-- Kartu + chart per scope -->
                <div
                    v-for="s in kartu"
                    :key="s.key"
                    class="relative grid grid-cols-1 gap-4 rounded-lg bg-white p-4 shadow ring-1 ring-gray-100 lg:grid-cols-4"
                >
                    <LoadingOverlay :show="memuat.kartu || memuat.chart" />

                    <div class="lg:col-span-1">
                        <KpiCard
                            :judul="`${modeAktif.label} — ${s.judul}`"
                            :nilai="s.nilai"
                            :delta="s.delta"
                            :label-delta="LABEL_DELTA"
                            :tampilkan-target="false"
                            :inverse="true"
                        />
                        <p class="mt-2 px-1 text-[11px] text-gray-500">
                            Akumulasi YTD:
                            <strong class="tabular-nums">{{ s.akumulasi === null ? '–' : s.akumulasi.toLocaleString('id-ID', { maximumFractionDigits: 0 }) }} Jt</strong>
                        </p>
                    </div>

                    <div class="lg:col-span-3">
                        <div class="h-64">
                            <ComboChart
                                v-if="seriUntuk(s.key)"
                                :labels="seriUntuk(s.key).label"
                                :tahun-lalu="seriUntuk(s.key).tahun_lalu"
                                :tahun-ini="seriUntuk(s.key).tahun_ini"
                            />
                            <p v-else class="pt-24 text-center text-sm text-gray-400">Tidak ada data.</p>
                        </div>
                    </div>
                </div>

                <p v-if="!kartu.length && !memuat.kartu" class="rounded-lg bg-white p-6 text-center text-sm text-gray-400 shadow">
                    Tidak ada data untuk filter ini.
                </p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
