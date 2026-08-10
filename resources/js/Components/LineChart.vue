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
    tampilkanLegenda: { type: Boolean, default: true },
    variant: { type: String, default: 'default' },
    showLastValueTag: { type: Boolean, default: false },
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


const nilaiTerakhirPlugin = {
    id: 'nilaiTerakhirPlugin',
    afterDatasetsDraw(chart, args, pluginOptions) {
        if (!pluginOptions?.enabled) return;

        const datasetIndex = chart.data.datasets.length - 1;
        const meta = chart.getDatasetMeta(datasetIndex);
        if (!meta?.data?.length) return;

        const indeksTitik = [...meta.data].map((pt, idx) => ({ pt, idx }))
            .reverse()
            .find(({ idx }) => chart.data.datasets[datasetIndex].data[idx] !== null && chart.data.datasets[datasetIndex].data[idx] !== undefined);
        if (!indeksTitik) return;

        const { pt, idx } = indeksTitik;
        const value = chart.data.datasets[datasetIndex].data[idx];
        if (value === null || value === undefined) return;

        const { ctx } = chart;
        const label = pluginOptions.formatter ? pluginOptions.formatter(value) : String(value);
        const x = pt.x;
        const y = pt.y;
        const padX = 8;
        const h = 22;
        ctx.save();
        ctx.font = '700 11px Inter, sans-serif';
        const w = Math.ceil(ctx.measureText(label).width) + padX * 2;
        let boxX = x - w / 2;
        let boxY = y - 30;
        if (boxX < chart.chartArea.left + 4) boxX = chart.chartArea.left + 4;
        if (boxX + w > chart.chartArea.right - 4) boxX = chart.chartArea.right - w - 4;
        if (boxY < chart.chartArea.top + 4) boxY = y + 10;

        const r = 8;
        ctx.fillStyle = '#ffffff';
        ctx.strokeStyle = pluginOptions.borderColor || '#16b5d8';
        ctx.lineWidth = 1.5;
        ctx.beginPath();
        ctx.moveTo(boxX + r, boxY);
        ctx.lineTo(boxX + w - r, boxY);
        ctx.quadraticCurveTo(boxX + w, boxY, boxX + w, boxY + r);
        ctx.lineTo(boxX + w, boxY + h - r);
        ctx.quadraticCurveTo(boxX + w, boxY + h, boxX + w - r, boxY + h);
        ctx.lineTo(boxX + r, boxY + h);
        ctx.quadraticCurveTo(boxX, boxY + h, boxX, boxY + h - r);
        ctx.lineTo(boxX, boxY + r);
        ctx.quadraticCurveTo(boxX, boxY, boxX + r, boxY);
        ctx.closePath();
        ctx.fill();
        ctx.stroke();

        ctx.fillStyle = pluginOptions.textColor || '#16b5d8';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(label, boxX + w / 2, boxY + h / 2 + 0.5);
        ctx.restore();
    },
};

const options = computed(() => {
    const gayaTrend = props.variant === 'monthly-trend';

    const semuaNilai = props.datasets
        .flatMap((dataset) => (Array.isArray(dataset.data) ? dataset.data : []))
        .filter((nilai) => nilai !== null && nilai !== undefined && !Number.isNaN(Number(nilai)))
        .map(Number);

    const minNilai = semuaNilai.length ? Math.min(...semuaNilai) : 0;
    const maxNilai = semuaNilai.length ? Math.max(...semuaNilai) : 0;
    const rentang = Math.max(maxNilai - minNilai, 1);
    const padding = gayaTrend ? rentang * 0.18 : rentang * 0.1;
    const yMin = gayaTrend ? Math.max(0, minNilai - padding) : undefined;
    const yMax = gayaTrend ? maxNilai + padding : undefined;

    return {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        layout: { padding: gayaTrend ? { top: 10, right: 10, left: 0, bottom: 0 } : 0 },
        plugins: {
            // ChartDataLabels didaftarkan global oleh ComboChart. Tanpa override
            // ini seluruh angka mentah menumpuk di setiap titik line trend.
            datalabels: { display: false },
            legend: {
                display: props.tampilkanLegenda,
                position: 'bottom',
                labels: {
                    boxWidth: gayaTrend ? 8 : 10,
                    boxHeight: gayaTrend ? 8 : undefined,
                    usePointStyle: true,
                    pointStyle: 'circle',
                    padding: gayaTrend ? 18 : 12,
                    color: '#7b8aa0',
                    font: { size: gayaTrend ? 11 : 10, weight: gayaTrend ? '600' : '500' },
                },
            },
            tooltip: { enabled: false, external: tooltipKustom },
            nilaiTerakhirPlugin: {
                enabled: props.showLastValueTag,
                formatter: props.formatNilai,
                borderColor: '#16b5d8',
                textColor: '#16b5d8',
            },
        },
        scales: {
            y: {
                min: yMin,
                max: yMax,
                ticks: {
                    callback: (v) => props.formatNilai(v),
                    font: { size: gayaTrend ? 10 : 10, weight: gayaTrend ? '500' : '400' },
                    color: '#94a3b8',
                    maxTicksLimit: gayaTrend ? 5 : 6,
                },
                border: { display: false },
                grid: {
                    color: gayaTrend ? 'rgba(148,163,184,0.22)' : 'rgba(15,23,42,0.05)',
                    borderDash: gayaTrend ? [4, 4] : [],
                    drawTicks: false,
                },
            },
            x: {
                grid: { display: false },
                border: { color: 'rgba(148,163,184,0.18)' },
                ticks: {
                    color: '#94a3b8',
                    font: { size: gayaTrend ? 10 : 10 },
                    maxTicksLimit: gayaTrend ? 11 : undefined,
                },
            },
        },
        elements: {
            line: { tension: gayaTrend ? 0.35 : 0.3, borderWidth: gayaTrend ? 2.5 : 2 },
            point: { radius: 0, hitRadius: 12, hoverRadius: gayaTrend ? 4 : 3 },
        },
    };
});
</script>

<template>
    <Line :data="data" :options="options" :plugins="[nilaiTerakhirPlugin]" />
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
