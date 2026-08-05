<script setup>
import { formatAngka, formatDelta, formatDeltaPct, formatPct } from '@/utils/formatAngka';
import { deltaCls, pctBadgeClsArah } from '@/utils/pencapaian';
import { computed } from 'vue';

/**
 * Kartu KPI untuk halaman PRESENT (layar rapat / videotron).
 *
 * Aturan tampilan yang dikunci desain (lihat .present-* di app.css):
 *  - Judul ANTI-WRAP (satu baris).
 *  - Nilai & persentase pencapaian SEJAJAR pada satu baris, nilai tidak dipotong.
 *  - Dua blok delta MtD & YtD; DITUMPUK saat kartu menyempit (HP), sejajar saat lebar.
 * Ukuran teks memakai container query (cqw) sehingga terbaca di ketiga lebar.
 */
const props = defineProps({
    judul: { type: String, required: true },
    nilai: { type: Number, default: null },
    /** { mtd: {nilai, persen}, ytd: {nilai, persen} } */
    delta: { type: Object, default: () => ({}) },
    target: { type: Number, default: null },
    pencapaian: { type: Number, default: null },
    gap: { type: Number, default: null },
    /** Tanggal/periode efektif kartu (posisi terbaru yang tersedia). */
    per: { type: String, default: null },
    /** Makin kecil makin baik (tidak dipakai kartu overview, tersedia untuk kelengkapan). */
    inverse: { type: Boolean, default: false },
});

const blokDelta = computed(() => [
    { key: 'mtd', label: 'MtD', ...(props.delta?.mtd ?? {}) },
    { key: 'ytd', label: 'YtD', ...(props.delta?.ytd ?? {}) },
]);

/** 'Y-m-d' -> '5 Ags 2026'; label bulanan (mis. 'Jun 2026') diteruskan apa adanya. */
const NAMA_BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Ags', 'Sep', 'Okt', 'Nov', 'Des'];
const perLabel = computed(() => {
    if (!props.per) return null;
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(props.per);

    return m ? `${Number(m[3])} ${NAMA_BULAN[Number(m[2]) - 1]} ${m[1]}` : props.per;
});
</script>

<template>
    <div class="present-card">
        <div class="flex items-baseline justify-between gap-2">
            <!-- Judul anti-wrap; min-w-0 + truncate menjaga layout tetap utuh. -->
            <p class="min-w-0 truncate whitespace-nowrap text-xs font-semibold uppercase tracking-wide text-gray-500">
                {{ judul }}
            </p>
            <span v-if="perLabel" class="shrink-0 whitespace-nowrap text-[10px] text-gray-400">{{ perLabel }}</span>
        </div>

        <!-- Nilai & persentase SEJAJAR pada satu baris. -->
        <div class="mt-1 flex items-baseline justify-between gap-2">
            <span class="present-nilai min-w-0 font-semibold tabular-nums text-gray-900">
                {{ formatAngka(nilai) }}
            </span>
            <span
                class="present-pct shrink-0 rounded-md px-2 py-0.5 font-semibold tabular-nums"
                :class="pctBadgeClsArah(pencapaian, inverse)"
            >
                {{ formatPct(pencapaian) }}
            </span>
        </div>

        <!-- Blok MtD / YtD -->
        <div class="present-deltas mt-3">
            <div
                v-for="d in blokDelta"
                :key="d.key"
                class="rounded-md bg-gray-50 px-2 py-1"
            >
                <p class="text-[10px] font-medium uppercase text-gray-400">{{ d.label }}</p>
                <p class="whitespace-nowrap text-sm font-semibold tabular-nums" :class="deltaCls(d.nilai ?? null, inverse)">
                    {{ formatDelta(d.nilai ?? null) }}
                </p>
                <p class="whitespace-nowrap text-[10px] tabular-nums text-gray-400">
                    {{ formatDeltaPct(d.persen ?? null) }}
                </p>
            </div>
        </div>

        <!-- RKA / Gap -->
        <div class="mt-3 flex items-center justify-between border-t border-gray-100 pt-2 text-[11px] text-gray-500">
            <span class="whitespace-nowrap">RKA {{ formatAngka(target) }}</span>
            <span class="whitespace-nowrap" :class="deltaCls(gap, inverse)">Gap {{ formatDelta(gap) }}</span>
        </div>
    </div>
</template>
