import axios from 'axios';

/**
 * Pabrik service API admin per domain.
 */
export function buatAdminApi(domain, { param = 'tanggal', massal = 'bulan' } = {}) {
    const unggah = (namaRoute, berkas) => {
        const data = new FormData();
        data.append('berkas', berkas);

        return axios.post(route(namaRoute), data).then((r) => r.data);
    };

    return {
        // --- Data aktual ---
        fetchRiwayat: () =>
            axios.get(route(`admin.upload.${domain}.riwayat`)).then((r) => r.data.riwayat ?? []),

        previewAktual: (berkas) => unggah(`admin.upload.${domain}.preview`, berkas),

        uploadAktual: (berkas) => unggah(`admin.upload.${domain}.store`, berkas),

        hapusTanggal: (nilai) =>
            axios.delete(route(`admin.upload.${domain}.hapus`, { [param]: nilai })).then((r) => r.data),

        hapusBulan: (tahun, bulan) =>
            massal === 'tahun'
                ? axios
                    .delete(route(`admin.upload.${domain}.hapus-tahun`, { tahun }))
                    .then((r) => r.data)
                : axios
                    .delete(route(`admin.upload.${domain}.bulk-month`), { data: { tahun, bulan } })
                    .then((r) => r.data),

        urlUnduh: (nilai) => route(`admin.upload.${domain}.unduh`, { [param]: nilai }),

        // --- RKA ---
        fetchRka: () =>
            axios
                .get(route('admin.rka-manage.data', { domain }))
                .then((r) => r.data.ringkasan ?? []),

        uploadRka: (berkas) => unggah(`admin.rka.${domain}.store`, berkas),

        hapusRkaPilihan: (pilihan) =>
            axios
                .delete(route('admin.rka-manage.selected', { domain }), { data: { pilihan } })
                .then((r) => r.data),

        hapusRkaTahun: (tahun) =>
            axios.delete(route(`admin.rka.${domain}.hapus-tahun`, { tahun })).then((r) => r.data),
    };
}

/**
 * Varian bulanan untuk domain seperti Laba.
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
