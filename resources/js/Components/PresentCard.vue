<script setup>
import { formatAngka, formatDelta, formatDeltaPct, formatPct } from '@/utils/formatAngka';
import { pctBadgeClsArah } from '@/utils/pencapaian';
import { computed } from 'vue';

const props = defineProps({
    metricKey: { type: String, default: 'dpk' },
    judul: { type: String, required: true },
    nilai: { type: Number, default: null },
    delta: { type: Object, default: () => ({}) },
    target: { type: Number, default: null },
    pencapaian: { type: Number, default: null },
    gap: { type: Number, default: null },
    per: { type: String, default: null },
    inverse: { type: Boolean, default: false },
    rincian: { type: Array, default: () => [] },
    rasio: { type: Number, default: null },
    compact: { type: Boolean, default: false },
});

const blokDelta = computed(() => [
    { key: 'mtd', label: 'MtD', ...(props.delta?.mtd ?? {}) },
    { key: 'ytd', label: 'YtD', ...(props.delta?.ytd ?? {}) },
]);

const gradientClass = computed(() => ({
    dpk: 'from-[#0a56be] via-[#0f67d0] to-[#1481e3]',
    pinjaman: 'from-[#0b4fad] via-[#0a61c7] to-[#0d7adf]',
    sml: 'from-[#0a52b5] via-[#0f67d0] to-[#1e86e5]',
    npl: 'from-[#1a65c5] via-[#267ede] to-[#46a0ea]',
    recovery: 'from-[#0951b5] via-[#0e63ca] to-[#2582df]',
}[props.metricKey] ?? 'from-[#0a56be] via-[#0f67d0] to-[#1481e3]'));

const NAMA_BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Ags', 'Sep', 'Okt', 'Nov', 'Des'];
const perLabel = computed(() => {
    if (!props.per) return null;
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(props.per);
    return m ? `${Number(m[3])} ${NAMA_BULAN[Number(m[2]) - 1]} ${m[1]}` : props.per;
});

function deltaTone(nilai) {
    if (nilai === null || nilai === undefined || Number(nilai) === 0) return 'text-white/80';
    const baik = props.inverse ? Number(nilai) < 0 : Number(nilai) > 0;
    return baik ? 'text-emerald-200' : 'text-rose-300';
}

const detailCols = computed(() => (props.rincian?.length ?? 0) >= 3 ? 'grid-cols-3' : 'grid-cols-2');
</script>

<template>
    <article
        class="relative isolate min-w-0 overflow-hidden rounded-[16px] border border-white/10 bg-gradient-to-br text-white shadow-[0_12px_24px_rgba(9,78,173,0.2)]"
        :class="[gradientClass, compact ? 'p-3' : 'p-3.5']"
    >
        <span class="pointer-events-none absolute -right-12 -top-12 h-28 w-28 rounded-full bg-white/10" />
        <span class="pointer-events-none absolute bottom-0 left-0 h-full w-full bg-[radial-gradient(circle_at_bottom_left,rgba(255,255,255,0.08),transparent_36%)]" />

        <div class="relative">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="truncate text-[10px] font-extrabold uppercase tracking-[0.14em] text-white/90">
                        {{ judul }}
                    </p>
                    <p v-if="perLabel" class="mt-0.5 text-[10px] font-medium text-white/70">Posisi {{ perLabel }}</p>
                </div>
                <div class="shrink-0 text-right">
                    <p class="text-[8px] font-semibold uppercase tracking-wide text-white/60">RKA</p>
                    <p class="text-[11px] font-bold tabular-nums text-white">{{ formatAngka(target) }}</p>
                    <span
                        class="mt-1 inline-flex rounded-md px-1.5 py-0.5 text-[9px] font-black tabular-nums shadow-sm"
                        :class="pctBadgeClsArah(pencapaian, inverse)"
                    >
                        {{ formatPct(pencapaian) }}
                    </span>
                </div>
            </div>

            <div class="mt-2 flex items-end justify-between gap-2">
                <p class="min-w-0 truncate text-[clamp(1.6rem,2vw,2.65rem)] font-black leading-none tracking-tight tabular-nums">
                    {{ formatAngka(nilai) }}
                </p>
                <div v-if="rasio !== null" class="rounded-lg bg-white/14 px-2 py-1 text-[10px] font-black ring-1 ring-white/15">
                    {{ formatPct(rasio) }}
                </div>
            </div>

            <div v-if="rincian.length" class="mt-3 grid gap-1.5" :class="detailCols">
                <div v-for="item in rincian" :key="item.label" class="min-w-0 rounded-lg bg-white/13 px-2 py-1.5 ring-1 ring-white/10">
                    <p class="truncate text-[8px] font-bold uppercase tracking-wide text-white/65">{{ item.label }}</p>
                    <p class="mt-0.5 truncate text-[11px] font-black tabular-nums text-white">{{ formatAngka(item.nilai) }}</p>
                    <p class="truncate text-[8px] font-bold tabular-nums" :class="Number(item.pencapaian) >= 100 ? 'text-emerald-200' : 'text-amber-200'">
                        {{ formatPct(item.pencapaian) }}
                    </p>
                </div>
            </div>

            <div class="mt-3 grid grid-cols-2 gap-1.5 border-t border-white/15 pt-2.5">
                <div v-for="d in blokDelta" :key="d.key" class="rounded-lg bg-black/8 px-2 py-1.5 text-center ring-1 ring-white/8">
                    <p class="text-[8px] font-semibold uppercase tracking-wide text-white/65">{{ d.label }}</p>
                    <p class="truncate text-[10px] font-black tabular-nums" :class="deltaTone(d.nilai)">{{ formatDelta(d.nilai ?? null) }}</p>
                    <p class="truncate text-[8px] font-semibold tabular-nums text-white/70">{{ formatDeltaPct(d.persen ?? null) }}</p>
                </div>
            </div>

            <div class="mt-2 flex items-center justify-between gap-2 text-[9px]">
                <span class="font-semibold text-white/70">Gap RKA</span>
                <span class="font-black tabular-nums" :class="deltaTone(gap)">{{ formatDelta(gap) }}</span>
            </div>
        </div>
    </article>
</template>
