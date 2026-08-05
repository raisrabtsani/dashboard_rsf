<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useIdleLogout } from '@/composables/useIdleLogout';

const user = computed(() => usePage().props.auth?.user ?? null);
const isAdmin = computed(() => user.value?.role === 'admin');
const levelAkses = computed(() => user.value?.access_level ?? 'LEVEL_UKER');

useIdleLogout(usePage().props.sessionLifetime ?? 10);

const sidebarMobile = ref(false);

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
        `<svg xmlns='http://www.w3.org/2000/svg' width='340' height='160'>` +
        `<text x='8' y='90' font-family='Inter, sans-serif' font-size='15' font-weight='600' ` +
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

        <!-- ===================== SIDEBAR ===================== -->
        <aside
            class="fixed inset-y-0 left-0 z-40 flex w-72 flex-col border-r border-slate-200 bg-white transition-transform lg:translate-x-0"
            :class="sidebarMobile ? 'translate-x-0' : '-translate-x-full'"
        >
            <!-- Brand -->
            <div class="flex items-center gap-3 border-b border-slate-100 px-5 py-4">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-brand-600 to-brand-500 text-white shadow-md shadow-brand-600/30">
                    <ApplicationLogo class="h-6 w-6" />
                </div>
                <div class="leading-tight">
                    <p class="text-sm font-extrabold tracking-tight text-slate-900">DASHBOARD KERAGAAN</p>
                    <p class="text-[11px] text-slate-400">Regional Strategy and Finance</p>
                </div>
            </div>

            <!-- Menu -->
            <nav class="flex-1 overflow-y-auto px-3 py-4">
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
        <div class="lg:pl-72">
            <!-- Topbar -->
            <header class="sticky top-0 z-20 border-b border-slate-200 bg-brand-600 text-white">
                <div class="flex h-16 items-center justify-between px-4 sm:px-6">
                    <div class="flex items-center gap-3">
                        <button
                            type="button"
                            class="rounded-lg p-2 text-white/80 hover:bg-white/10 lg:hidden"
                            aria-label="Buka menu"
                            @click="sidebarMobile = true"
                        >
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16" /></svg>
                        </button>
                        <div class="flex items-center gap-2">
                            <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-white/15">
                                <ApplicationLogo class="h-5 w-5" />
                            </div>
                            <div class="hidden leading-tight sm:block">
                                <p class="text-sm font-bold tracking-tight">DASHBOARD KERAGAAN</p>
                                <p class="text-[11px] text-white/70">Regional Strategy and Finance</p>
                            </div>
                        </div>
                    </div>

                    <Dropdown align="right" width="48">
                        <template #trigger>
                            <button
                                type="button"
                                class="flex items-center gap-2 rounded-xl px-2 py-1.5 text-left transition hover:bg-white/10"
                            >
                                <span class="flex h-9 w-9 items-center justify-center rounded-full bg-white/15">
                                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor"><path d="M12 12a5 5 0 100-10 5 5 0 000 10zm0 2c-5 0-9 2.5-9 5.5V22h18v-2.5c0-3-4-5.5-9-5.5z" /></svg>
                                </span>
                                <span class="hidden leading-tight sm:block">
                                    <span class="block text-sm font-semibold">{{ user?.name }}</span>
                                    <span class="block text-[11px] uppercase text-white/70">{{ user?.tipe ?? user?.role }}</span>
                                </span>
                                <svg class="h-4 w-4 text-white/70" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.3 7.3a1 1 0 011.4 0L10 10.6l3.3-3.3a1 1 0 111.4 1.4l-4 4a1 1 0 01-1.4 0l-4-4a1 1 0 010-1.4z" clip-rule="evenodd" /></svg>
                            </button>
                        </template>
                        <template #content>
                            <DropdownLink :href="route('profile.edit')">Ganti Password</DropdownLink>
                            <DropdownLink :href="route('logout')" method="post" as="button">Log Out</DropdownLink>
                        </template>
                    </Dropdown>
                </div>
            </header>

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
