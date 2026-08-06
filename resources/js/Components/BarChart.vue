<script setup>
import {
    BarElement,
    CategoryScale,
    Chart as ChartJS,
    Legend,
    LinearScale,
    Tooltip,
} from 'chart.js';
import { Bar } from 'vue-chartjs';
import { formatAngka } from '@/utils/formatAngka';
import { computed } from 'vue';

ChartJS.register(CategoryScale, LinearScale, BarElement, Tooltip, Legend);

const props = defineProps({
    labels: { type: Array, required: true },
    datasets: { type: Array, required: true },
    formatNilai: { type: Function, default: formatAngka },
    tampilkanLegenda: { type: Boolean, default: true },
    showValueLabels: { type: Boolean, default: false },
    variant: { type: String, default: 'default' },
    stacked: { type: Boolean, default: false },
});

const data = computed(() => ({ labels: props.labels, datasets: props.datasets }));

const valueLabelsPlugin = {
    id: 'barValueLabels',
    afterDatasetsDraw(chart, args, pluginOptions) {
        if (!pluginOptions?.enabled) return;

        const { ctx, chartArea } = chart;
        ctx.save();
        ctx.font = '700 9px Inter, sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';

        chart.data.datasets.forEach((dataset, datasetIndex) => {
            const meta = chart.getDatasetMeta(datasetIndex);
            if (meta.hidden) return;

            meta.data.forEach((bar, index) => {
                const value = dataset.data[index];
                if (value === null || value === undefined || Number.isNaN(Number(value))) return;

                const text = pluginOptions.formatter ? pluginOptions.formatter(value) : String(value);
                const positif = Number(value) >= 0;
                const y = positif ? bar.y - 8 : bar.y + 10;
                if (y < chartArea.top + 4 || y > chartArea.bottom - 4) return;

                ctx.fillStyle = dataset.labelColor || dataset.borderColor || dataset.backgroundColor || '#334155';
                ctx.fillText(text, bar.x, y);
            });
        });

        ctx.restore();
    },
};

const options = computed(() => {
    const laba = props.variant === 'laba';

    return {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        layout: { padding: laba ? { top: 18, left: 2, right: 2, bottom: 0 } : 0 },
        plugins: {
            legend: {
                display: props.tampilkanLegenda,
                position: 'bottom',
                labels: {
                    boxWidth: laba ? 9 : 12,
                    boxHeight: laba ? 9 : undefined,
                    usePointStyle: true,
                    pointStyle: 'rectRounded',
                    padding: laba ? 18 : 12,
                    color: '#64748b',
                    font: { size: laba ? 10 : 11, weight: '600' },
                },
            },
            tooltip: {
                callbacks: {
                    label: (ctx) => `${ctx.dataset.label}: ${props.formatNilai(ctx.parsed.y)}`,
                },
            },
            barValueLabels: {
                enabled: props.showValueLabels,
                formatter: props.formatNilai,
            },
        },
        scales: {
            y: {
                stacked: props.stacked,
                border: { display: false },
                ticks: {
                    callback: (v) => props.formatNilai(v),
                    color: '#94a3b8',
                    font: { size: laba ? 9 : 10 },
                    maxTicksLimit: laba ? 5 : 6,
                },
                grid: {
                    color: laba ? 'rgba(148,163,184,0.18)' : 'rgba(0,0,0,0.05)',
                    borderDash: laba ? [4, 4] : [],
                    drawTicks: false,
                },
            },
            x: {
                stacked: props.stacked,
                grid: { display: false },
                border: { color: 'rgba(148,163,184,0.18)' },
                ticks: {
                    color: '#94a3b8',
                    font: { size: laba ? 9 : 10 },
                },
            },
        },
        datasets: {
            bar: {
                borderRadius: laba ? 5 : 0,
                borderSkipped: false,
                categoryPercentage: laba ? 0.68 : 0.8,
                barPercentage: laba ? 0.78 : 0.9,
                maxBarThickness: laba ? 38 : undefined,
            },
        },
    };
});
</script>

<template>
    <Bar :data="data" :options="options" :plugins="[valueLabelsPlugin]" />
</template>
