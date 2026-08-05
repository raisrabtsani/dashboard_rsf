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
 * Pembungkus line chart baku. Datalabels sengaja TIDAK diaktifkan di sini —
 * chart tren harian punya terlalu banyak titik; domain yang membutuhkannya
 * mendaftarkan plugin itu sendiri.
 */
ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Tooltip, Legend);

const props = defineProps({
    labels: { type: Array, required: true },
    datasets: { type: Array, required: true },
});

const data = computed(() => ({ labels: props.labels, datasets: props.datasets }));

const options = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 12, usePointStyle: true } },
        tooltip: {
            callbacks: {
                label: (ctx) => `${ctx.dataset.label}: ${formatAngka(ctx.parsed.y)}`,
            },
        },
    },
    scales: {
        y: {
            ticks: { callback: (v) => formatAngka(v) },
            grid: { color: 'rgba(0,0,0,0.05)' },
        },
        x: { grid: { display: false } },
    },
    elements: { line: { tension: 0.3, borderWidth: 2 }, point: { radius: 0, hitRadius: 12 } },
};
</script>

<template>
    <Line :data="data" :options="options" />
</template>
