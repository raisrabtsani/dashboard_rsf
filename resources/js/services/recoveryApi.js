import axios from 'axios';

/**
 * Satu-satunya tempat axios untuk domain Recovery (penagihan kredit hapus buku).
 *
 * DILARANG memanggil axios langsung di komponen — komponen mengimpor fungsi
 * dari sini. Bentuknya menyalin simpananApi.js: filter dikirim apa adanya,
 * lingkup data tetap ditentukan middleware `scope` di backend.
 */

const params = (filter = {}) =>
    Object.fromEntries(
        Object.entries(filter).filter(([, v]) => v !== null && v !== undefined && v !== ''),
    );

const ambil = (namaRoute, filter) =>
    axios.get(route(namaRoute), { params: params(filter) }).then((r) => r.data);

export const fetchFilterOptions = (filter) => ambil('api.recovery.filter-options', filter);

export const fetchSnapshot = (filter) => ambil('api.recovery.snapshot', filter);

export const fetchChart = (filter) => ambil('api.recovery.chart', filter);

export const fetchBranchPencapaian = (filter) => ambil('api.recovery.branch-pencapaian', filter);

export const fetchCabang = (areaId) =>
    axios.get(route('api.recovery.cabang', { areaId })).then((r) => r.data);

export const fetchUker = (cabangId) =>
    axios.get(route('api.recovery.uker', { cabangId })).then((r) => r.data);
