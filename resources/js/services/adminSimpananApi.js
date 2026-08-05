import axios from 'axios';

/**
 * Satu-satunya tempat axios untuk area Admin domain Simpanan
 * (upload data aktual + kelola RKA). Dilarang axios inline di komponen.
 */

const unggah = (namaRoute, berkas) => {
    const data = new FormData();
    data.append('berkas', berkas);

    return axios.post(route(namaRoute), data).then((r) => r.data);
};

// --- Upload data aktual ---------------------------------------------------

export const fetchRiwayat = () =>
    axios.get(route('admin.upload.simpanan.riwayat')).then((r) => r.data.riwayat ?? []);

export const uploadSimpanan = (berkas) => unggah('admin.upload.simpanan.store', berkas);

export const hapusTanggal = (tanggal) =>
    axios.delete(route('admin.upload.simpanan.hapus', { tanggal })).then((r) => r.data);

export const hapusBulan = (tahun, bulan) =>
    axios
        .delete(route('admin.upload.simpanan.bulk-month'), { data: { tahun, bulan } })
        .then((r) => r.data);

/** Unduh ulang: navigasi biasa supaya browser yang menangani unduhan berkas. */
export const urlUnduh = (tanggal) => route('admin.upload.simpanan.unduh', { tanggal });

// --- RKA ------------------------------------------------------------------

export const fetchRka = () =>
    axios.get(route('admin.rka.simpanan.data')).then((r) => r.data.ringkasan ?? []);

export const uploadRka = (berkas) => unggah('admin.rka.simpanan.store', berkas);

export const hapusRkaTahun = (tahun) =>
    axios.delete(route('admin.rka.simpanan.hapus-tahun', { tahun })).then((r) => r.data);
