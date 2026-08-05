<script setup>
import ApplyButton from '@/Components/ApplyButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import KpiCard from '@/Components/KpiCard.vue';
import LoadingOverlay from '@/Components/LoadingOverlay.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import {
    fetchCabang,
    fetchFilterOptions,
    fetchRingkasan,
    fetchUker,
} from '@/services/ringkasanApi';
import { formatAngka, formatPct } from '@/utils/formatAngka';
import { useScope } from '@/utils/scope';

const props = defineProps({
    tanggalAwal: { type: String, required: true },
    filterAwal: { type: Object, default: () => ({}) },
});

const scope = useScope();

/*
 * POLA PENDING vs APPLIED (lihat SimpananDashboard/Index.vue).
 * `pending` berubah saat user mengutak-atik filter; TIDAK ada fetch.
 * `applied` hanya berubah saat tombol Terapkan ditekan — itulah yang dikirim.
 */
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

const kartu = computed(() => ringkasan.value?.kartu ?? []);
const rasio = computed(() => ringkasan.value?.rasio ?? []);

/** Format tanggal efektif kartu: 'Y-m-d' -> '5 Ags 2026'; label bulanan diteruskan. */
const NAMA_BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Ags', 'Sep', 'Okt', 'Nov', 'Des'];
function formatPer(per) {
    if (!per) return null;
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(per);
    if (!m) return per; // sudah berupa label bulan (mis. "Ags 2026")

    return `${Number(m[3])} ${NAMA_BULAN[Number(m[2]) - 1]} ${m[1]}`;
}

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
    } finally {
        memuat.value = false;
    }
}

function terapkan() {
    Object.assign(applied, pending);
    muat();
}

// Cascading Area -> Cabang -> Uker. Hanya mengganti daftar opsi, tidak fetch data.
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
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Ringkasan Keragaan RSF
            </h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <!-- Filter bar -->
                <div class="rounded-lg bg-white p-4 shadow ring-1 ring-gray-100">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        <label v-if="scope.bolehPilihArea.value" class="block">
                            <span class="text-xs font-medium text-gray-500">Area</span>
                            <select v-model="pending.area_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                <option :value="null">Semua Area</option>
                                <option v-for="a in opsi.area" :key="a.id" :value="a.id">{{ a.nama }}</option>
                            </select>
                        </label>

                        <label v-if="scope.bolehPilihCabang.value" class="block">
                            <span class="text-xs font-medium text-gray-500">Cabang (BO)</span>
                            <select v-model="pending.cabang_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                <option :value="null">Semua Cabang</option>
                                <option v-for="c in opsi.cabang" :key="c.id" :value="c.id">{{ c.nama }}</option>
                            </select>
                        </label>

                        <label v-if="scope.bolehPilihUker.value" class="block">
                            <span class="text-xs font-medium text-gray-500">Unit Kerja</span>
                            <select v-model="pending.uker_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                <option :value="null">Semua Uker</option>
                                <option v-for="u in opsi.uker" :key="u.id" :value="u.id">{{ u.nama }}</option>
                            </select>
                        </label>

                        <label class="block">
                            <span class="text-xs font-medium text-gray-500">Tanggal Posisi</span>
                            <input v-model="pending.tanggal" type="date" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                        </label>

                        <div class="flex items-end">
                            <ApplyButton :dirty="dirty" :loading="memuat" @click="terapkan" />
                        </div>
                    </div>

                    <p v-if="dirty" class="mt-2 text-xs text-amber-600">
                        Ada perubahan filter yang belum diterapkan.
                    </p>
                    <p class="mt-2 text-xs text-gray-400">
                        Tiap kartu memakai posisi terbaru yang tersedia ≤ tanggal di atas — Laba, PH & Net DG mundur otomatis ke bulan terakhir yang datanya sudah ada.
                    </p>
                </div>

                <!-- Rasio utama -->
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div
                        v-for="r in rasio"
                        :key="r.key"
                        class="rounded-lg bg-white p-4 shadow ring-1 ring-gray-100"
                    >
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            {{ r.judul }}
                        </p>
                        <p class="mt-1 text-3xl font-semibold tabular-nums text-gray-900">
                            {{ formatPct(r.nilai) }}
                        </p>
                        <p class="mt-1 text-[11px] text-gray-400">
                            {{ r.deskripsi }} · {{ formatAngka(r.pembilang) }} / {{ formatAngka(r.penyebut) }}
                        </p>
                    </div>
                </div>

                <!-- Kartu ringkas per domain -->
                <div class="relative">
                    <LoadingOverlay :show="memuat" />
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Link
                            v-for="k in kartu"
                            :key="k.key"
                            :href="route(k.route)"
                            class="group block rounded-lg ring-1 ring-transparent transition hover:ring-2 hover:ring-indigo-200"
                        >
                            <KpiCard
                                :judul="k.judul"
                                :nilai="k.nilai"
                                :delta="k.delta"
                                :label-delta="k.label_delta"
                                :target="k.target"
                                :pencapaian="k.pencapaian"
                                :gap="k.gap"
                                :inverse="k.inverse"
                                :rupiah="k.rupiah"
                                :tampilkan-target="k.tampilkan_target"
                            />
                            <p class="px-1 pt-1 text-[10px] text-gray-400">
                                <span v-if="k.per">posisi {{ formatPer(k.per) }}</span>
                                <span v-else>belum ada data</span>
                                <span class="text-indigo-400 opacity-0 transition group-hover:opacity-100"> · buka →</span>
                            </p>
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
