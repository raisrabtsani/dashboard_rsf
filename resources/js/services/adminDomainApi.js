import axios from 'axios';

/**
 * Pabrik service API admin per domain.
 *
 * Semua domain memakai bentuk route yang sama:
 *   admin.upload.<domain>.{riwayat,store,unduh,hapus,bulk-month}
 *   admin.rka.<domain>.{data,store,hapus-tahun}
 *
 * Domain baru cukup memanggil buatAdminApi('<domain>') — jangan menyalin file
 * service admin per domain.
 */
export function buatAdminApi(domain) {
    const unggah = (namaRoute, berkas) => {
        const data = new FormData();
        data.append('berkas', berkas);

        return axios.post(route(namaRoute), data).then((r) => r.data);
    };

    return {
        // --- Data aktual ---
        fetchRiwayat: () =>
            axios.get(route(`admin.upload.${domain}.riwayat`)).then((r) => r.data.riwayat ?? []),

        uploadAktual: (berkas) => unggah(`admin.upload.${domain}.store`, berkas),

        hapusTanggal: (tanggal) =>
            axios.delete(route(`admin.upload.${domain}.hapus`, { tanggal })).then((r) => r.data),

        hapusBulan: (tahun, bulan) =>
            axios
                .delete(route(`admin.upload.${domain}.bulk-month`), { data: { tahun, bulan } })
                .then((r) => r.data),

        /** Unduhan ditangani browser lewat navigasi biasa. */
        urlUnduh: (tanggal) => route(`admin.upload.${domain}.unduh`, { tanggal }),

        // --- RKA ---
        fetchRka: () =>
            axios.get(route(`admin.rka.${domain}.data`)).then((r) => r.data.ringkasan ?? []),

        uploadRka: (berkas) => unggah(`admin.rka.${domain}.store`, berkas),

        hapusRkaTahun: (tahun) =>
            axios.delete(route(`admin.rka.${domain}.hapus-tahun`, { tahun })).then((r) => r.data),
    };
}

/**
 * Varian BULANAN untuk domain yang datanya per (tahun, bulan), bukan per
 * tanggal harian — mis. Laba. Riwayat, unduh, dan hapus dikunci pada periode
 * (tahun, bulan), plus hapus per tahun.
 */
export function buatAdminApiBulanan(domain) {
    const unggah = (namaRoute, berkas) => {
        const data = new FormData();
        data.append('berkas', berkas);

        return axios.post(route(namaRoute), data).then((r) => r.data);
    };

    return {
        fetchRiwayat: () =>
            axios.get(route(`admin.upload.${domain}.riwayat`)).then((r) => r.data.riwayat ?? []),

        uploadAktual: (berkas) => unggah(`admin.upload.${domain}.store`, berkas),

        hapusPeriode: (tahun, bulan) =>
            axios.delete(route(`admin.upload.${domain}.hapus`, { tahun, bulan })).then((r) => r.data),

        hapusTahun: (tahun) =>
            axios.delete(route(`admin.upload.${domain}.hapus-tahun`, { tahun })).then((r) => r.data),

        urlUnduh: (tahun, bulan) => route(`admin.upload.${domain}.unduh`, { tahun, bulan }),
    };
}
