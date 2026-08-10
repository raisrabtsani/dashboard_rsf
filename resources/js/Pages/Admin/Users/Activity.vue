<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import LoadingOverlay from '@/Components/LoadingOverlay.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { fetchAktivitas } from '@/services/adminActivityApi';

const online = ref([]);
const pengguna = ref([]);
const statistik = ref({ online: 0, total: 0, ambang_menit: 5 });
const memuat = ref(false);
const terakhirDiperbarui = ref(null);

const offline = computed(() => Math.max(0, Number(statistik.value.total ?? 0) - Number(statistik.value.online ?? 0)));

/** Refresh senyap tiap 30 detik supaya daftar tetap segar tanpa berkedip. */
const INTERVAL_MS = 30_000;
let timer = null;

async function muat({ senyap = false } = {}) {
    if (!senyap) memuat.value = true;
    try {
        const data = await fetchAktivitas();
        online.value = data.online ?? [];
        pengguna.value = data.pengguna ?? [];
        statistik.value = data.statistik ?? statistik.value;
        terakhirDiperbarui.value = new Date();
    } finally {
        memuat.value = false;
    }
}

/** "baru saja" / "3 mnt lalu" / "2 jam lalu" / "4 hr lalu" / "—". */
function sejak(menit) {
    if (menit === null || menit === undefined) return '—';
    if (menit < 1) return 'baru saja';
    if (menit < 60) return `${menit} mnt lalu`;
    if (menit < 1440) return `${Math.floor(menit / 60)} jam lalu`;

    return `${Math.floor(menit / 1440)} hr lalu`;
}

function inisial(nama) {
    return String(nama ?? '?')
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((bagian) => bagian.charAt(0).toUpperCase())
        .join('') || '?';
}

function waktuPembaruan() {
    if (!terakhirDiperbarui.value) return 'Belum diperbarui';

    return terakhirDiperbarui.value.toLocaleTimeString('id-ID', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });
}

onMounted(() => {
    muat();
    timer = setInterval(() => muat({ senyap: true }), INTERVAL_MS);
});

onUnmounted(() => {
    if (timer) clearInterval(timer);
});
</script>

