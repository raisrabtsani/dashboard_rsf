<script setup>
import { formatAngka, formatDelta, formatDeltaPct, formatPct } from '@/utils/formatAngka';
import { deltaCls, pctBadgeClsArah } from '@/utils/pencapaian';
import { computed } from 'vue';

/**
 * Kartu KPI baku: nilai posisi + baris delta + pencapaian vs RKA.
 *
 * Dipakai ulang SEMUA domain dashboard — jangan bikin varian per halaman.
 * Semua nilai uang masuk dalam satuan juta (lihat utils/formatAngka.js).
 */
const props = defineProps({
    judul: { type: String, required: true },
    nilai: { type: Number, default: null },
    /** { dtd: {nilai, persen}, mtd: {...}, ytd: {...}, yoy|mom: {...} } */
    delta: { type: Object, default: () => ({}) },
    target: { type: Number, default: null },
    pencapaian: { type: Number, default: null },
    gap: { type: Number, default: null },
    /**
     * Susunan baris delta, bila domain memakai kolom yang berbeda.
     * Mis. Pinjaman tab SML/NPL mengganti YoY dengan "Date to Date" (MoM).
     * Dikirim backend lewat `label_delta` supaya label & key tidak pernah
     * berbeda antara yang dihitung dan yang ditampilkan.
     */
    labelDelta: { type: Array, default: null },
    /** KPI yang makin KECIL makin baik (SML, NPL). Membalik semua pewarnaan. */
    inverse: { type: Boolean, default: false },
});

// Label UI "D-1", tapi key datanya tetap `dtd` — jangan diganti.
const BARIS_DEFAULT = [
    { key: 'dtd', label: 'D-1' },
    { key: 'mtd', label: 'MTD' },
    { key: 'ytd', label: 'YTD' },
    { key: 'yoy', label: 'YoY' },
];

const deltaBaris = computed(() =>
    (props.labelDelta ?? BARIS_DEFAULT).map((d) => ({
        ...d,
        nilai: props.delta?.[d.key]?.nilai ?? null,
        persen: props.delta?.[d.key]?.persen ?? null,
    })),
);
</script>

<template>
    <div class="rounded-lg bg-white p-4 shadow ring-1 ring-gray-100">
        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">
            {{ judul }}
        </p>

        <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">
            {{ formatAngka(nilai) }}
        </p>

        <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-1">
            <div
                v-for="d in deltaBaris"
                :key="d.key"
                class="flex items-baseline justify-between gap-1"
            >
                <dt class="text-[11px] font-medium text-gray-400">
                    {{ d.label }}
                </dt>
                <dd
                    class="text-[11px] font-semibold tabular-nums"
                    :class="deltaCls(d.nilai, inverse)"
                    :title="`${formatDelta(d.nilai)} (${formatDeltaPct(d.persen)})`"
                >
                    {{ formatDelta(d.nilai) }}
                </dd>
            </div>
        </dl>

        <div class="mt-3 flex items-center justify-between border-t border-gray-100 pt-2">
            <div class="text-[11px] text-gray-500">
                <span class="block">RKA {{ formatAngka(target) }}</span>
                <span class="block" :class="deltaCls(gap, inverse)">
                    Gap {{ formatDelta(gap) }}
                </span>
            </div>

            <span
                class="rounded-full px-2 py-1 text-xs font-semibold tabular-nums"
                :class="pctBadgeClsArah(pencapaian, inverse)"
            >
                {{ formatPct(pencapaian) }}
            </span>
        </div>
    </div>
</template>
