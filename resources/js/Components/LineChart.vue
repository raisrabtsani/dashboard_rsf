<script setup>
import {
    CategoryScale,
    Chart as ChartJS,
    Legend,
    LinearScale,
    LineElement,
    PointElement,
    Tooltip,
} from 'chart.js';
import { Line } from 'vue-chartjs';
import { formatAngka } from '@/utils/formatAngka';
import { computed } from 'vue';

/**
 * Pembungkus line chart baku dengan TOOLTIP HTML kustom.
 *
 * Tooltip dirender sebagai kartu HTML eksternal (bukan bawaan canvas): header
 * "Hari ke-N" + satu baris berkotak-warna per bulan. Dipakai semua domain yang
 * menampilkan tren harian per bulan, jadi tampilannya konsisten di seluruh app.
 */
ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Tooltip, Legend);

const props = defineProps({
    labels: { type: Array, required: true },
    datasets: { type: Array, required: true },
    /** Formatter nilai sumbu-Y & tooltip. Default satuan uang (juta). */
    formatNilai: { type: Function, default: formatAngka },
    /** Kata di header tooltip sebelum angka label-X. "Hari ke-23", "Jam", dst. */
    labelSumbu: { type: String, default: 'Hari ke-' },
});

const data = computed(() => ({ labels: props.labels, datasets: props.datasets }));

/** Tooltip HTML eksternal (lihat gambar desain). */
function tooltipKustom(context) {
    const { chart, tooltip } = context;
    const induk = chart.canvas.parentNode;
    induk.style.position = induk.style.position || 'relative';

    let el = induk.querySelector('.line-tooltip');
    if (!el) {
        el = document.createElement('div');
        el.className = 'line-tooltip';
        induk.appendChild(el);
    }

    if (tooltip.opacity === 0) {
        el.style.opacity = '0';
        return;
    }

    const judul = tooltip.title?.[0] ?? '';
    const baris = tooltip.dataPoints
        .filter((dp) => dp.parsed.y !== null && dp.parsed.y !== undefined)
        .map((dp) => {
            const warna = dp.dataset.borderColor;
            return (
                `<div class="lt-row">` +
                `<span class="lt-box" style="border-color:${warna}"></span>` +
                `<span class="lt-label">${dp.dataset.label}:</span>` +
                `<span class="lt-val">${props.formatNilai(dp.parsed.y)}</span>` +
                `</div>`
            );
        })
        .join('');

    el.innerHTML = `<div class="lt-head">${props.labelSumbu}${judul}</div>${baris}`;

    // Posisikan; jaga agar tidak keluar tepi kanan container.
    const lebar = el.offsetWidth;
    let x = chart.canvas.offsetLeft + tooltip.caretX + 14;
    if (x + lebar > induk.clientWidth) x = chart.canvas.offsetLeft + tooltip.caretX - lebar - 14;

    el.style.opacity = '1';
    el.style.left = `${Math.max(4, x)}px`;
    el.style.top = `${chart.canvas.offsetTop + tooltip.caretY - 10}px`;
}

const options = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 10, usePointStyle: true, font: { size: 10 } } },
        tooltip: { enabled: false, external: tooltipKustom },
    },
    scales: {
        y: { ticks: { callback: (v) => props.formatNilai(v), font: { size: 10 } }, grid: { color: 'rgba(15,23,42,0.05)' } },
        x: { grid: { display: false }, ticks: { font: { size: 10 } } },
    },
    elements: { line: { tension: 0.3, borderWidth: 2 }, point: { radius: 0, hitRadius: 12 } },
}));
</script>

<template>
    <Line :data="data" :options="options" />
</template>

<style>
/* Global (bukan scoped) karena tooltip disisipkan lewat DOM langsung. */
.line-tooltip {
    position: absolute;
    z-index: 20;
    min-width: 150px;
    padding: 10px 12px;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.15);
    pointer-events: none;
    opacity: 0;
    transition: opacity 0.12s ease;
    transform: translateY(-50%);
    font-size: 12px;
}
.line-tooltip .lt-head {
    margin-bottom: 6px;
    font-weight: 700;
    color: #0857c3;
}
.line-tooltip .lt-row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 1px 0;
    white-space: nowrap;
}
.line-tooltip .lt-box {
    width: 12px;
    height: 12px;
    flex: none;
    border: 2px solid;
    border-radius: 3px;
    background: #fff;
}
.line-tooltip .lt-label {
    color: #64748b;
}
.line-tooltip .lt-val {
    margin-left: auto;
    font-weight: 600;
    color: #0f172a;
}
</style>
