<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
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
import { formatAngka, formatDelta, formatPct } from '@/utils/formatAngka';
import { deltaCls, pctBadgeClsArah } from '@/utils/pencapaian';
import { useScope } from '@/utils/scope';

const props = defineProps({
    tanggalAwal: { type: String, required: true },
    filterAwal: { type: Object, default: () => ({}) },
});

const scope = useScope();
const user = computed(() => usePage().props.auth?.user ?? null);

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
        <div class="mx-auto max-w-7xl space-y-6">
            <!-- Banner selamat datang -->
            <div class="relative overflow-hidden rounded-2xl bg-gradient-to-r from-brand-700 via-brand-600 to-brand-500 p-6 text-white shadow-lg sm:p-8">
                <div class="pointer-events-none absolute -right-10 -top-10 h-52 w-52 rounded-full bg-white/10" aria-hidden="true" />
                <div class="relative flex items-center gap-5">
                    <div class="hidden h-20 w-20 shrink-0 items-center justify-center rounded-2xl bg-white/15 shadow-inner sm:flex">
                        <ApplicationLogo class="h-11 w-11" />
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-widest text-white/70">Selamat datang</p>
                        <h1 class="mt-1 text-3xl font-extrabold tracking-tight sm:text-4xl">{{ user?.name }}</h1>
                        <div class="mt-3 flex items-center gap-2">
                            <span class="rounded-lg bg-white/15 px-2.5 py-1 text-xs font-semibold uppercase">{{ user?.tipe ?? user?.role }}</span>
                            <span class="inline-flex items-center gap-1.5 text-xs text-white/80">
                                <span class="h-2 w-2 rounded-full bg-emerald-400" /> Online
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Judul + filter -->
            <div class="flex flex-wrap items-center gap-3">
                <h2 class="text-lg font-bold uppercase tracking-wide text-slate-700">Overview</h2>
                <div class="ml-auto flex flex-wrap items-center gap-2">
                    <input v-model="pending.tanggal" type="date" class="rounded-xl border-slate-200 bg-white text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500" />
                    <select v-if="scope.bolehPilihArea.value" v-model="pending.area_id" class="rounded-xl border-slate-200 bg-white text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                        <option :value="null">Semua Area</option>
                        <option v-for="a in opsi.area" :key="a.id" :value="a.id">{{ a.nama }}</option>
                    </select>
                    <select v-if="scope.bolehPilihCabang.value" v-model="pending.cabang_id" class="rounded-xl border-slate-200 bg-white text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                        <option :value="null">Semua BO</option>
                        <option v-for="c in opsi.cabang" :key="c.id" :value="c.id">{{ c.nama }}</option>
                    </select>
                    <select v-if="scope.bolehPilihUker.value" v-model="pending.uker_id" class="rounded-xl border-slate-200 bg-white text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                        <option :value="null">Semua Uker</option>
                        <option v-for="u in opsi.uker" :key="u.id" :value="u.id">{{ u.nama }}</option>
                    </select>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700"
                        :class="{ 'ring-2 ring-amber-300': dirty }"
                        @click="terapkan"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 103.9 9.4l3.3 3.3a1 1 0 001.4-1.4l-3.3-3.3A5.5 5.5 0 009 3.5zM5.5 9a3.5 3.5 0 117 0 3.5 3.5 0 01-7 0z" clip-rule="evenodd" /></svg>
                        Terapkan
                    </button>
                </div>
            </div>

            <!-- Grid kartu -->
            <div class="relative">
                <LoadingOverlay :show="memuat" />
                <div class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
                    <!-- Kartu domain -->
                    <Link
                        v-for="k in kartu"
                        :key="k.key"
                        :href="route(k.route)"
                        class="group relative block overflow-hidden rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-100 transition hover:-translate-y-0.5 hover:shadow-md"
                    >
                        <span class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-brand-600 to-brand-400" />
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-50 text-brand-600">
                                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" v-html="ikon(k.key)" />
                                </span>
                                <span class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ k.judul }}</span>
                            </div>
                            <svg class="h-4 w-4 text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-brand-500" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.3 4.3a1 1 0 011.4 0l5 5a1 1 0 010 1.4l-5 5a1 1 0 01-1.4-1.4L11.6 10 7.3 5.7a1 1 0 010-1.4z" clip-rule="evenodd" /></svg>
                        </div>

                        <div class="mt-4 flex items-end justify-between">
                            <p class="text-3xl font-extrabold tabular-nums text-slate-900">{{ formatAngka(k.nilai) }}</p>
                            <div v-if="k.tampilkan_target !== false" class="text-right">
                                <p class="text-[10px] font-medium uppercase text-slate-400">Target</p>
                                <p class="text-xs font-semibold tabular-nums text-slate-500">{{ formatAngka(k.target) }}</p>
                            </div>
                        </div>

                        <div class="mt-1 flex items-center justify-between">
                            <p class="text-[11px] text-slate-400">posisi {{ formatPer(k.per) ?? '—' }}</p>
                            <span
                                v-if="k.tampilkan_target !== false && k.pencapaian !== null && k.pencapaian !== undefined"
                                class="rounded-lg px-2 py-0.5 text-xs font-bold tabular-nums"
                                :class="pctBadgeClsArah(k.pencapaian, k.inverse)"
                            >
                                {{ formatPct(k.pencapaian) }}
                            </span>
                        </div>

                        <div class="mt-4 grid grid-cols-4 gap-1.5 border-t border-slate-100 pt-3">
                            <div v-for="d in deltaChips(k)" :key="d.key" class="rounded-lg bg-slate-50 px-1 py-1.5 text-center">
                                <p class="text-[9px] font-semibold uppercase text-slate-400">{{ d.label }}</p>
                                <p class="text-[11px] font-bold tabular-nums" :class="deltaCls(d.nilai, k.inverse)">{{ formatDelta(d.nilai) }}</p>
                            </div>
                        </div>
                    </Link>

                    <!-- Kartu rasio (%CASA, %LDR) -->
                    <div
                        v-for="r in rasio"
                        :key="r.key"
                        class="relative overflow-hidden rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-100"
                    >
                        <span class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-emerald-500 to-teal-400" />
                        <div class="flex items-center gap-2.5">
                            <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" v-html="ikon(r.key)" />
                            </span>
                            <span class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ r.judul }}</span>
                        </div>
                        <p class="mt-4 text-3xl font-extrabold tabular-nums text-slate-900">{{ formatPct(r.nilai) }}</p>
                        <p class="mt-2 text-[11px] text-slate-400">
                            {{ r.deskripsi }}
                        </p>
                        <p class="mt-1 text-[11px] font-medium tabular-nums text-slate-500">
                            {{ formatAngka(r.pembilang) }} / {{ formatAngka(r.penyebut) }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
