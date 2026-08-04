<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

// Kerangka area Admin. Sub-halaman (upload aktual, RKA, manajemen user,
// aktivitas pengguna) menyusul — seluruh grup /admin sudah dijaga middleware
// 'admin' di routes/web.php.
</script>

<template>
    <Head title="Admin" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Admin
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="bg-white p-6 shadow sm:rounded-lg">
                    <p class="text-sm text-gray-600">
                        Area Admin. Sub-halaman upload data, RKA, manajemen
                        user, dan aktivitas pengguna akan ditambahkan di sini.
                    </p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