<template>
    <Head title="Aktivitas Pengguna" />

    <AuthenticatedLayout>
        <template #header>
            <div class="activity-header">
                <div class="activity-header__identity">
                    <span class="activity-header__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                            <circle cx="9" cy="7" r="4" />
                            <path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" />
                        </svg>
                    </span>
                    <div>
                        <p class="activity-header__eyebrow">ADMINISTRASI SISTEM</p>
                        <h2 class="activity-header__title">Aktivitas Pengguna</h2>
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-2">
                    <Link :href="route('admin.index')" class="inline-flex min-h-[2.65rem] items-center gap-2 rounded-xl border border-slate-200 bg-white px-3.5 text-xs font-semibold text-slate-600 shadow-sm transition hover:border-blue-200 hover:text-blue-700">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6" /></svg>
                        Kembali ke Admin
                    </Link>
                    <button class="activity-refresh" :disabled="memuat" type="button" @click="muat()">
                        <svg :class="{ 'activity-refresh__spin': memuat }" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M20 11a8.1 8.1 0 0 0-15.5-2M4 4v5h5M4 13a8.1 8.1 0 0 0 15.5 2M20 20v-5h-5" />
                        </svg>
                        <span>{{ memuat ? 'Memuat…' : 'Segarkan' }}</span>
                    </button>
                </div>
            </div>
        </template>

        <div class="activity-page">
            <div class="activity-container">
                <section class="activity-hero">
                    <div class="activity-hero__content">
                        <div class="activity-hero__live">
                            <span class="activity-hero__live-dot" />
                            PEMANTAUAN LANGSUNG
                        </div>
                        <h1 class="activity-hero__title">Pantau pengguna yang sedang aktif</h1>
                        <p class="activity-hero__description">
                            Data aktivitas diperbarui otomatis setiap 30 detik untuk membantu pemantauan akses dashboard.
                        </p>
                    </div>
                    <div class="activity-hero__sync">
                        <span>Terakhir diperbarui</span>
                        <strong>{{ waktuPembaruan() }}</strong>
                    </div>
                </section>

                <section class="activity-summary">
                    <article class="activity-stat activity-stat--online">
                        <div class="activity-stat__icon">
                            <svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" /><path d="M8 12l2.5 2.5L16 9" /></svg>
                        </div>
                        <div>
                            <p class="activity-stat__label">Online sekarang</p>
                            <p class="activity-stat__value">{{ statistik.online }}</p>
                            <p class="activity-stat__hint">Aktif ≤ {{ statistik.ambang_menit }} menit</p>
                        </div>
                    </article>

                    <article class="activity-stat activity-stat--total">
                        <div class="activity-stat__icon">
                            <svg viewBox="0 0 24 24" fill="none"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" /><circle cx="8.5" cy="7" r="4" /><path d="M20 8v6M23 11h-6" /></svg>
                        </div>
                        <div>
                            <p class="activity-stat__label">Total akun</p>
                            <p class="activity-stat__value">{{ statistik.total }}</p>
                            <p class="activity-stat__hint">Akun terdaftar</p>
                        </div>
                    </article>

                    <article class="activity-stat activity-stat--offline">
                        <div class="activity-stat__icon">
                            <svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" /><path d="M8 12h8" /></svg>
                        </div>
                        <div>
                            <p class="activity-stat__label">Sedang offline</p>
                            <p class="activity-stat__value">{{ offline }}</p>
                            <p class="activity-stat__hint">Tidak aktif saat ini</p>
                        </div>
                    </article>
                </section>

                <section class="activity-panel activity-panel--online">
                    <LoadingOverlay :show="memuat" />
                    <header class="activity-panel__header">
                        <div>
                            <div class="activity-panel__title-row">
                                <span class="activity-panel__status-dot" />
                                <h3>Online sekarang</h3>
                                <span class="activity-panel__count">{{ online.length }}</span>
                            </div>
                            <p>Pengguna yang memiliki aktivitas dalam {{ statistik.ambang_menit }} menit terakhir.</p>
                        </div>
                        <span class="activity-panel__live-label">LIVE</span>
                    </header>

                    <div class="activity-table-wrap">
                        <table class="activity-table">
                            <thead>
                                <tr>
                                    <th>Pengguna</th>
                                    <th>Tipe akses</th>
                                    <th>Alamat IP</th>
                                    <th class="activity-table__center">Sesi</th>
                                    <th class="activity-table__right">Aktivitas terakhir</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="u in online" :key="u.id">
                                    <td>
                                        <div class="activity-user">
                                            <span class="activity-avatar activity-avatar--online">{{ inisial(u.name) }}</span>
                                            <div>
                                                <p class="activity-user__name">{{ u.name }}</p>
                                                <p class="activity-user__username">@{{ u.username }}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="activity-role">{{ u.role === 'admin' ? 'Admin' : u.tipe }}</span></td>
                                    <td><code class="activity-ip">{{ u.ip ?? '—' }}</code></td>
                                    <td class="activity-table__center"><span class="activity-session">{{ u.sesi }}</span></td>
                                    <td class="activity-table__right" :title="u.terakhir_aktivitas">
                                        <span class="activity-time activity-time--online">{{ sejak(u.menit_lalu) }}</span>
                                    </td>
                                </tr>
                                <tr v-if="!online.length">
                                    <td colspan="5">
                                        <div class="activity-empty">
                                            <span class="activity-empty__icon">○</span>
                                            <strong>Tidak ada pengguna yang online</strong>
                                            <p>Data akan muncul saat ada pengguna yang aktif.</p>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="activity-panel">
                    <header class="activity-panel__header">
                        <div>
                            <div class="activity-panel__title-row">
                                <span class="activity-panel__history-icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none"><path d="M3 12a9 9 0 1 0 3-6.7L3 8" /><path d="M3 3v5h5M12 7v5l3 2" /></svg>
                                </span>
                                <h3>Riwayat aktivitas pengguna</h3>
                            </div>
                            <p>Status dan waktu aktivitas terakhir seluruh pengguna.</p>
                        </div>
                        <span class="activity-panel__total">{{ pengguna.length }} pengguna</span>
                    </header>

                    <div class="activity-table-wrap">
                        <table class="activity-table">
                            <thead>
                                <tr>
                                    <th>Pengguna</th>
                                    <th>Kantor / unit kerja</th>
                                    <th>Status</th>
                                    <th class="activity-table__right">Terakhir aktif</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="u in pengguna" :key="u.id">
                                    <td>
                                        <div class="activity-user">
                                            <span class="activity-avatar" :class="{ 'activity-avatar--online': u.online }">{{ inisial(u.name) }}</span>
                                            <div>
                                                <p class="activity-user__name">{{ u.name }}</p>
                                                <p class="activity-user__username">@{{ u.username }}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="activity-office">{{ u.uker ?? u.cabang ?? '—' }}</span></td>
                                    <td>
                                        <span class="activity-status" :class="u.online ? 'activity-status--online' : 'activity-status--offline'">
                                            <span />{{ u.online ? 'Online' : 'Offline' }}
                                        </span>
                                    </td>
                                    <td class="activity-table__right" :title="u.terakhir_aktif ?? ''">
                                        <span class="activity-time" :class="{ 'activity-time--online': u.online }">{{ sejak(u.menit_lalu) }}</span>
                                    </td>
                                </tr>
                                <tr v-if="!pengguna.length">
                                    <td colspan="4">
                                        <div class="activity-empty">
                                            <strong>Belum ada data aktivitas</strong>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.activity-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.activity-header__identity {
    display: flex;
    align-items: center;
    gap: 0.85rem;
}

