<script setup>
import { formatAngka, formatDelta, formatPct } from '@/utils/formatAngka';
import { deltaCls, pctClsArah } from '@/utils/pencapaian';
import { computed } from 'vue';

const props = defineProps({
    judul: { type: String, required: true },
    baris: { type: Array, default: () => [] },
    kolom: { type: Array, default: () => [] },
    tanggal: { type: String, default: null },
    inverse: { type: Boolean, default: false },
});

const NAMA_BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Ags', 'Sep', 'Okt', 'Nov', 'Des'];
const tanggalLabel = computed(() => {
    const m = props.tanggal && /^(\d{4})-(\d{2})-(\d{2})$/.exec(props.tanggal);
    return m ? `${Number(m[3])} ${NAMA_BULAN[Number(m[2]) - 1]} ${m[1]}` : props.tanggal;
});

const headerAtas = computed(() => {
    const hasil = [];
    let i = 0;
    while (i < props.kolom.length) {
        const kolom = props.kolom[i];
        if (kolom.group) {
            let colspan = 1;
            while (i + colspan < props.kolom.length && props.kolom[i + colspan].group === kolom.group) colspan += 1;
            hasil.push({ label: kolom.group, colspan, rowspan: 1, sub: true });
            i += colspan;
            continue;
        }

        hasil.push({ label: kolom.label, colspan: 1, rowspan: 2, sub: false, key: kolom.key });
        i += 1;
    }
    return hasil;
});

const headerBawah = computed(() => props.kolom.filter((k) => !!k.group));
const colspanPenuh = computed(() => 1 + props.kolom.length);

function formatDeltaPct(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) return '-';
    const angka = Number(value);
    return `${angka > 0 ? '+' : angka < 0 ? '-' : ''}${Math.abs(angka).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}%`;
}

function formatAktif(row, kolom) {
    if (row?.row_mode === 'ratio') {
        if (kolom.format === 'number') return 'pct';
        if (kolom.format === 'delta') return 'deltaPct';
    }
    return kolom.format;
}

function formatCell(value, format) {
    if (format === 'pct') return formatPct(value);
    if (format === 'delta') return formatDelta(value);
    if (format === 'deltaPct') return formatDeltaPct(value);
    return formatAngka(value);
}

function cellClass(value, format, row, kolom) {
    // Pada tabel rasio, nilai Actual dan RKA dibuat netral.
    // Detail % SML dan % NPL hanya memberi warna pada Penc RKA sampai YTD.
    const tabelPersenKualitas = ['Detail % SML', 'Detail % NPL'];
    const kolomIndikator = tabelPersenKualitas.includes(props.judul)
        ? ['penc', 'dtd', 'mtd', 'ytd']
        : ['penc', 'dtd', 'mtd', 'ytd', 'yoy'];
    const tabelRasioNetral = [
        'Detail Dana Pihak Ketiga',
        ...tabelPersenKualitas,
    ];

    if (
        tabelRasioNetral.includes(props.judul)
        && row?.row_mode === 'ratio'
        && !kolomIndikator.includes(kolom?.key)
    ) {
        return 'text-slate-700';
    }

    if (format === 'pct') return pctClsArah(value, props.inverse);
    if (format === 'delta' || format === 'deltaPct') return deltaCls(value, props.inverse);
    return 'text-slate-700';
}
</script>

<template>
    <section class="overflow-hidden rounded-[18px] border border-slate-200 bg-white shadow-[0_8px_24px_rgba(15,23,42,0.07)]">
        <header class="flex flex-wrap items-center justify-between gap-2 bg-gradient-to-r from-[#0756bd] to-[#1676d6] px-4 py-3 text-white">
            <div>
                <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-white/65">Detail Kinerja</p>
                <h3 class="mt-0.5 text-sm font-extrabold uppercase tracking-wide">{{ judul }}</h3>
            </div>
            <span v-if="tanggalLabel" class="rounded-lg bg-white/15 px-2.5 py-1 text-[10px] font-semibold">Posisi {{ tanggalLabel }}</span>
        </header>

        <div class="overflow-x-auto">
            <table class="min-w-full border-collapse text-[11px]">
                <thead>
                    <tr class="bg-[#0b5fc8] text-white">
                        <th rowspan="2" class="min-w-[240px] border-b border-white/10 px-4 py-2 text-left font-extrabold uppercase tracking-wide">Mata Anggaran</th>
                        <template v-for="(h, idx) in headerAtas" :key="`top-${idx}-${h.label}`">
                            <th v-if="h.sub" :colspan="h.colspan" class="border-b border-white/10 px-3 py-2 text-center font-extrabold">{{ h.label }}</th>
                            <th v-else :rowspan="2" class="min-w-[92px] border-b border-white/10 px-3 py-2 text-center font-extrabold">{{ h.label }}</th>
                        </template>
                    </tr>
                    <tr class="bg-[#dfeeff] text-[#0756bd]">
                        <th
                            v-for="k in headerBawah"
                            :key="`sub-${k.key}`"
                            class="min-w-[92px] border-b border-slate-200 px-3 py-2 text-center font-extrabold"
                        >
                            {{ k.label }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template v-if="baris.length">
                        <template v-for="(b, index) in baris" :key="`${b.label}-${index}`">
                            <tr v-if="b.kind === 'group'" class="bg-[#eef6ff]">
                                <td :colspan="colspanPenuh" class="px-4 py-2 font-extrabold text-[#0b5fc8]">{{ b.label }}</td>
                            </tr>
                            <tr v-else class="transition hover:bg-blue-50/60">
                                <td class="px-4 py-2 font-semibold text-slate-700">{{ b.label }}</td>
                                <td
                                    v-for="k in kolom"
                                    :key="`${b.label}-${k.key}`"
                                    class="px-3 py-2 text-right font-semibold tabular-nums"
                                    :class="cellClass(b[k.key], formatAktif(b, k), b, k)"
                                >
                                    {{ formatCell(b[k.key], formatAktif(b, k)) }}
                                </td>
                            </tr>
                        </template>
                    </template>
                    <tr v-else>
                        <td :colspan="colspanPenuh" class="px-4 py-10 text-center text-slate-400">Tidak ada data pada tanggal posisi ini.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
