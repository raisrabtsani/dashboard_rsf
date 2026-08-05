<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import LoadingOverlay from '@/Components/LoadingOverlay.vue';
import PresentCard from '@/Components/PresentCard.vue';
import PresentDetailTable from '@/Components/PresentDetailTable.vue';
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import { fetchArea, fetchDetail, fetchOverview } from '@/services/presentApi';
import { formatAngka, formatPct } from '@/utils/formatAngka';

const props = defineProps({
    tanggalAwal: { type: String, required: true },
});

// Halaman rapat, bukan eksplorasi: satu-satunya kontrol adalah tanggal posisi.
const tanggal = ref(props.tanggalAwal);

const overview = ref(null);
const area = ref(null);
const detail = ref(null);
const memuat = ref(false);

const kartuRegion = computed(() => overview.value?.kartu ?? []);
const rasio = computed(() => overview.value?.rasio ?? []);
const blokArea = computed(() => area.value?.area ?? []);
const tabelDetail = computed(() => detail.value?.tabel ?? []);

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
    <Head title="PRESENT — Rapat Pagi Region" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    PRESENT — Rapat Pagi Region
                </h2>
                <label class="flex items-center gap-2 text-xs text-gray-500">
                    Tanggal Posisi
                    <input
                        v-model="tanggal"
                        type="date"
                        class="rounded-md border-gray-300 text-sm"
                        @change="muat"
                    />
                </label>
            </div>
        </template>

        <div class="relative py-8">
            <LoadingOverlay :show="memuat" />

            <div class="mx-auto max-w-7xl space-y-10 px-4 sm:px-6 lg:px-8 2xl:max-w-[1600px] tv:max-w-[1840px]">
                <!-- SLIDE 1 — Overview Region -->
                <section>
                    <div class="mb-3 flex items-baseline gap-3">
                        <span class="rounded bg-brand-600 px-2 py-0.5 text-xs font-bold text-white">1</span>
                        <h3 class="text-lg font-semibold text-gray-800">Overview Region</h3>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <PresentCard
                            v-for="k in kartuRegion"
                            :key="k.key"
                            :judul="k.judul"
                            :nilai="k.nilai"
                            :delta="k.delta"
                            :target="k.target"
                            :pencapaian="k.pencapaian"
                            :gap="k.gap"
                            :per="k.per"
                        />
                    </div>

                    <!-- %CASA + %LDR -->
                    <div class="mt-4 grid grid-cols-2 gap-4 sm:max-w-2xl">
                        <div v-for="r in rasio" :key="r.key" class="present-card">
                            <p class="truncate whitespace-nowrap text-xs font-semibold uppercase tracking-wide text-gray-500">
                                {{ r.judul }}
                            </p>
                            <p class="present-nilai mt-1 font-semibold tabular-nums text-gray-900">
                                {{ formatPct(r.nilai) }}
                            </p>
                            <p class="mt-1 whitespace-nowrap text-[11px] text-gray-400">
                                {{ r.deskripsi }}
                            </p>
                            <p class="whitespace-nowrap text-[11px] text-gray-400">
                                {{ formatAngka(r.pembilang) }} / {{ formatAngka(r.penyebut) }}
                            </p>
                        </div>
                    </div>
                </section>

                <!-- SLIDE 2 — Overview per Area -->
                <section>
                    <div class="mb-3 flex items-baseline gap-3">
                        <span class="rounded bg-brand-600 px-2 py-0.5 text-xs font-bold text-white">2</span>
                        <h3 class="text-lg font-semibold text-gray-800">Overview per Area</h3>
                    </div>

                    <div class="space-y-6">
                        <div v-for="a in blokArea" :key="a.area_id">
                            <h4 class="mb-2 text-sm font-semibold text-gray-600">{{ a.nama }}</h4>
                            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                                <PresentCard
                                    v-for="k in a.kartu"
                                    :key="k.key"
                                    :judul="k.judul"
                                    :nilai="k.nilai"
                                    :delta="k.delta"
                                    :target="k.target"
                                    :pencapaian="k.pencapaian"
                                    :gap="k.gap"
                                    :per="k.per"
                                />
                            </div>
                        </div>
                        <p v-if="!blokArea.length" class="text-sm text-gray-400">Tidak ada data area.</p>
                    </div>
                </section>

                <!-- SLIDE 3 — Detail per Cabang -->
                <section>
                    <div class="mb-3 flex items-baseline gap-3">
                        <span class="rounded bg-brand-600 px-2 py-0.5 text-xs font-bold text-white">3</span>
                        <h3 class="text-lg font-semibold text-gray-800">Detail per Cabang</h3>
                    </div>

                    <div class="grid grid-cols-1 gap-6 2xl:grid-cols-2">
                        <PresentDetailTable
                            v-for="t in tabelDetail"
                            :key="t.key"
                            :judul="t.judul"
                            :baris="t.baris"
                            :tanggal="t.tanggal"
                            :inverse="t.inverse"
                        />
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
