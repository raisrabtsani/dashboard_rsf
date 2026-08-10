<script setup>
import rsfLogo from '../../images/rsf-region-7.png';
import Dropdown from '@/Components/Dropdown.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useIdleLogout } from '@/composables/useIdleLogout';

const user = computed(() => usePage().props.auth?.user ?? null);
const isAdmin = computed(() => user.value?.role === 'admin');
const levelAkses = computed(() => user.value?.access_level ?? 'LEVEL_UKER');

useIdleLogout(usePage().props.sessionLifetime ?? 10);

const sidebarMobile = ref(false);
const sidebarDesktop = ref(true);

/* --- Watermark anti-bocor screenshot (dipertahankan dari layout lama) --- */
const escXml = (s) =>
    String(s).replace(
        /[<>&'"]/g,
        (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', "'": '&apos;', '"': '&quot;' })[c],
    );

const watermarkStyle = computed(() => {
    if (!user.value) return { display: 'none' };

    const teks = escXml(`${user.value.name} · ${user.value.username}`);
    const svg =
        `<svg xmlns='http://www.w3.org/2000/svg' width='520' height='240'>` +
        `<text x='14' y='138' font-family='Inter, sans-serif' font-size='23' font-weight='600' ` +
        `fill='#0f172a' fill-opacity='0.05'>${teks}</text></svg>`;

    return { backgroundImage: `url("data:image/svg+xml,${encodeURIComponent(svg)}")` };
});

/* --- Ikon sidebar (inner SVG, stroke currentColor) --- */
const IKON = {
    overview: '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
    dpk: '<circle cx="12" cy="12" r="9"/><path d="M12 7v10M9.5 9.5c0-1 1-1.6 2.5-1.6s2.5.7 2.5 1.7-1 1.5-2.5 1.9-2.5.9-2.5 1.9 1 1.7 2.5 1.7 2.5-.6 2.5-1.6"/>',
    kredit: '<rect x="4" y="4" width="16" height="16" rx="2"/><path d="M8 9h8M8 13h8M8 17h5"/>',
    recovery: '<circle cx="12" cy="12" r="9"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/>',
    laba: '<path d="M4 16l5-5 3 3 7-8"/><path d="M20 6v4h-4"/>',
    merchant: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 9h18M7 15h4"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    present: '<rect x="3" y="4" width="18" height="12" rx="1.5"/><path d="M12 16v4M8 20h8"/>',
    admin: '<path d="M12 3l7 3v5c0 4.4-3 8.4-7 9-4-.6-7-4.6-7-9V6z"/>',
};

/**
 * Menu utama — label mengikuti mockup. Filter admin/level hanya kosmetik;
 * gerbang sebenarnya di middleware backend.
 */
const MENU = computed(() =>
    [
        { label: 'Overview', route: 'dashboard', cocok: 'dashboard', icon: 'overview' },
        { label: 'Dana Pihak Ketiga', route: 'simpanan', cocok: 'simpanan', icon: 'dpk' },
        { label: 'Pinjaman', route: 'pinjaman', cocok: 'pinjaman', icon: 'kredit' },
        {
            label: 'Recovery EC & PH',
            icon: 'recovery',
            cocok: ['recovery', 'recovery-ph'],
            children: [
                { label: 'Recovery EC', route: 'recovery', cocok: 'recovery' },
                { label: 'PH & Net DG', route: 'recovery-ph', cocok: 'recovery-ph' },
            ],
        },
        { label: 'Laba', route: 'laba', cocok: 'laba', icon: 'laba' },
        { label: 'Merchant', route: 'merchant', cocok: 'merchant', icon: 'merchant' },
        { label: 'DPK Hourly', route: 'simpanan-hourly', cocok: 'simpanan-hourly', icon: 'clock', kecualiUker: true },
        { label: 'Present RSF', route: 'present', cocok: 'present', icon: 'present', hanyaLevelAll: true },
        { label: 'Admin', route: 'admin.index', cocok: 'admin.*', icon: 'admin', adminSaja: true },
    ]
        .filter((m) => !m.adminSaja || isAdmin.value)
        .filter((m) => !m.kecualiUker || levelAkses.value !== 'LEVEL_UKER')
        .filter((m) => !m.hanyaLevelAll || levelAkses.value === 'LEVEL_ALL'),
);

const aktif = (cocok) =>
    (Array.isArray(cocok) ? cocok : [cocok]).some((c) => route().current(c));

// Grup submenu terbuka kalau salah satu anaknya aktif.
const grupTerbuka = ref({});
MENU.value.forEach((m) => {
    if (m.children) grupTerbuka.value[m.label] = aktif(m.cocok);
});
</script>

<template>
    <div class="min-h-screen bg-slate-100">
        <div v-if="user" class="watermark" :style="watermarkStyle" aria-hidden="true" />

        <!-- Overlay drawer mobile -->
        <div
            v-if="sidebarMobile"
            class="fixed inset-0 z-30 bg-slate-900/40 lg:hidden"
            @click="sidebarMobile = false"
        />

        <!-- ===================== HEADER TETAP ===================== -->
    <!-- Topbar -->
    <header class="sticky top-0 z-50 border-b border-blue-700/40 bg-brand-600 text-white shadow-sm">
        <div class="flex h-16 items-center justify-between px-4 sm:px-6">
            <div class="flex items-center gap-3">
                <button
                    type="button"
                    class="inline-flex h-9 w-9 items-center justify-center rounded-xl text-white/85 transition hover:bg-white/15 hover:text-white lg:hidden"
                    aria-label="Buka menu"
                    @click="sidebarMobile = true"
                >
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>

                <button
                    type="button"
                    class="hidden h-9 w-9 items-center justify-center rounded-xl border border-white/15 bg-white/10 text-white/85 shadow-sm transition hover:bg-white/20 hover:text-white lg:inline-flex"
                    :aria-label="sidebarDesktop ? 'Sembunyikan navbar' : 'Tampilkan navbar'"
                    :title="sidebarDesktop ? 'Sembunyikan navbar' : 'Tampilkan navbar'"
                    :aria-expanded="sidebarDesktop"
                    @click="sidebarDesktop = !sidebarDesktop"
                >
                    <svg
                        class="h-5 w-5 transition-transform duration-300"
                        :class="sidebarDesktop ? '' : 'rotate-180'"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <rect x="3" y="4" width="18" height="16" rx="2" />
                        <path d="M9 4v16" />
                        <path d="m17 9-3 3 3 3" />
                    </svg>
                </button>
                <div class="flex items-center gap-2">
                    <img
                        :src="rsfLogo"
                        alt="RSF Region 7 Jakarta 2"
                        class="h-10 w-10 shrink-0 rounded-xl object-contain shadow-sm ring-1 ring-white/20"
                    />
                    <div class="hidden leading-tight sm:block">
                        <p class="text-sm font-bold tracking-tight">DASHBOARD KERAGAAN</p>
                        <p class="text-[11px] text-white/70">Regional Strategy and Finance</p>
                    </div>
                </div>
            </div>

            <Dropdown
                align="right"
                width="72"
                content-classes="overflow-hidden rounded-2xl bg-white"
            >
                <template #trigger>
                    <button
                        type="button"
                        class="flex max-w-[290px] items-center gap-2 rounded-xl bg-white/10 px-2.5 py-1.5 text-left ring-1 ring-white/10 transition hover:bg-white/15"
                    >
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-white/30 bg-white/10">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                                <circle cx="12" cy="8" r="4" />
                                <path d="M4.5 21a7.5 7.5 0 0115 0" />
                            </svg>
                        </span>
                        <span class="hidden min-w-0 leading-tight sm:block">
                            <span class="block truncate text-xs font-bold tracking-tight">{{ user?.name }}</span>
                            <span class="mt-0.5 block truncate text-[10px] text-white/70">Region 7 Jakarta 2</span>
                        </span>
                        <svg class="h-3.5 w-3.5 shrink-0 text-white/75" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M5.3 12.7a1 1 0 001.4 0L10 9.4l3.3 3.3a1 1 0 001.4-1.4l-4-4a1 1 0 00-1.4 0l-4 4a1 1 0 000 1.4z" clip-rule="evenodd" />
                        </svg>
                    </button>
                </template>

                <template #content>
                    <div class="border-b border-slate-200 bg-slate-50/70 px-4 py-4">
                        <p class="truncate text-sm font-extrabold leading-snug text-slate-700">{{ user?.name }}</p>
                        <p class="mt-1 text-xs font-medium text-slate-500">Region 7 Jakarta 2</p>
                    </div>

                    <Link
                        :href="route('profile.edit')"
                        class="group flex w-full items-center gap-3 border-b border-slate-200 px-4 py-3.5 text-left text-sm font-semibold text-slate-600 transition hover:bg-blue-50 hover:text-brand-700"
                    >
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500 transition group-hover:bg-brand-100 group-hover:text-brand-700">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M15.75 5.25a3 3 0 1 1-4.243 4.243L5.25 15.75v3h3V16.5h2.25v-2.25h2.25l3-3" />
                            </svg>
                        </span>
                        Ganti Password
                    </Link>

                    <Link
                        :href="route('logout')"
                        method="post"
                        as="button"
                        class="group flex w-full items-center gap-3 px-4 py-3.5 text-left text-sm font-semibold text-rose-600 transition hover:bg-rose-50"
                    >
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-rose-50 text-rose-500 transition group-hover:bg-rose-100 group-hover:text-rose-600">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M10 5H6a2 2 0 00-2 2v10a2 2 0 002 2h4" />
                                <path d="M14 8l4 4-4 4M18 12H9" />
                            </svg>
                        </span>
                        Logout
                    </Link>
                </template>
            </Dropdown>
        </div>
    </header>

        <!-- ===================== SIDEBAR ===================== -->
        <aside
            class="fixed bottom-0 left-0 top-16 z-40 flex w-72 flex-col border-r border-slate-200 bg-white shadow-xl shadow-slate-900/5 transition-transform duration-300 ease-in-out"
            :class="[
                sidebarMobile ? 'translate-x-0' : '-translate-x-full',
                sidebarDesktop ? 'lg:translate-x-0' : 'lg:-translate-x-full',
            ]"
        >
            <!-- Menu -->
            <nav class="flex-1 overflow-y-auto px-3 pb-4 pt-5">
                <p class="px-3 pb-2 text-[11px] font-semibold uppercase tracking-widest text-slate-400">Menu Utama</p>

                <template v-for="m in MENU" :key="m.label">
                    <!-- Item biasa -->
                    <Link
                        v-if="!m.children"
                        :href="route(m.route)"
                        class="group mb-1 flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition"
                        :class="aktif(m.cocok)
                            ? 'bg-brand-50 text-brand-700 ring-1 ring-brand-100'
                            : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900'"
                        @click="sidebarMobile = false"
                    >
                        <svg
                            class="h-5 w-5 shrink-0"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.7"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            :class="aktif(m.cocok) ? 'text-brand-600' : 'text-slate-400 group-hover:text-slate-500'"
                            v-html="IKON[m.icon]"
                        />
                        {{ m.label }}
                    </Link>

                    <!-- Grup submenu -->
                    <div v-else class="mb-1">
                        <button
                            type="button"
                            class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition"
                            :class="aktif(m.cocok)
                                ? 'text-brand-700'
                                : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900'"
                            @click="grupTerbuka[m.label] = !grupTerbuka[m.label]"
                        >
                            <svg
                                class="h-5 w-5 shrink-0"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.7"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                :class="aktif(m.cocok) ? 'text-brand-600' : 'text-slate-400'"
                                v-html="IKON[m.icon]"
                            />
                            <span class="flex-1 text-left">{{ m.label }}</span>
                            <svg
                                class="h-4 w-4 transition"
                                :class="grupTerbuka[m.label] ? 'rotate-180' : ''"
                                viewBox="0 0 20 20"
                                fill="currentColor"
                            >
                                <path fill-rule="evenodd" d="M5.3 7.3a1 1 0 011.4 0L10 10.6l3.3-3.3a1 1 0 111.4 1.4l-4 4a1 1 0 01-1.4 0l-4-4a1 1 0 010-1.4z" clip-rule="evenodd" />
                            </svg>
                        </button>
                        <div v-show="grupTerbuka[m.label]" class="ml-4 mt-1 space-y-1 border-l border-slate-100 pl-4">
                            <Link
                                v-for="c in m.children"
                                :key="c.route"
                                :href="route(c.route)"
                                class="block rounded-lg px-3 py-2 text-sm transition"
                                :class="aktif(c.cocok)
                                    ? 'bg-brand-50 font-semibold text-brand-700'
                                    : 'text-slate-500 hover:bg-slate-50 hover:text-slate-800'"
                                @click="sidebarMobile = false"
                            >
                                {{ c.label }}
                            </Link>
                        </div>
                    </div>
                </template>
            </nav>

            <!-- Footer region -->
            <div class="border-t border-slate-100 px-5 py-4">
                <div class="flex items-center gap-2 text-xs text-slate-500">
                    <span class="h-2 w-2 rounded-full bg-emerald-500" />
                    Region Office 7 Jakarta 2
                </div>
            </div>
        </aside>

        <!-- ===================== KONTEN ===================== -->
        <div
            class="transition-[padding] duration-300 ease-in-out"
            :class="sidebarDesktop ? 'lg:pl-72' : 'lg:pl-0'"
        >
            <!-- Header slot opsional -->
            <div v-if="$slots.header" class="border-b border-slate-200 bg-white px-4 py-5 sm:px-6">
                <slot name="header" />
            </div>

            <main class="px-4 py-6 sm:px-6">
                <slot />
            </main>
        </div>
    </div>
</template>
