<script setup>
import {
    formatAngka,
    formatDelta,
    formatDeltaJumlah,
    formatDeltaPct,
    formatJumlah,
    formatPct,
} from '@/utils/formatAngka';
import { deltaCls, pctBadgeClsArah } from '@/utils/pencapaian';
import { computed } from 'vue';

/**
 * Kartu KPI baku: nilai posisi + baris delta + pencapaian vs RKA.
 *
 * Dipakai ulang SEMUA domain dashboard — jangan bikin varian per halaman.
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
     * Mis. Pinjaman SML/NPL mengganti YoY dengan MoM; Laba & Merchant flow
     * menghilangkan salah satu kolom. Dikirim backend lewat `label_delta`.
     */
    labelDelta: { type: Array, default: null },
    /** KPI yang makin KECIL makin baik (SML, NPL, EDC SV Rp.0). */
    inverse: { type: Boolean, default: false },
    /**
     * KPI uang (satuan juta) vs HITUNGAN (unit/transaksi). Nilai hitungan
     * diformat tanpa skala Jt/M/T. Default true supaya domain uang tak berubah.
     */
    rupiah: { type: Boolean, default: true },
    /** Sembunyikan blok RKA/gap/pencapaian untuk KPI tanpa target. */
    tampilkanTarget: { type: Boolean, default: true },
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

const fmtNilai = (v) => (props.rupiah ? formatAngka(v) : formatJumlah(v));
const fmtDelta = (v) => (props.rupiah ? formatDelta(v) : formatDeltaJumlah(v));
</script>

<template>
    <div class="rounded-lg bg-white p-4 shadow ring-1 ring-gray-100">
        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">
            {{ judul }}
        </p>

        <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">
            {{ fmtNilai(nilai) }}
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
                    :title="`${fmtDelta(d.nilai)} (${formatDeltaPct(d.persen)})`"
                >
                    {{ fmtDelta(d.nilai) }}
                </dd>
            </div>
        </dl>

        <div
            v-if="tampilkanTarget"
            class="mt-3 flex items-center justify-between border-t border-gray-100 pt-2"
        >
            <div class="text-[11px] text-gray-500">
                <span class="block">RKA {{ fmtNilai(target) }}</span>
                <span class="block" :class="deltaCls(gap, inverse)">
                    Gap {{ fmtDelta(gap) }}
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
