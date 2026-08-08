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
    rasioDetail: { type: Object, default: null },
    compact: { type: Boolean, default: false },
    stretch: { type: Boolean, default: false },
    summary: { type: Boolean, default: false },
});

const blokDelta = computed(() => [
    { key: 'mtd', label: 'MtD', ...(props.delta?.mtd ?? {}) },
    { key: 'ytd', label: 'YtD', ...(props.delta?.ytd ?? {}) },
]);

const gradientClass = computed(() => ({
    dpk: 'from-[#06469e] via-[#0753af] to-[#0c58ad]',
    pinjaman: 'from-[#2b7ddd] via-[#3287e8] to-[#2d79d5]',
    sml: 'from-[#0750ae] via-[#0758bb] to-[#0a55ad]',
    npl: 'from-[#2f80de] via-[#378be9] to-[#347fd8]',
    recovery: 'from-[#063f91] via-[#084b9f] to-[#0a438e]',
}[props.metricKey] ?? 'from-[#0750ae] via-[#0f67d0] to-[#1481e3]'));

const NAMA_BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Ags', 'Sep', 'Okt', 'Nov', 'Des'];
const perLabel = computed(() => {
    if (!props.per) return null;
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(props.per);
    return m ? `${Number(m[3])} ${NAMA_BULAN[Number(m[2]) - 1]} ${m[1]}` : props.per;
});

const detailItems = computed(() => {
    if (props.rasioDetail) return [{ ...props.rasioDetail, mode: 'ratio' }];
    return (props.rincian ?? []).map((item) => ({ ...item, mode: 'amount' }));
});

function deltaTone(nilai, inverse = props.inverse) {
    if (nilai === null || nilai === undefined || Number(nilai) === 0) return 'text-white/80';
    const baik = inverse ? Number(nilai) < 0 : Number(nilai) > 0;
    return baik ? 'text-emerald-200' : 'text-rose-300';
}

function detailValue(item, key) {
    const value = item?.[key];
    return item?.mode === 'ratio' ? formatPct(value) : formatAngka(value);
}

function detailDelta(item, key) {
    const value = item?.delta?.[key]?.nilai;
    return item?.mode === 'ratio' ? formatDeltaPct(value) : formatDelta(value);
}

function detailGap(item) {
    return item?.mode === 'ratio' ? formatDeltaPct(item?.gap) : formatDelta(item?.gap);
}
</script>

