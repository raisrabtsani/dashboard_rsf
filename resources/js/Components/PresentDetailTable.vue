<script setup>
import { formatAngka, formatDelta, formatPct } from '@/utils/formatAngka';
import { deltaCls, pctClsArah } from '@/utils/pencapaian';
import { computed } from 'vue';

/**
 * SATU komponen tabel detail per cabang untuk PRESENT — dipakai DPK, Pinjaman,
 * SML, dan NPL. JANGAN membuat varian per metrik; perbedaan arah pencapaian
 * (SML/NPL makin kecil makin baik) ditangani lewat prop `inverse`.
 */
const props = defineProps({
    judul: { type: String, required: true },
    /** [{ id, nama, nilai, target, pencapaian, gap }] */
    baris: { type: Array, default: () => [] },
    tanggal: { type: String, default: null },
    /** Makin kecil makin baik (SML/NPL) — mempengaruhi warna pencapaian & gap. */
    inverse: { type: Boolean, default: false },
});

const jml = (kunci) => props.baris.reduce((t, b) => t + (Number(b[kunci]) || 0), 0);

const total = computed(() => {
    if (!props.baris.length) return null;

    const nilai = jml('nilai');
    const target = jml('target');

    return {
        nilai,
        target,
        gap: nilai - target,
        pencapaian: target > 0 ? Math.round((nilai / target) * 10000) / 100 : null,
    };
});

const NAMA_BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Ags', 'Sep', 'Okt', 'Nov', 'Des'];
const tanggalLabel = computed(() => {
    const m = props.tanggal && /^(\d{4})-(\d{2})-(\d{2})$/.exec(props.tanggal);

    return m ? `${Number(m[3])} ${NAMA_BULAN[Number(m[2]) - 1]} ${m[1]}` : props.tanggal;
});
</script>

<template>
    <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
        <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-gray-100 p-4">
            <h3 class="text-sm font-semibold text-gray-700">{{ judul }}</h3>
            <span v-if="tanggalLabel" class="text-[11px] text-gray-400">posisi {{ tanggalLabel }}</span>
        </div>

        <div class="overflow-x-auto">
            <table class="table-data">
                <thead>
                    <tr>
                        <th class="text-left">Cabang</th>
                        <th class="text-right">Nilai</th>
                        <th class="text-right">RKA</th>
                        <th class="text-right">Gap</th>
                        <th class="text-right">Pencapaian</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="b in baris" :key="b.id" class="hover:bg-gray-50">
                        <td class="text-gray-800">{{ b.nama }}</td>
                        <td class="text-right tabular-nums">{{ formatAngka(b.nilai) }}</td>
                        <td class="text-right tabular-nums text-gray-500">{{ formatAngka(b.target) }}</td>
                        <td class="text-right tabular-nums" :class="deltaCls(b.gap, inverse)">{{ formatDelta(b.gap) }}</td>
                        <td class="text-right font-semibold tabular-nums" :class="pctClsArah(b.pencapaian, inverse)">
                            {{ formatPct(b.pencapaian) }}
                        </td>
                    </tr>
                    <tr v-if="!baris.length">
                        <td colspan="5" class="py-6 text-center text-gray-400">Tidak ada data.</td>
                    </tr>
                </tbody>
                <tfoot v-if="total" class="border-t-2 border-gray-200 bg-gray-50 font-semibold">
                    <tr>
                        <td class="text-gray-700">Total Region</td>
                        <td class="text-right tabular-nums">{{ formatAngka(total.nilai) }}</td>
                        <td class="text-right tabular-nums text-gray-500">{{ formatAngka(total.target) }}</td>
                        <td class="text-right tabular-nums" :class="deltaCls(total.gap, inverse)">{{ formatDelta(total.gap) }}</td>
                        <td class="text-right tabular-nums" :class="pctClsArah(total.pencapaian, inverse)">
                            {{ formatPct(total.pencapaian) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</template>
