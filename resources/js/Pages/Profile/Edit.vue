<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

// Halaman Profil hanya berisi form ganti password — identitas & hapus akun
// dikelola admin.
defineProps({ status: { type: String } });

const user = computed(() => usePage().props.auth?.user ?? null);
</script>

<template>
    <Head title="Ganti Password" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-2xl space-y-6">
            <!-- Kartu identitas -->
            <div class="flex items-center gap-4 rounded-2xl bg-gradient-to-r from-brand-700 to-brand-600 p-6 text-white shadow-lg">
                <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-white/15">
                    <svg class="h-7 w-7" viewBox="0 0 24 24" fill="currentColor"><path d="M12 12a5 5 0 100-10 5 5 0 000 10zm0 2c-5 0-9 2.5-9 5.5V22h18v-2.5c0-3-4-5.5-9-5.5z" /></svg>
                </span>
                <div>
                    <p class="text-lg font-bold">{{ user?.name }}</p>
                    <p class="text-sm text-white/70">
                        {{ user?.username }} · {{ user?.tipe ?? user?.role }}
                    </p>
                </div>
            </div>

            <!-- Kartu ganti password -->
            <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-100 sm:p-8">
                <div class="mb-6 flex items-center gap-3">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600">
                        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2h1a2 2 0 012 2v5a2 2 0 01-2 2H4a2 2 0 01-2-2v-5a2 2 0 012-2h1zm2 0h6V7a3 3 0 00-6 0v2z" clip-rule="evenodd" /></svg>
                    </span>
                    <div>
                        <h2 class="text-base font-bold text-slate-900">Ganti Password</h2>
                        <p class="text-sm text-slate-500">Gunakan password yang kuat dan tidak dibagikan ke siapa pun.</p>
                    </div>
                </div>

                <div
                    v-if="status === 'password-updated'"
                    class="mb-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-700"
                >
                    Password berhasil diperbarui.
                </div>

                <UpdatePasswordForm />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
