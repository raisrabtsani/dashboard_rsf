import axios from 'axios';

/**
 * Satu-satunya tempat axios untuk domain Simpanan (DPK).
 *
 * DILARANG memanggil axios langsung di komponen — komponen mengimpor fungsi
 * dari sini. Salin bentuk file ini untuk domain berikutnya
 * (pinjamanApi.js, recoveryApi.js, ...).
 */

/**
 * Buang filter kosong supaya query string tetap bersih.
 *
 * Catatan keamanan: filter yang dikirim dari sini TIDAK menentukan lingkup data.
 * Middleware `scope` di backend menulis ulang area_id/cabang_id/uker_id sesuai
 * level user, apa pun yang dikirim di sini.
 */
const params = (filter = {}) =>
    Object.fromEntries(
        Object.entries(filter).filter(([, v]) => v !== null && v !== undefined && v !== ''),
    );

const ambil = (namaRoute, filter) =>
    axios.get(route(namaRoute), { params: params(filter) }).then((r) => r.data);

export const fetchFilterOptions = (filter) => ambil('api.simpanan.filter-options', filter);

export const fetchSnapshot = (filter) => ambil('api.simpanan.snapshot', filter);

export const fetchChart = (filter) => ambil('api.simpanan.chart', filter);

export const fetchBranchPencapaian = (filter) => ambil('api.simpanan.branch-pencapaian', filter);

export const fetchCabang = (areaId) =>
    axios.get(route('api.simpanan.cabang', { areaId })).then((r) => r.data);

export const fetchUker = (cabangId) =>
    axios.get(route('api.simpanan.uker', { cabangId })).then((r) => r.data);