<template>
    <article
        class="relative isolate min-w-0 overflow-hidden rounded-[16px] border border-white/10 bg-gradient-to-br text-white shadow-[0_12px_24px_rgba(9,78,173,0.2)]"
        :class="[
            gradientClass,
            compact ? 'p-3' : 'p-3.5',
            stretch ? 'h-full' : '',
            !summary && (compact ? 'min-h-[390px]' : 'min-h-[470px]'),
        ]"
    >
        <span class="pointer-events-none absolute -right-12 -top-12 h-28 w-28 rounded-full bg-white/10" />
        <span class="pointer-events-none absolute bottom-0 left-0 h-full w-full bg-[radial-gradient(circle_at_bottom_left,rgba(255,255,255,0.08),transparent_36%)]" />

        <!-- Ringkasan kecil dipakai pada blok Trend agar tinggi grafik tetap sejajar. -->
        <div v-if="summary" class="relative" :class="stretch ? 'flex h-full flex-col' : ''">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="truncate text-[10px] font-extrabold uppercase tracking-[0.14em] text-white/90">{{ judul }}</p>
                    <p v-if="perLabel" class="mt-0.5 text-[10px] font-medium text-white/70">Posisi {{ perLabel }}</p>
                </div>
                <div class="shrink-0 text-right">
                    <p class="text-[8px] font-semibold uppercase tracking-wide text-white/60">RKA</p>
                    <p class="text-[11px] font-bold tabular-nums text-white">{{ formatAngka(target) }}</p>
                    <span class="mt-1 inline-flex rounded-md px-1.5 py-0.5 text-[9px] font-black tabular-nums shadow-sm" :class="pctBadgeClsArah(pencapaian, inverse)">
                        {{ formatPct(pencapaian) }}
                    </span>
                </div>
            </div>

            <div class="mt-2 flex items-end justify-between gap-2">
                <p class="min-w-0 truncate text-[clamp(1.6rem,2vw,2.65rem)] font-black leading-none tracking-tight tabular-nums">{{ formatAngka(nilai) }}</p>
                <div v-if="rasio !== null" class="rounded-lg bg-white/14 px-2 py-1 text-[10px] font-black ring-1 ring-white/15">{{ formatPct(rasio) }}</div>
            </div>

            <div v-if="rincian.length" class="mt-3 grid gap-1.5" :class="rincian.length >= 3 ? 'grid-cols-3' : 'grid-cols-2'">
                <div v-for="item in rincian" :key="item.label" class="min-w-0 rounded-lg bg-white/13 px-2 py-1.5 ring-1 ring-white/10">
                    <p class="truncate text-[8px] font-bold uppercase tracking-wide text-white/65">{{ item.label }}</p>
                    <p class="mt-0.5 truncate text-[11px] font-black tabular-nums text-white">{{ formatAngka(item.nilai) }}</p>
                    <p class="truncate text-[8px] font-bold tabular-nums" :class="Number(item.pencapaian) >= 100 ? 'text-emerald-200' : 'text-amber-200'">{{ formatPct(item.pencapaian) }}</p>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-1.5 border-t border-white/15 pt-2.5" :class="stretch ? 'mt-auto' : 'mt-3'">
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

        <!-- Tampilan utama mengikuti kartu RSF: nilai, RKA/Penc, Gap, MtD/YtD, lalu rincian. -->
        <div v-else class="relative flex h-full flex-col">
            <p class="truncate font-extrabold uppercase tracking-[0.03em] text-white" :class="compact ? 'text-[11px]' : 'text-sm'">{{ judul }}</p>
            <p class="mt-1 font-black leading-none tracking-tight tabular-nums" :class="compact ? 'text-[1.85rem]' : 'text-[2.2rem]'">{{ formatAngka(nilai) }}</p>

            <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] font-bold">
                <span>RKA {{ formatAngka(target) }}</span>
                <span class="inline-flex rounded-md px-2 py-0.5 text-[10px] font-black shadow-sm" :class="pctBadgeClsArah(pencapaian, inverse)">
                    Penc {{ formatPct(pencapaian) }}
                </span>
            </div>

            <p class="mt-1 text-[11px] font-extrabold">
                <span class="text-white/90">Gap RKA</span>
                <span class="ml-1 tabular-nums" :class="deltaTone(gap)">{{ formatDelta(gap) }}</span>
            </p>
            <p class="mt-1 text-[10px] font-bold tabular-nums text-white/90">
                MtD <span :class="deltaTone(delta?.mtd?.nilai)">{{ formatDelta(delta?.mtd?.nilai) }}</span>
                <span class="text-white/45"> · </span>
                YtD <span :class="deltaTone(delta?.ytd?.nilai)">{{ formatDelta(delta?.ytd?.nilai) }}</span>
            </p>

            <div v-if="detailItems.length" class="mt-3 border-t border-white/25 pt-2">
                <section
                    v-for="(item, index) in detailItems"
                    :key="item.label"
                    class="py-2"
                    :class="index > 0 ? 'border-t border-dashed border-white/20' : ''"
                >
                    <template v-if="item.mode === 'ratio'">
                        <p class="text-xs font-extrabold">{{ item.label }}</p>
                        <p class="mt-0.5 text-[2rem] font-black leading-none tabular-nums">{{ detailValue(item, 'nilai') }}</p>
                        <div class="mt-2 flex flex-wrap items-center gap-2 text-[11px] font-bold">
                            <span>RKA {{ detailValue(item, 'target') }}</span>
                            <span class="inline-flex rounded-md px-2 py-0.5 text-[10px] font-black shadow-sm" :class="pctBadgeClsArah(item.pencapaian, true)">
                                Penc {{ formatPct(item.pencapaian) }}
                            </span>
                        </div>
                        <p class="mt-1 text-[11px] font-extrabold">
                            Gap RKA <span class="tabular-nums" :class="deltaTone(item.gap, true)">{{ detailGap(item) }}</span>
                        </p>
                        <p class="mt-1 text-[10px] font-bold tabular-nums text-white/90">
                            MtD <span :class="deltaTone(item.delta?.mtd?.nilai, true)">{{ detailDelta(item, 'mtd') }}</span>
                            <span class="text-white/45"> · </span>
                            YtD <span :class="deltaTone(item.delta?.ytd?.nilai, true)">{{ detailDelta(item, 'ytd') }}</span>
                        </p>
                    </template>

                    <template v-else>
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="truncate text-xs font-extrabold">{{ item.label }}</p>
                                <p class="mt-0.5 truncate font-black leading-none tabular-nums" :class="compact ? 'text-[1.45rem]' : 'text-[1.75rem]'">{{ detailValue(item, 'nilai') }}</p>
                            </div>
                            <div class="shrink-0 text-right">
                                <span class="inline-flex rounded-md px-2 py-0.5 text-[10px] font-black shadow-sm" :class="pctBadgeClsArah(item.pencapaian, false)">{{ formatPct(item.pencapaian) }}</span>
                                <p class="mt-1 text-[9px] font-bold text-white/75">Gap RKA</p>
                                <p class="text-[11px] font-black tabular-nums" :class="deltaTone(item.gap, false)">{{ detailGap(item) }}</p>
                            </div>
                        </div>
                        <p class="mt-1 text-[10px] font-bold tabular-nums text-white/90">
                            MtD <span :class="deltaTone(item.delta?.mtd?.nilai, false)">{{ detailDelta(item, 'mtd') }}</span>
                            <span class="text-white/45"> · </span>
                            YtD <span :class="deltaTone(item.delta?.ytd?.nilai, false)">{{ detailDelta(item, 'ytd') }}</span>
                        </p>
                    </template>
                </section>
            </div>
        </div>
    </article>
</template>