.activity-header__icon {
    display: grid;
    height: 2.75rem;
    width: 2.75rem;
    place-items: center;
    border-radius: 0.9rem;
    background: linear-gradient(145deg, #0b67d5, #0754b9);
    color: white;
    box-shadow: 0 8px 18px rgba(8, 91, 197, 0.25);
}

.activity-header__icon svg,
.activity-refresh svg,
.activity-stat__icon svg,
.activity-panel__history-icon svg {
    height: 1.25rem;
    width: 1.25rem;
    stroke: currentColor;
    stroke-width: 1.8;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.activity-header__eyebrow {
    margin: 0;
    color: #2563eb;
    font-size: 0.66rem;
    font-weight: 800;
    letter-spacing: 0.15em;
}

.activity-header__title {
    margin: 0.15rem 0 0;
    color: #172033;
    font-size: 1.2rem;
    font-weight: 800;
}

.activity-refresh {
    display: inline-flex;
    min-height: 2.65rem;
    align-items: center;
    gap: 0.5rem;
    border: 1px solid #d7e0ec;
    border-radius: 0.8rem;
    background: #fff;
    padding: 0 1rem;
    color: #334155;
    font-size: 0.82rem;
    font-weight: 750;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.05);
    transition: all 0.2s ease;
}

.activity-refresh:hover:not(:disabled) {
    border-color: #93c5fd;
    color: #0755bd;
    transform: translateY(-1px);
    box-shadow: 0 8px 18px rgba(37, 99, 235, 0.12);
}

.activity-refresh:disabled {
    cursor: wait;
    opacity: 0.65;
}

.activity-refresh__spin {
    animation: activity-spin 0.8s linear infinite;
}

.activity-page {
    min-height: calc(100vh - 5rem);
    background: linear-gradient(180deg, #f3f7fc 0%, #edf3f9 100%);
    padding: 1.5rem 1rem 3rem;
}

.activity-container {
    margin: 0 auto;
    max-width: 1280px;
}

.activity-hero {
    position: relative;
    display: flex;
    min-height: 155px;
    align-items: center;
    justify-content: space-between;
    gap: 1.5rem;
    overflow: hidden;
    border-radius: 1.35rem;
    background: linear-gradient(115deg, #0754b9 0%, #0a66d2 55%, #2f8de5 100%);
    padding: 1.65rem 1.8rem;
    color: white;
    box-shadow: 0 16px 34px rgba(7, 82, 181, 0.2);
}

.activity-hero::before,
.activity-hero::after {
    content: '';
    position: absolute;
    border-radius: 9999px;
    background: rgba(255, 255, 255, 0.08);
}

.activity-hero::before {
    right: -60px;
    top: -120px;
    height: 280px;
    width: 280px;
}

.activity-hero::after {
    right: 160px;
    bottom: -145px;
    height: 240px;
    width: 240px;
}

.activity-hero__content,
.activity-hero__sync {
    position: relative;
    z-index: 1;
}

.activity-hero__live {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    color: #dbeafe;
    font-size: 0.68rem;
    font-weight: 800;
    letter-spacing: 0.16em;
}

.activity-hero__live-dot {
    height: 0.55rem;
    width: 0.55rem;
    border-radius: 9999px;
    background: #5eead4;
    box-shadow: 0 0 0 5px rgba(94, 234, 212, 0.15);
    animation: activity-pulse 1.7s ease-in-out infinite;
}

.activity-hero__title {
    margin: 0.75rem 0 0;
    font-size: clamp(1.55rem, 3vw, 2.25rem);
    font-weight: 850;
    line-height: 1.15;
}

.activity-hero__description {
    margin: 0.65rem 0 0;
    max-width: 690px;
    color: #dbeafe;
    font-size: 0.88rem;
    line-height: 1.65;
}

.activity-hero__sync {
    display: flex;
    min-width: 185px;
    flex-direction: column;
    border: 1px solid rgba(255, 255, 255, 0.18);
    border-radius: 1rem;
    background: rgba(255, 255, 255, 0.1);
    padding: 0.9rem 1rem;
    text-align: right;
    backdrop-filter: blur(8px);
}

.activity-hero__sync span {
    color: #bfdbfe;
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.activity-hero__sync strong {
    margin-top: 0.25rem;
    font-size: 1rem;
}

.activity-summary {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 1rem;
    margin-top: 1rem;
}

.activity-stat {
    display: flex;
    align-items: center;
    gap: 1rem;
    min-height: 118px;
    border: 1px solid #dfe7f1;
    border-radius: 1.15rem;
    background: #fff;
    padding: 1.15rem 1.25rem;
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.055);
}

.activity-stat__icon {
    display: grid;
    height: 3rem;
    width: 3rem;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 1rem;
}

.activity-stat--online .activity-stat__icon {
    background: #e9fbf4;
    color: #059669;
}

.activity-stat--total .activity-stat__icon {
    background: #eaf3ff;
    color: #2563eb;
}

.activity-stat--offline .activity-stat__icon {
    background: #f2f4f7;
    color: #64748b;
}

.activity-stat__label {
    margin: 0;
    color: #64748b;
    font-size: 0.68rem;
    font-weight: 800;
    letter-spacing: 0.09em;
    text-transform: uppercase;
}

.activity-stat__value {
    margin: 0.15rem 0 0;
    color: #172033;
    font-size: 2rem;
    font-weight: 850;
    line-height: 1;
    font-variant-numeric: tabular-nums;
}

.activity-stat--online .activity-stat__value {
    color: #059669;
}

.activity-stat__hint {
    margin: 0.35rem 0 0;
    color: #94a3b8;
    font-size: 0.72rem;
}

.activity-panel {
    position: relative;
    overflow: hidden;
    margin-top: 1rem;
    border: 1px solid #dfe7f1;
    border-radius: 1.15rem;
    background: white;
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.055);
}

.activity-panel--online {
    border-top: 3px solid #10b981;
}

.activity-panel__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    border-bottom: 1px solid #edf1f6;
    padding: 1rem 1.25rem;
}

.activity-panel__title-row {
    display: flex;
    align-items: center;
    gap: 0.55rem;
}

.activity-panel__title-row h3 {
    margin: 0;
    color: #1e293b;
    font-size: 0.95rem;
    font-weight: 800;
}

.activity-panel__header p {
    margin: 0.3rem 0 0;
    color: #94a3b8;
    font-size: 0.72rem;
}

.activity-panel__status-dot {
    height: 0.62rem;
    width: 0.62rem;
    border-radius: 9999px;
    background: #10b981;
    box-shadow: 0 0 0 4px #d1fae5;
}

.activity-panel__count,
.activity-panel__total,
.activity-panel__live-label {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 9999px;
    font-size: 0.68rem;
    font-weight: 800;
}

.activity-panel__count {
    min-width: 1.55rem;
    height: 1.55rem;
    background: #d1fae5;
    color: #047857;
}

.activity-panel__live-label {
    background: #ecfdf5;
    color: #059669;
    letter-spacing: 0.12em;
    padding: 0.45rem 0.7rem;
}

.activity-panel__total {
    background: #eff6ff;
    color: #2563eb;
    padding: 0.45rem 0.75rem;
}

.activity-panel__history-icon {
    display: inline-grid;
    height: 1.8rem;
    width: 1.8rem;
    place-items: center;
    border-radius: 0.55rem;
    background: #eff6ff;
    color: #2563eb;
}

.activity-panel__history-icon svg {
    height: 1rem;
    width: 1rem;
}

.activity-table-wrap {
    overflow-x: auto;
}

.activity-table {
    width: 100%;
    min-width: 760px;
    border-collapse: collapse;
    color: #475569;
    font-size: 0.78rem;
}

.activity-table th {
    background: #f8fafc;
    color: #64748b;
    padding: 0.75rem 1.25rem;
    text-align: left;
    font-size: 0.64rem;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.activity-table td {
    border-top: 1px solid #eef2f7;
    padding: 0.82rem 1.25rem;
    vertical-align: middle;
}

.activity-table tbody tr {
    transition: background 0.18s ease;
}

.activity-table tbody tr:hover {
    background: #f8fbff;
}

.activity-table__center {
    text-align: center !important;
}

.activity-table__right {
    text-align: right !important;
}

.activity-user {
    display: flex;
    align-items: center;
    gap: 0.72rem;
}

.activity-avatar {
    display: inline-grid;
    height: 2.25rem;
    width: 2.25rem;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 0.72rem;
    background: linear-gradient(145deg, #e8eef7, #dbe4f0);
    color: #475569;
    font-size: 0.68rem;
    font-weight: 850;
}

.activity-avatar--online {
    background: linear-gradient(145deg, #d1fae5, #a7f3d0);
    color: #047857;
    box-shadow: 0 0 0 3px #ecfdf5;
}

.activity-user__name {
    margin: 0;
    color: #1e293b;
    font-weight: 750;
}

.activity-user__username {
    margin: 0.12rem 0 0;
    color: #94a3b8;
    font-size: 0.68rem;
}

.activity-role {
    display: inline-flex;
    border-radius: 9999px;
    background: #eff6ff;
    color: #1d4ed8;
    padding: 0.35rem 0.65rem;
    font-size: 0.7rem;
    font-weight: 750;
}

.activity-ip {
    border-radius: 0.4rem;
    background: #f1f5f9;
    padding: 0.3rem 0.5rem;
    color: #475569;
    font-size: 0.7rem;
    font-variant-numeric: tabular-nums;
}

.activity-session {
    display: inline-grid;
    min-width: 1.8rem;
    height: 1.8rem;
    place-items: center;
    border-radius: 0.55rem;
    background: #f1f5f9;
    color: #475569;
    font-weight: 800;
}

.activity-time {
    color: #64748b;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}

.activity-time--online {
    color: #059669;
}

.activity-office {
    color: #475569;
    font-weight: 650;
}

.activity-status {
    display: inline-flex;
    align-items: center;
    gap: 0.42rem;
    border-radius: 9999px;
    padding: 0.36rem 0.65rem;
    font-size: 0.7rem;
    font-weight: 800;
}

.activity-status span {
    height: 0.42rem;
    width: 0.42rem;
    border-radius: 9999px;
}

.activity-status--online {
    background: #ecfdf5;
    color: #047857;
}

.activity-status--online span {
    background: #10b981;
    box-shadow: 0 0 0 3px #d1fae5;
}

.activity-status--offline {
    background: #f1f5f9;
    color: #64748b;
}

.activity-status--offline span {
    background: #94a3b8;
}

.activity-empty {
    display: flex;
    min-height: 150px;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: #94a3b8;
    text-align: center;
}

.activity-empty__icon {
    margin-bottom: 0.5rem;
    color: #cbd5e1;
    font-size: 2rem;
}

.activity-empty strong {
    color: #64748b;
}

.activity-empty p {
    margin: 0.25rem 0 0;
    font-size: 0.72rem;
}

@keyframes activity-spin {
    to { transform: rotate(360deg); }
}

@keyframes activity-pulse {
    0%, 100% { opacity: 0.65; transform: scale(0.9); }
    50% { opacity: 1; transform: scale(1.08); }
}

@media (max-width: 800px) {
    .activity-hero {
        align-items: flex-start;
        flex-direction: column;
    }

    .activity-hero__sync {
        min-width: 0;
        width: 100%;
        text-align: left;
    }

    .activity-summary {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 560px) {
    .activity-header__eyebrow,
    .activity-header__icon {
        display: none;
    }

    .activity-header__title {
        font-size: 1rem;
    }

    .activity-refresh {
        min-height: 2.4rem;
        padding: 0 0.75rem;
    }

    .activity-page {
        padding-left: 0.65rem;
        padding-right: 0.65rem;
    }

    .activity-hero {
        padding: 1.3rem;
    }
}
</style>
