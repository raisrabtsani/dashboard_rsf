<script setup>
import { ref } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useIdleLogout } from '@/composables/useIdleLogout';

const showingNavigationDropdown = ref(false);

const user = computed(() => usePage().props.auth?.user ?? null);
const isAdmin = computed(() => user.value?.role === 'admin');
const levelAkses = computed(() => user.value?.access_level ?? 'LEVEL_UKER');

// Auto-logout saat idle, sinkron dengan SESSION_LIFETIME server (menit).
useIdleLogout(usePage().props.sessionLifetime ?? 10);

/*
 * WATERMARK identitas — nama & username user login, diulang diagonal penuh layar
 * sebagai jejak anti-bocor screenshot. Digambar lewat SVG tile agar benar-benar
 * memenuhi layar (background-repeat). pointer-events/aria diatur di kelas .watermark
 * & atribut aria-hidden pada elemennya.
 */
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

/**
 * Menu utama. Domain dashboard berikutnya cukup ditambahkan di sini.
 *
 * `adminSaja` hanya menyembunyikan tautannya — gerbang sesungguhnya tetap
 * middleware `admin` di backend.
 */
const MENU = computed(() =>
    [
        { label: 'Dashboard', route: 'dashboard', cocok: 'dashboard' },
        // Kosmetik: gerbang sebenarnya middleware `present` (RO/admin) di backend.
        { label: 'PRESENT', route: 'present', cocok: 'present', hanyaLevelAll: true },
        { label: 'Simpanan (DPK)', route: 'simpanan', cocok: 'simpanan' },
        // Kosmetik: gerbang sebenarnya middleware `hourly` di backend.
        { label: 'DPK Hourly', route: 'simpanan-hourly', cocok: 'simpanan-hourly', kecualiUker: true },
        { label: 'Pinjaman', route: 'pinjaman', cocok: 'pinjaman' },
        { label: 'Recovery', route: 'recovery', cocok: 'recovery' },
        { label: 'PH & Net DG', route: 'recovery-ph', cocok: 'recovery-ph' },
        { label: 'Laba', route: 'laba', cocok: 'laba' },
        { label: 'Merchant', route: 'merchant', cocok: 'merchant' },
        { label: 'Admin', route: 'admin.index', cocok: 'admin.*', adminSaja: true },
    ]
        .filter((m) => !m.adminSaja || isAdmin.value)
        .filter((m) => !m.kecualiUker || levelAkses.value !== 'LEVEL_UKER')
        .filter((m) => !m.hanyaLevelAll || levelAkses.value === 'LEVEL_ALL'),
);
</script>

<template>
    <div>
        <!-- Watermark anti-bocor screenshot; non-interaktif & disembunyikan dari screen reader. -->
        <div v-if="user" class="watermark" :style="watermarkStyle" aria-hidden="true" />

        <div class="min-h-screen bg-gray-100">
            <nav
                class="border-b border-gray-100 bg-white"
            >
                <!-- Primary Navigation Menu -->
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="flex h-16 justify-between">
                        <div class="flex">
                            <!-- Logo -->
                            <div class="flex shrink-0 items-center">
                                <Link :href="route('dashboard')">
                                    <ApplicationLogo
                                        class="block h-9 w-auto fill-current text-gray-800"
                                    />
                                </Link>
                            </div>

                            <!-- Navigation Links -->
                            <div
                                class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex"
                            >
                                <NavLink
                                    v-for="m in MENU"
                                    :key="m.route"
                                    :href="route(m.route)"
                                    :active="route().current(m.cocok)"
                                >
                                    {{ m.label }}
                                </NavLink>
                            </div>
                        </div>

                        <div class="hidden sm:ms-6 sm:flex sm:items-center">
                            <!-- Settings Dropdown -->
                            <div class="relative ms-3">
                                <Dropdown align="right" width="48">
                                    <template #trigger>
                                        <span class="inline-flex rounded-md">
                                            <button
                                                type="button"
                                                class="inline-flex items-center rounded-md border border-transparent bg-white px-3 py-2 text-sm font-medium leading-4 text-gray-500 transition duration-150 ease-in-out hover:text-gray-700 focus:outline-none"
                                            >
                                                {{ $page.props.auth.user.name }}

                                                <svg
                                                    class="-me-0.5 ms-2 h-4 w-4"
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    viewBox="0 0 20 20"
                                                    fill="currentColor"
                                                >
                                                    <path
                                                        fill-rule="evenodd"
                                                        d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                        clip-rule="evenodd"
                                                    />
                                                </svg>
                                            </button>
                                        </span>
                                    </template>

                                    <template #content>
                                        <DropdownLink
                                            :href="route('profile.edit')"
                                        >
                                            Profile
                                        </DropdownLink>
                                        <DropdownLink
                                            :href="route('logout')"
                                            method="post"
                                            as="button"
                                        >
                                            Log Out
                                        </DropdownLink>
                                    </template>
                                </Dropdown>
                            </div>
                        </div>

                        <!-- Hamburger -->
                        <div class="-me-2 flex items-center sm:hidden">
                            <button
                                @click="
                                    showingNavigationDropdown =
                                        !showingNavigationDropdown
                                "
                                class="inline-flex items-center justify-center rounded-md p-2 text-gray-400 transition duration-150 ease-in-out hover:bg-gray-100 hover:text-gray-500 focus:bg-gray-100 focus:text-gray-500 focus:outline-none"
                            >
                                <svg
                                    class="h-6 w-6"
                                    stroke="currentColor"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        :class="{
                                            hidden: showingNavigationDropdown,
                                            'inline-flex':
                                                !showingNavigationDropdown,
                                        }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M4 6h16M4 12h16M4 18h16"
                                    />
                                    <path
                                        :class="{
                                            hidden: !showingNavigationDropdown,
                                            'inline-flex':
                                                showingNavigationDropdown,
                                        }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Responsive Navigation Menu -->
                <div
                    :class="{
                        block: showingNavigationDropdown,
                        hidden: !showingNavigationDropdown,
                    }"
                    class="sm:hidden"
                >
                    <div class="space-y-1 pb-3 pt-2">
                        <ResponsiveNavLink
                            v-for="m in MENU"
                            :key="m.route"
                            :href="route(m.route)"
                            :active="route().current(m.cocok)"
                        >
                            {{ m.label }}
                        </ResponsiveNavLink>
                    </div>

                    <!-- Responsive Settings Options -->
                    <div
                        class="border-t border-gray-200 pb-1 pt-4"
                    >
                        <div class="px-4">
                            <div
                                class="text-base font-medium text-gray-800"
                            >
                                {{ $page.props.auth.user.name }}
                            </div>
                            <div class="text-sm font-medium text-gray-500">
                                {{ $page.props.auth.user.email }}
                            </div>
                        </div>

                        <div class="mt-3 space-y-1">
                            <ResponsiveNavLink :href="route('profile.edit')">
                                Profile
                            </ResponsiveNavLink>
                            <ResponsiveNavLink
                                :href="route('logout')"
                                method="post"
                                as="button"
                            >
                                Log Out
                            </ResponsiveNavLink>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Page Heading -->
            <header
                class="bg-white shadow"
                v-if="$slots.header"
            >
                <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                    <slot name="header" />
                </div>
            </header>

            <!-- Page Content -->
            <main>
                <slot />
            </main>
        </div>
    </div>
</template>
