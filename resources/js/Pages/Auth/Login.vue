<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({
    status: { type: String },
});

// Login memakai username (label UI "Email atau PN"). Tidak ada lupa password /
// daftar akun — akun dibuat & direset admin.
const form = useForm({
    username: '',
    password: '',
    remember: false,
});

const lihatPassword = ref(false);

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Masuk" />

    <div class="grid-bg flex min-h-screen items-center justify-center bg-slate-100 p-4">
        <div
            class="grid w-full max-w-5xl overflow-hidden rounded-3xl bg-white shadow-2xl ring-1 ring-slate-200 md:grid-cols-2"
        >
            <!-- Panel kiri: brand -->
            <div
                class="relative hidden flex-col justify-between overflow-hidden bg-gradient-to-br from-brand-700 via-brand-600 to-brand-500 p-10 text-white md:flex"
            >
                <div
                    class="pointer-events-none absolute -right-16 -top-16 h-72 w-72 rounded-full bg-white/10"
                    aria-hidden="true"
                />
                <div
                    class="pointer-events-none absolute -bottom-24 -left-10 h-64 w-64 rounded-full bg-white/5"
                    aria-hidden="true"
                />

                <div class="relative">
                    <div class="flex items-center gap-3">
                        <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-white/15 text-white">
                            <ApplicationLogo class="h-7 w-7" />
                        </div>
                        <div>
                            <p class="text-lg font-extrabold leading-none tracking-tight">DASHBOARD KERAGAAN</p>
                            <p class="mt-1 text-xs text-white/70">Regional Strategy and Finance</p>
                        </div>
                    </div>

                    <span
                        class="mt-10 inline-flex items-center gap-2 rounded-lg bg-white/15 px-3 py-1.5 text-xs font-semibold tracking-wide"
                    >
                        REGION 7 · JAKARTA 2
                    </span>

                    <h1 class="mt-6 text-4xl font-extrabold leading-tight tracking-tight">
                        Data kinerja dalam<br />satu dashboard.
                    </h1>
                    <p class="mt-4 max-w-sm text-sm leading-relaxed text-white/80">
                        Pantau dana pihak ketiga, pinjaman, recovery, merchant, laba,
                        dan performa unit kerja secara terpadu.
                    </p>

                    <div class="mt-8 flex flex-wrap gap-3">
                        <span class="inline-flex items-center gap-2 rounded-xl bg-white/10 px-3 py-2 text-xs font-medium">
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0L3.3 9.7a1 1 0 111.4-1.4l3.3 3.3 6.8-6.8a1 1 0 011.4 0z" clip-rule="evenodd" /></svg>
                            Data terintegrasi
                        </span>
                        <span class="inline-flex items-center gap-2 rounded-xl bg-white/10 px-3 py-2 text-xs font-medium">
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0L3.3 9.7a1 1 0 111.4-1.4l3.3 3.3 6.8-6.8a1 1 0 011.4 0z" clip-rule="evenodd" /></svg>
                            Akses sesuai peran
                        </span>
                    </div>
                </div>

                <div class="relative flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-white/15">
                        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 1l7 3v5c0 4.4-3 8.4-7 9.5C6 17.4 3 13.4 3 9V4l7-3z" clip-rule="evenodd" /></svg>
                    </div>
                    <div>
                        <p class="text-sm font-semibold">Akses aman</p>
                        <p class="text-xs text-white/70">Gunakan akun resmi yang telah terdaftar.</p>
                    </div>
                </div>
            </div>

            <!-- Panel kanan: form -->
            <div class="p-8 sm:p-10">
                <p class="text-xs font-bold uppercase tracking-widest text-brand-600">Portal Keragaan</p>
                <h2 class="mt-2 text-3xl font-extrabold tracking-tight text-slate-900">Selamat datang</h2>
                <p class="mt-2 text-sm text-slate-500">
                    Masuk menggunakan akun resmi untuk mengakses Dashboard Keragaan Region 7 Jakarta 2.
                </p>

                <div v-if="status" class="mt-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-700">
                    {{ status }}
                </div>

                <form class="mt-8 space-y-5" @submit.prevent="submit">
                    <div>
                        <label for="username" class="text-sm font-medium text-slate-700">Email atau PN</label>
                        <div class="relative mt-1.5">
                            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M2.5 5.5A2 2 0 014.5 4h11a2 2 0 012 1.5L10 10.5 2.5 5.5z" /><path d="M18 7.4l-8 5-8-5V14a2 2 0 002 2h12a2 2 0 002-2V7.4z" /></svg>
                            </span>
                            <input
                                id="username"
                                v-model="form.username"
                                type="text"
                                required
                                autofocus
                                autocomplete="username"
                                placeholder="Masukkan username / PN"
                                class="block w-full rounded-xl border-slate-200 bg-slate-50 py-3 pl-10 pr-3 text-sm text-slate-900 placeholder-slate-400 focus:border-brand-500 focus:bg-white focus:ring-brand-500"
                            />
                        </div>
                        <p v-if="form.errors.username" class="mt-1.5 text-sm text-rose-600">{{ form.errors.username }}</p>
                    </div>

                    <div>
                        <label for="password" class="text-sm font-medium text-slate-700">Password</label>
                        <div class="relative mt-1.5">
                            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2h1a2 2 0 012 2v5a2 2 0 01-2 2H4a2 2 0 01-2-2v-5a2 2 0 012-2h1zm2 0h6V7a3 3 0 00-6 0v2z" clip-rule="evenodd" /></svg>
                            </span>
                            <input
                                id="password"
                                v-model="form.password"
                                :type="lihatPassword ? 'text' : 'password'"
                                required
                                autocomplete="current-password"
                                placeholder="••••••••"
                                class="block w-full rounded-xl border-slate-200 bg-slate-50 py-3 pl-10 pr-11 text-sm text-slate-900 placeholder-slate-400 focus:border-brand-500 focus:bg-white focus:ring-brand-500"
                            />
                            <button
                                type="button"
                                class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-600"
                                :aria-label="lihatPassword ? 'Sembunyikan password' : 'Tampilkan password'"
                                @click="lihatPassword = !lihatPassword"
                            >
                                <svg v-if="!lihatPassword" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M10 4C5 4 1.7 7.1.5 10c1.2 2.9 4.5 6 9.5 6s8.3-3.1 9.5-6c-1.2-2.9-4.5-6-9.5-6zm0 10a4 4 0 110-8 4 4 0 010 8zm0-2a2 2 0 100-4 2 2 0 000 4z" /></svg>
                                <svg v-else class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M2 4.3l1.3-1.3 14.4 14.4-1.3 1.3-2.5-2.5A9.6 9.6 0 0110 16c-5 0-8.3-3.1-9.5-6a11 11 0 013.3-4L2 4.3zM10 6a4 4 0 014 4c0 .5-.1 1-.3 1.5l-5.2-5.2C9 6.1 9.5 6 10 6zm-3.7.9l1.5 1.5A2 2 0 006 10a2 2 0 002 2 2 2 0 001.6-.8l1.5 1.5A4 4 0 016 10c0-1.2.5-2.3 1.3-3.1z" /></svg>
                            </button>
                        </div>
                        <p v-if="form.errors.password" class="mt-1.5 text-sm text-rose-600">{{ form.errors.password }}</p>
                    </div>

                    <label class="flex items-center gap-2">
                        <input
                            v-model="form.remember"
                            type="checkbox"
                            class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                        />
                        <span class="text-sm text-slate-600">Ingat saya</span>
                    </label>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-brand-700 to-brand-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-brand-600/25 transition hover:from-brand-800 hover:to-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 disabled:opacity-50"
                    >
                        {{ form.processing ? 'Memproses…' : 'Masuk ke Dashboard' }}
                        <svg v-if="!form.processing" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 10a1 1 0 011-1h9.6l-3.3-3.3a1 1 0 011.4-1.4l5 5a1 1 0 010 1.4l-5 5a1 1 0 01-1.4-1.4L13.6 11H4a1 1 0 01-1-1z" clip-rule="evenodd" /></svg>
                    </button>

                    <div class="flex items-start gap-2 rounded-xl bg-slate-50 px-3 py-3 text-xs text-slate-500 ring-1 ring-slate-100">
                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 1l7 3v5c0 4.4-3 8.4-7 9.5C6 17.4 3 13.4 3 9V4l7-3z" clip-rule="evenodd" /></svg>
                        Untuk keamanan, jangan membagikan username dan password kepada pihak lain.
                    </div>
                </form>

                <p class="mt-8 text-center text-xs text-slate-400">
                    © 2026 Regional Strategy and Finance · Region 7 Jakarta 2
                </p>
            </div>
        </div>
    </div>
</template>

<style scoped>
.grid-bg {
    background-image:
        linear-gradient(rgba(148, 163, 184, 0.12) 1px, transparent 1px),
        linear-gradient(90deg, rgba(148, 163, 184, 0.12) 1px, transparent 1px);
    background-size: 32px 32px;
}
</style>
