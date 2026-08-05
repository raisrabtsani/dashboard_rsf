# CLAUDE.md — Dashboard Keragaan RSF

Panduan arsitektur & disiplin kerja untuk repo ini. **Baca file ini sebelum menulis kode.**
Spesifikasi produk lengkap (domain, formula bisnis, aturan per dashboard) ada di
[../PRD.md](../PRD.md) — CLAUDE.md hanya mengatur **bagaimana** kode ditulis, PRD mengatur
**apa** yang dibangun.

Status repo: scaffolding awal (Laravel 12 + Breeze Inertia/Vue + Tailwind + Chart.js).
Domain dashboard belum diimplementasikan — bangun mengikuti pola di bawah.

---

## 1. Stack

| Lapisan  | Teknologi                                                                     |
| -------- | ----------------------------------------------------------------------------- |
| Backend  | PHP 8.3 (`^8.2`), Laravel 12, Inertia-Laravel 2, Sanctum 4, Ziggy 2           |
| Frontend | Vue 3, `@inertiajs/vue3` 2, Tailwind CSS 3 (+`@tailwindcss/forms`), Vite 7    |
| Chart    | `chart.js` 4 + `vue-chartjs` 5 + `chartjs-plugin-datalabels` 2                |
| Database | **MySQL** (dev & produksi), **SQLite in-memory** (test — lihat `phpunit.xml`) |
| Kualitas | Laravel Pint (`pint.json`), PHPUnit 11, GitHub Actions                        |

Tailwind memakai jalur **v3** (`tailwind.config.js` + `postcss.config.js` + direktif
`@tailwind` di `resources/css/app.css`). Jangan campur dengan API Tailwind v4
(`@tailwindcss/vite`, `@import "tailwindcss"`).

## 2. Perintah

```bash
composer run dev      # serve + queue + pail + vite sekaligus
npm run build         # build aset produksi
php artisan test      # WAJIB sebelum & sesudah tiap perubahan backend
./vendor/bin/pint     # format backend — WAJIB sebelum commit
./vendor/bin/pint --test   # yang dijalankan CI (tidak menulis, hanya menggagalkan)
```

---

## 3. Arsitektur: monolit, tanpa REST API terpisah

Aplikasi ini **monolit Laravel + Inertia 2 + Vue 3**. Tidak ada API publik dan tidak ada
`routes/api.php` sebagai layanan terpisah.

- **Halaman** dirender lewat `Inertia::render()` dari `routes/web.php`.
- **Data dinamis** (filter, snapshot KPI, chart, tabel) diambil frontend lewat endpoint
  ber-prefix **`api/*`** yang mengembalikan **JSON**, dipanggil dengan **axios**.
- Endpoint `api/*` itu tetap **route web**: tetap dalam session auth dan middleware
  scoping yang sama seperti halamannya. Bukan token-based, bukan stateless.

```
Browser (Vue 3 + Inertia)
   │ page visit (Inertia)              │ data dinamis (axios → JSON)
   ▼                                   ▼
routes/web.php ──► middleware [auth, scope, (admin|present|hourly)]
   │
   ▼
Controller tipis (parse request) ──► Service (semua query & kalkulasi)
   │                                        │
   ▼                                        ▼
Inertia::render(Pages/…)                  MySQL
```

## 4. Pola per domain dashboard (wajib, tanpa pengecualian)

Setiap domain dashboard terdiri dari **tepat lima berkas peran**:

| Peran                  | Lokasi                                            | Tanggung jawab                                             |
| ---------------------- | ------------------------------------------------- | ---------------------------------------------------------- |
| 1. Controller **tipis** | `app/Http/Controllers/<Domain>DashboardController.php` | Hanya parse & validasi request, panggil service, kembalikan response |
| 2. Service backend     | `app/Services/<Domain>Service.php`                | **Semua** query Eloquent/DB & kalkulasi bisnis             |
| 3. Halaman Inertia     | `resources/js/Pages/<Domain>Dashboard/Index.vue`  | Satu halaman per domain                                    |
| 4. Service API frontend | `resources/js/services/<domain>Api.js`            | **Satu-satunya** tempat axios untuk domain ini             |
| 5. Route + endpoint    | `routes/web.php`, prefix `api/<domain>/*`         | Nama route `api.<domain>.*`                                |

### Aturan yang tidak boleh dilanggar

- **Controller tidak boleh berisi query.** Tidak ada `DB::`, `Model::where(...)`, atau
  kalkulasi di controller. Controller yang lebih dari ~10 baris per method biasanya
  tanda logika bocor dari service.
- **Service tidak boleh menyentuh `Request` atau mengembalikan response HTTP.** Service
  menerima parameter biasa (`?int $areaId`, `string $tanggal`, …) dan mengembalikan array.
  Ini yang membuatnya bisa diuji langsung dan dipakai ulang lintas halaman.
- **Dilarang memanggil axios inline di komponen Vue.** Komponen mengimpor fungsi dari
  `resources/js/services/<domain>Api.js`. Tidak ada `axios.get(...)` di dalam `.vue`.
- **Satu halaman per domain**, bukan satu halaman per tab/varian. Varian (mis. tab
  Total/SML/NPL, toggle EDC|QRIS) ditangani di dalam halaman yang sama.
- Endpoint dashboard **membaca filter dari Request** (`area_id`/`cabang_id`/`uker_id`)
  supaya otomatis ikut terkunci middleware scoping. Jangan membaca `auth()->user()`
  langsung untuk menentukan lingkup data di service.

### Contoh bentuk yang benar

```php
// app/Http/Controllers/SimpananDashboardController.php — tipis
public function getSnapshot(Request $request, SimpananService $service)
{
    return response()->json($service->snapshot(
        tanggal: $request->string('tanggal')->toString(),
        areaId: $request->integer('area_id') ?: null,
        cabangId: $request->integer('cabang_id') ?: null,
        ukerId: $request->integer('uker_id') ?: null,
    ));
}
```

```js
// resources/js/services/simpananApi.js — satu-satunya tempat axios
import axios from 'axios';

export const fetchSnapshot = (params) =>
    axios.get(route('api.simpanan.snapshot'), { params }).then((r) => r.data);
```

```vue
<!-- Pages/SimpananDashboard/Index.vue -->
<script setup>
import { fetchSnapshot } from '@/services/simpananApi';
// ❌ import axios from 'axios'  ← dilarang di komponen
</script>
```

Pola endpoint standar tiap domain: `GET /filter-options`, `/snapshot`, `/chart`,
`/branch-pencapaian`, `/cabang/{areaId}`, `/uker/{cabangId}`. Nama method controller
seragam supaya domain baru bisa disalin dari domain yang sudah ada.

### 🧭 TEMPLATE: domain Simpanan (DPK)

**Domain Simpanan sudah selesai end-to-end dan menjadi acuan 7 domain berikutnya.**
Saat membangun domain baru, salin berkas-berkas ini dan ganti nama domainnya —
jangan merancang ulang strukturnya.

| Peran | Berkas acuan |
| --- | --- |
| Migration aktual | [`create_simpanan_table`](database/migrations/2026_08_05_100000_create_simpanan_table.php) |
| Migration RKA | [`create_rka_simpanan_table`](database/migrations/2026_08_05_100001_create_rka_simpanan_table.php) |
| Model | [`Simpanan`](app/Models/Simpanan.php), [`RkaSimpanan`](app/Models/RkaSimpanan.php) |
| Service | [`SimpananService`](app/Services/SimpananService.php) |
| Controller tipis | [`SimpananDashboardController`](app/Http/Controllers/SimpananDashboardController.php) |
| Route | grup `api/simpanan` di [`routes/web.php`](routes/web.php) |
| Service API frontend | [`simpananApi.js`](resources/js/services/simpananApi.js) |
| Halaman | [`SimpananDashboard/Index.vue`](resources/js/Pages/SimpananDashboard/Index.vue) |
| Seeder dummy | [`SimpananDummySeeder`](database/seeders/SimpananDummySeeder.php) |
| Feature test | [`SimpananDashboardTest`](tests/Feature/SimpananDashboardTest.php) |

Yang wajib ditiru dari template itu:

- **Kartu snapshot** memuat nilai posisi + 4 delta (`dtd`/`mtd`/`ytd`/`yoy`) + `target`,
  `pencapaian`, `gap`. Tanggal pembanding di-resolve ke **tanggal tersedia terakhir ≤
  target**, bukan tanggal kalender mentah — kalau tidak, akhir pekan & hari libur bikin
  delta kosong.
- **Label UI "D-1", key data tetap `dtd`.** Jangan mengganti key-nya.
- **Tidak ada data ≠ nol.** Delta tanpa pembanding, dan pencapaian tanpa RKA, bernilai
  `null` — ditampilkan "–", bukan 0 atau 0%.
- **`branch-pencapaian` berpindah grouping sendiri**: tanpa `cabang_id` → per cabang;
  dengan `cabang_id` → per uker (drill-down BO). Satu endpoint, bukan dua.

### 🧭 TEMPLATE: area Admin per domain

Tiap domain punya dua halaman admin (upload aktual + kelola RKA) dengan pola yang sama.
Acuan: [`UploadSimpananController`](app/Http/Controllers/Admin/UploadSimpananController.php),
[`SimpananCsvImportService`](app/Services/SimpananCsvImportService.php),
[`RkaSimpananCsvImportService`](app/Services/RkaSimpananCsvImportService.php),
[`Admin/Upload/Simpanan.vue`](resources/js/Pages/Admin/Upload/Simpanan.vue).

- **Parsing berkas ada di `<Domain>CsvImportService`, TIDAK di controller.** Controller
  admin hanya memvalidasi berkas lalu memanggil service. Formatnya diumumkan lewat
  konstanta `KOLOM` di service dan dikirim ke halaman sebagai prop — satu sumber kebenaran
  untuk backend, UI, dan berkas unduhan.
- **Nama kolom WAJIB toleran.** Berkas dari unit bisnis disusun manusia di Excel, jadi
  nama kolomnya tidak pernah persis: `Produk` vs `produk`, `" RKA "` (berspasi di dalam
  nama kolom!) vs `target`. Setiap importer mendeklarasikan konstanta `ALIAS` dan
  memetakannya lewat [`App\Support\PetaKolom`](app/Support/PetaKolom.php) — pencocokan
  abai huruf besar/kecil, spasi, dan underscore. **Menuntut nama kolom yang persis akan
  menolak berkas yang sebenarnya benar isinya.**
- **Bulan bisa berupa nama.** Berkas RKA menulis `Januari`, bukan `1`. Urai lewat
  [`App\Support\Bulan::uraiAtauGagal()`](app/Support/Bulan.php) (menerima angka 1-12,
  nama Indonesia, singkatan, dan nama Inggris). Jangan bikin tabel bulan sendiri.
- **Angka ikut format Excel**: `" 10,093,170,076 "` — buang spasi, koma, dan NBSP sebelum
  `is_numeric()`.
- **Sel nilai KOSONG bukan nol.** Di berkas RKA, target kosong berarti uker itu memang
  tidak punya target untuk produk itu (unit mikro tidak menjual Giro). Barisnya
  **dilewati**, bukan disimpan sebagai 0 — tanpa baris RKA, pencapaian otomatis tampil
  `null`, sesuai aturan §7. Jumlah baris yang dilewati **wajib dilaporkan** di pesan
  sukses supaya tidak hilang diam-diam.
- **Tolak baris kembar di dalam berkas.** `upsert()` hanya menyimpan salah satu dari dua
  baris berkunci sama, jadi sebagian target bisa hilang tanpa jejak. Periksa duplikat
  sebelum menulis dan tolak berkasnya.
- **`cabang_id` diambil dari master, bukan dari berkas.** Master adalah sumber kebenaran
  hubungan uker→cabang; kolom cabang di berkas hanya informatif.
- **Semua pembacaan berkas lewat [`App\Support\Spreadsheet::baca()`](app/Support/Spreadsheet.php)**
  (CSV → `Csv::baca`, XLSX/XLS → PhpSpreadsheet). Jangan memanggil PhpSpreadsheet langsung
  di service importer. Sel tanggal Excel adalah serial number, bukan teks — helper itu
  sudah mengonversinya, kalau tidak `2026-08-05` terbaca `46234`.
- **⚠️ Format berkas ditentukan dari NAMA ASLI kiriman klien, bukan dari path.**
  PHP menyimpan unggahan sebagai berkas sementara `phpA1B2.tmp`, jadi
  `$file->getRealPath()` TIDAK berekstensi asli. Importer wajib meneruskan
  `$file->getClientOriginalName()` ke `Spreadsheet::baca(..., namaAsli: ...)`.
  Nama itu juga dipakai di pesan error — admin mengenali "Target Simpanan.csv",
  bukan "phpA1B2.tmp".
- **Dua status kegagalan yang berbeda**, lewat
  [`App\Exceptions\ImportException`](app/Exceptions/ImportException.php):
  **422 `berkas()`** = isi/format berkasnya salah → perbaiki berkasnya;
  **409 `bentrok()`** = berkasnya benar tapi datanya sudah ada → hapus dulu.
  Jangan menyeragamkannya jadi satu status: admin tidak bisa membedakan "file saya salah"
  dari "tanggalnya sudah ada", dan itu persis pertanyaan pertama mereka saat upload gagal.
- **Aktual ditolak bila tanggalnya sudah ada** (hapus dulu, lalu unggah ulang).
  **RKA justru di-upsert**, karena target boleh direvisi sepanjang tahun berjalan.
  Perbedaan ini disengaja; jangan diseragamkan.
- **Validasi baris menyebut nomor barisnya** (`Baris 42: ...`), dihitung `index + 2` supaya
  cocok dengan nomor baris yang dilihat user di Excel.
- **Import dibungkus transaksi**: berkas yang gagal di baris ke-N tidak boleh menyisakan
  N−1 baris pertama di database.
- **Riwayat upload diturunkan dari datanya sendiri** (group by tanggal), bukan tabel log
  terpisah — riwayat tidak akan pernah berbohong soal apa yang sebenarnya tersimpan.
- **Hapus per bulan memakai rentang tanggal**, bukan `MONTH()`/`YEAR()` (lihat §8).
- Halaman admin memakai **`AuthenticatedLayout` yang sama** dengan dashboard.

### Manajemen user

- Status akun memakai **`is_locked`** (tombol kunci/buka), bukan verifikasi email —
  login memakai username, jadi verifikasi email tidak relevan dan sudah dihapus.
  User terkunci **ditolak login walau kredensialnya benar**; pemeriksaannya ada di
  `LoginRequest::authenticate()` **setelah** `Auth::attempt` berhasil, supaya pesan
  errornya tidak membocorkan username mana yang terdaftar.
- **Ganti password lewat form edit user**; dikosongkan = tidak diubah
  (`UserAdminService::perbarui()` membuang key `password` yang kosong). Tidak ada route
  reset-password terpisah.
- Admin **tidak bisa mengunci atau menghapus akunnya sendiri** (422).

### Domain kedua: Pinjaman — apa yang boleh berbeda

Pinjaman dibangun dari template Simpanan. Yang **berbeda** menandai batas mana yang boleh
divariasikan tanpa merusak pola:

- **Dimensi tambahan (`kualitas`) diserap ke query dasar**, bukan jadi service terpisah.
  Tab Total/SML/NPL hanya memilih `whereIn('kualitas', ...)`; OS = Lancar+SML+NPL.
- **Endpoint di luar pola boleh ditambah** (`/chart-segmen`, `/produk`) selama enam
  endpoint bakunya tetap ada dan bentuknya sama.
- **Label kolom delta dikirim backend** lewat `label_delta`, bukan ditebak frontend.
  Tab SML/NPL mengganti YoY dengan MoM ("Date to Date"). Key data ikut berubah (`yoy` →
  `mom`) — kalau label dan key ditentukan di tempat berbeda, keduanya pasti akan melenceng.
- **MoM = `subMonthNoOverflow()`**, supaya 31 Mar tidak melompat ke awal Maret. Bila
  tanggal itu tak ada datanya, fallback ke tanggal **terakhir yang tersedia di bulan
  tersebut** — bukan tanggal terdekat sebelumnya, supaya perbandingannya tetap antar-bulan
  yang benar.
- **Arah pencapaian bisa terbalik.** Backend mengirim flag `inverse`; frontend memakai
  `pctClsArah`/`pctBadgeClsArah`/`deltaCls(nilai, inverse)`. Ambang dan peta warnanya satu
  sumber di [`pencapaian.js`](resources/js/utils/pencapaian.js) — **jangan menulis ambang
  sendiri di halaman**.
- **Perlakuan rollup 855 ditentukan per domain**, bukan global: Simpanan mengecualikannya,
  Pinjaman **mengikutkannya** (segmen Menengah dikelola Region). Konsekuensi yang
  disengaja: jumlah baris tabel Kinerja Cabang lebih kecil dari kartu Total, karena 855
  tetap disembunyikan dari daftar cabang. Beri catatan di UI, jangan "dirapikan".
- **Aturan duplikat importer boleh beda**: Simpanan blokir per tanggal; Pinjaman per
  **(tanggal + segmen)**, sehingga segmen baru menyusul tetap bisa masuk sementara segmen
  lama dilewati dan dilaporkan.

### Menghindari duplikasi antar domain

Saat menambah domain ketiga, yang sudah dipakai bersama — **pakai ulang, jangan salin**:

| Bagian | Berkas |
| --- | --- |
| Filter Area→Cabang→Uker + opsi dropdown | [`Concerns\MenyaringOrganisasi`](app/Services/Concerns/MenyaringOrganisasi.php) |
| Perhitungan delta | [`Support\Delta`](app/Support/Delta.php) |
| Halaman admin upload & RKA | [`Admin/UploadDomain.vue`](resources/js/Components/Admin/UploadDomain.vue), [`Admin/RkaDomain.vue`](resources/js/Components/Admin/RkaDomain.vue) |
| Service API admin | [`buatAdminApi(domain)`](resources/js/services/adminDomainApi.js) |
| Baca berkas & peta kolom | `Support\Spreadsheet`, `Support\PetaKolom`, `Support\Bulan` |

Halaman admin per domain seharusnya hanya belasan baris yang meneruskan props. Kalau
menyalin lebih dari itu, berarti ada yang belum diekstrak.

### Konvensi frontend (berlaku semua domain)

- **Pola pending vs applied.** State `pending` berubah saat user mengutak-atik filter dan
  **tidak** memicu fetch; `applied` hanya berubah saat tombol **Terapkan** ditekan. Yang
  dikirim ke backend selalu `applied`. Pengecualian yang disengaja: dropdown drill-down
  tabel memuat ulang tabelnya saja secara langsung.
- **Komponen reusable — pakai ulang, dilarang bikin varian per halaman:**
  [`KpiCard`](resources/js/Components/KpiCard.vue),
  [`ApplyButton`](resources/js/Components/ApplyButton.vue),
  [`LoadingOverlay`](resources/js/Components/LoadingOverlay.vue),
  [`SortArrow`](resources/js/Components/SortArrow.vue),
  [`LineChart`](resources/js/Components/LineChart.vue).
- **Helper bersama:** [`formatAngka.js`](resources/js/utils/formatAngka.js) (Jt/M/T),
  [`pencapaian.js`](resources/js/utils/pencapaian.js) (≥100% hijau, 95–100% kuning,
  <95% merah), [`useTableSort.js`](resources/js/utils/useTableSort.js),
  [`scope.js`](resources/js/utils/scope.js), [`chartColors.js`](resources/js/utils/chartColors.js).
- **Satuan di frontend selalu JUTA.** `formatAngka()` menerima juta dan menskalakannya
  ke Jt/M/T. Jangan pernah membagi 1.000.000 di komponen — backend sudah melakukannya.
- **Halaman baru wajib masuk menu navigasi.** Daftar menu ada di konstanta `MENU` di
  [`AuthenticatedLayout`](resources/js/Layouts/AuthenticatedLayout.vue); domain baru cukup
  menambah satu baris di sana. Halaman yang hanya bisa dicapai dengan mengetik URL sama
  saja dengan belum jadi.

## 5. Master organisasi: hierarki, ID manual, dan seeder berbasis CSV

Hierarki **Region → Cabang → Uker**, dengan **Area** sebagai dimensi menyilang yang
menempel di level cabang. Satu cabang punya **`region_id` DAN `area_id`** sekaligus:
`region_id` = garis organisasi, `area_id` = pengelompokan yang dipakai filter dashboard
(filter memakai dimensi **Area → Cabang → Uker**, bukan Region).

| Tabel    | Model    | Sumber data                                                                      |
| -------- | -------- | -------------------------------------------------------------------------------- |
| `region` | `Region` | `code_region.csv`                                                                |
| `areas`  | `Area`   | pasangan unik (`id_area`, `Nama Area`) di `peta_area.csv`                        |
| `cabang` | `Cabang` | tiap `id_cabang` unik di `code_uker.csv`; `area_id` di-join lewat `peta_area.csv` |
| `uker`   | `Uker`   | tiap baris `code_uker.csv`                                                       |

### ID manual — bukan auto-increment

ID region, cabang, dan uker **berasal dari sistem sumber BRI**, bukan dari database.
Migration memakai `$table->unsignedInteger('id')->primary()` (tanpa `id()`/`increments()`),
dan setiap model master menyetel:

```php
public $incrementing = false;
protected $keyType = 'int';
```

Konsekuensi yang gampang terlewat: **jangan pernah pakai `factory()` tanpa `id` eksplisit**
untuk tabel master, dan jangan mengurutkan/mengasumsikan id berurutan.

### Seeder master WAJIB membaca CSV

[`MasterSeeder`](database/seeders/MasterSeeder.php) membaca ketiga file di
`database/seeders/data/` **secara langsung**. Aturannya:

- **Dilarang meng-hardcode baris master di dalam kode PHP.** Ada cabang/uker baru?
  Ganti CSV-nya, jalankan ulang seeder. Jangan menambah array di seeder.
- Seeder **idempoten** (`upsert` dengan primary key manual) — aman dijalankan berulang,
  tanpa truncate. Jangan menambahkan `truncate()` ke seeder master.
- Semua pembacaan CSV lewat satu helper: [`App\Support\Csv::baca()`](app/Support/Csv.php).
  **Jangan menulis `fgetcsv()` sendiri** di seeder/importer baru. Helper itu sudah
  menangani BOM UTF-8 (file diekspor dari Excel — tanpa dilucuti, nama kolom pertama
  terbaca `\u{FEFF}id_region`), validasi kolom wajib, dan baris ragged.
- File yang strukturnya berubah **gagal keras** dengan pesan jelas, bukan diam-diam
  menghasilkan tabel kosong.

### Tipe uker diturunkan, tidak ada di sumber

`code_uker.csv` tidak punya kolom tipe. Tipe diturunkan lewat
[`Uker::tipeDari()`](app/Models/Uker.php) dan **urutan pemeriksaannya penting**:

| Kondisi                   | Tipe   |
| ------------------------- | ------ |
| `id_uker == id_cabang`    | `BO`   |
| nama diawali `SBO `       | `SBO`  |
| nama diawali `Unit `      | `UNIT` |
| nama diawali `KK `        | `KK`   |

Baris BO dikenali dari **id**, bukan nama — jangan dibalik. Nama yang tak cocok pola mana
pun akan diperingatkan di output seeder dan jatuh ke `UNIT` (paling tidak berbahaya secara
akses); perbaiki datanya, jangan diamkan peringatannya.

### Rollup Region Office `855`

`855` adalah entitas rollup Region, **sengaja tidak ada di `code_uker.csv`** karena bukan
kantor operasional. Tapi data kelolaan level Region (mis. Pinjaman segmen **Medium**)
memakai `cabang_id = 855`, jadi seeder **wajib** membuat baris bayangan di `cabang` **dan**
`uker` (tipe `REGION`) dari `code_region.csv` — tanpa itu import tersebut gagal validasi
foreign key.

- Angka 855 punya **sumber tunggal**: [`Region::OFFICE_ID`](app/Models/Region.php).
  Dilarang menulis `855` sebagai literal di service, controller, atau komponen.
- 855 **disembunyikan** dari dropdown BO dan tabel "Kinerja Cabang" lewat scope
  `tanpaRegionOffice()` di `Cabang` dan `Uker`.
- Perlakuan per domain **berbeda-beda** (ada yang mengecualikan 855, ada yang justru
  mengikutkannya dalam total) — ikuti §8 & BR-15 di [../PRD.md](../PRD.md), dan kunci
  keputusannya dengan test.

## 6. Autentikasi & lingkup akses data

### Login username, alur lain dimatikan

- Login memakai **`username`**, bukan email. Kolom `email` nullable dan **tidak dipakai
  di UI** — jangan menambahkannya ke form mana pun.
- Yang hidup hanya **login, logout, ganti password**. Registrasi publik, reset password
  via email, verifikasi email, konfirmasi password, update profil, dan hapus akun sendiri
  **sudah dihapus** — akun dikelola admin lewat `/admin/users`.
- [`Auth\DisabledAuthFeaturesTest`](tests/Feature/Auth/DisabledAuthFeaturesTest.php)
  mengunci semuanya (404 + nama route tidak terdaftar). Kalau test itu tiba-tiba merah,
  ada yang menghidupkan kembali alur mati — jangan "perbaiki" dengan melonggarkan test.

### Tiga level akses

`role` (`admin`/`user`) + `tipe` (`RO`/`BO`/`SBO`/`UNIT`/`KK`) dipetakan oleh accessor
[`User::access_level`](app/Models/User.php):

| role / tipe        | access_level   | Lingkup                                     |
| ------------------ | -------------- | ------------------------------------------- |
| role `admin`       | `LEVEL_ALL`    | semua data, semua filter bebas              |
| tipe `RO`          | `LEVEL_ALL`    | semua data, tapi **bukan** admin            |
| tipe `BO`          | `LEVEL_CABANG` | terkunci 1 cabang, drill-down uker diizinkan |
| tipe `SBO`/`UNIT`/`KK` | `LEVEL_UKER` | terkunci 1 uker                            |

Aturan penting: **default-nya `LEVEL_UKER`** (tersempit). Tipe kosong atau tak dikenal
tidak boleh berujung melihat semua data. Kalau menambah tipe baru, tambahkan ke `match`
secara eksplisit — jangan mengandalkan cabang `default`.

`access_level` di-`$appends` ke model, dan ikut dikirim di props Inertia `auth.user`
(payload-nya **dikurasi** di `HandleInertiaRequests`, bukan seluruh model).

### `scope` — penegakan ada di backend

Middleware [`EnforceUserScope`](app/Http/Middleware/EnforceUserScope.php) (alias `scope`)
**menulis ulang** `area_id`/`cabang_id`/`uker_id` di Request **sebelum** controller
membacanya. Nilai kiriman klien dibuang dan diganti milik user sendiri.

- **Semua route dashboard & `api/*` wajib berada di grup `['auth', 'scope']`.** Route
  dashboard di luar grup itu = kebocoran data lintas kantor.
  `ScopeEnforcementTest::test_semua_route_dashboard_memakai_middleware_scope` menjaganya.
- **Endpoint dashboard wajib membaca filter dari Request**, bukan dari `auth()->user()`.
  Membaca dari Request = otomatis ikut terkunci. Membaca user langsung = melewati gerbang.
- Middleware menimpa **query bag dan request bag sekaligus**. `merge()` saja tidak cukup:
  untuk GET ia hanya menyentuh query, untuk POST hanya request — sisanya masih memuat
  nilai kiriman klien dan terbaca lewat `$request->query()`/`$request->post()`.
- Akun berlevel sempit tapi datanya cacat (BO tanpa `cabang_id`, uker tanpa `uker_id`)
  **ditolak 403**, bukan dibiarkan lolos tanpa filter — "tanpa filter" justru artinya
  membuka semua data.
- **Endpoint ranking antar entitas** (tabel "Kinerja Cabang") dibalas **kosong** untuk
  user `LEVEL_UKER` — mereka tidak berhak melihat posisi kantor lain. Daftarnya ada di
  `EnforceUserScope::BRANCH_ROUTES`; setiap domain baru **wajib mendaftarkan nama route
  ranking-nya di sana**. Itu satu-satunya tempat aturan ini ditulis.
- Penyembunyian filter di frontend hanya **kosmetik/UX**. Jangan pernah menjadikannya
  satu-satunya pengaman.

### Seeding akun: `UserSeeder` vs `users:sync`

Akun berasal dari `database/seeders/data/user.csv` (kolom `id_region`, `id_cabang`,
`id_uker`, `User`, `Nama`, `Type Uker`, `Role`, `Password`). Logika baca & normalisasinya
terpusat di [`UserCsvImportService`](app/Services/UserCsvImportService.php) — dipakai
seeder **dan** command, supaya aturannya tidak bercabang dua.

| Jalur | Perilaku | Kapan dipakai |
| --- | --- | --- |
| `UserSeeder` (`db:seed`) | **TRUNCATE** + bulk insert | inisialisasi awal, dev/test |
| `php artisan users:sync` | upsert per `username`, tanpa truncate | **pemutakhiran produksi** |

- **Jangan menjalankan `db:seed` di produksi.** `UserSeeder` menghapus semua akun beserta
  password yang sudah diganti user. Pemutakhiran daftar kantor/nama → `users:sync`.
- `users:sync` sengaja **tidak** memasukkan `password` ke daftar kolom yang di-update:
  user baru dapat password dari CSV, user lama passwordnya tidak tersentuh. Kalau nanti
  menambah kolom baru ke tabel users, tambahkan ke daftar update — **kecuali** `password`.
- `UserSeeder` wajib dipanggil **setelah** `MasterSeeder`: `users.cabang_id`/`uker_id`
  punya foreign key ke `cabang`/`uker`.
- **Hash dihitung sekali per password unik**, bukan per baris. Semua akun memakai password
  seragam, jadi ini memangkas 259 pemanggilan bcrypt jadi satu (di `BCRYPT_ROUNDS=12`
  bedanya ~78 detik vs ~5 detik untuk seluruh `migrate:fresh --seed`). Pola yang sama
  berlaku untuk importer massal lain.
- Normalisasi `Type Uker` → `users.tipe`: `Unit` → `UNIT`, `Kantor Kas` → `KK`,
  `RO`/`BO`/`SBO` apa adanya. Nilai yang tak dikenali **melempar exception** — tipe
  menentukan `access_level`, jadi menebak di sini sama dengan menebak seberapa banyak data
  yang boleh dilihat orang.
- `cabang_id`/`uker_id` diambil **apa adanya** dari file (RO = 855/855, BO
  `id_cabang == id_uker`, uker pakai `id_uker`-nya). Jangan menurunkannya ulang dari nama.
- Password default `RSF12345` hanya **fallback** untuk sel `Password` yang kosong; nilai
  di file yang menang. ⚠️ Password seragam ter-commit di CSV adalah risiko yang diketahui.

### `admin` — gerbang area Admin

[`AdminMiddleware`](app/Http/Middleware/AdminMiddleware.php) (alias `admin`) memeriksa
**role**, bukan `access_level`: user RO berlevel `LEVEL_ALL` (boleh lihat semua data) tapi
**tidak** boleh mengelola upload, RKA, dan user.

### Menguji perubahan keamanan

Test lingkup akses harus **menyerang dari sisi klien** — kirim `cabang_id` milik kantor
lain lewat query string dan body, lalu pastikan balasan tetap lingkup sendiri. Setelah
menulisnya, lumpuhkan sebentar middleware-nya dan pastikan test itu **benar-benar merah**;
test keamanan yang tetap hijau saat pengamannya dicabut tidak menjaga apa pun.

## 7. Satuan uang: rupiah penuh di DB, juta di tampilan

- Semua nilai uang **disimpan dalam rupiah penuh** di database. Importer tidak boleh
  membagi apa pun sebelum menyimpan.
- Konversi ke **juta** hanya lewat satu helper: [`App\Support\Satuan::toJuta()`](app/Support/Satuan.php)
  (bagi 1.000.000).
- **Dilarang** menulis `/ 1000000`, `/ 1e6`, atau konstanta juta lain yang tersebar di
  service/controller/komponen. Kalau butuh konversi di dalam SQL, tetap rujuk
  `Satuan::JUTA` sebagai sumber angkanya.
- **Dilarang** "auto-detect satuan" (menebak apakah angka sudah dalam juta). Satuannya
  sudah ditentukan kontrak ini.
- `Satuan::toJuta(null)` mengembalikan `null`, bukan `0`. Bulan/tanggal tanpa data
  bermakna "tidak ada data" dan harus tetap `null` sampai ke chart.

## 8. Test & portabilitas query

- Jalankan `php artisan test` **sebelum dan sesudah** setiap perubahan backend. Kalau
  sebelum-nya sudah merah, itu informasi penting — jangan mulai menumpuk perubahan di atas
  suite yang sudah gagal.
- Feature test berjalan di **SQLite in-memory** (`phpunit.xml`), sedangkan produksi MySQL.
  Konsekuensinya query harus **portable**:

  ```php
  // ❌ MONTH()/YEAR() mentah — SQLite tidak punya fungsi ini, test langsung gagal
  ->selectRaw('MONTH(tanggal) as bulan')
  ->whereRaw('MONTH(tanggal) = ?', [$bulan])

  // ✅ builder Laravel (dikompilasi per driver: MySQL MONTH(), SQLite strftime())
  ->whereYear('tanggal', $tahun)
  ->whereMonth('tanggal', $bulan)

  // ✅ paling aman & paling cepat (bisa pakai index): rentang tanggal
  ->whereBetween('tanggal', [$awalBulan, $akhirBulan])

  // ✅ agregasi bulanan: group by tanggal, turunkan bulannya di PHP
  ```

- Aturan yang sama berlaku untuk `DATE_FORMAT`, `YEAR()`, `DATEDIFF`, `IF()`,
  `GROUP_CONCAT` — semuanya spesifik MySQL. Kalau butuh raw SQL, sediakan cabang per
  driver atau pindahkan kalkulasinya ke PHP.

### ⚠️ Kolom tanggal: JANGAN pakai cast `date`

Cast `date`/`datetime` Laravel **menulis** nilai dengan format `Y-m-d H:i:s`. Kolom `DATE`
MySQL memotong bagian jamnya, tapi **SQLite typeless menyimpannya apa adanya** —
`'2026-03-10 00:00:00'`. Akibatnya `whereIn('tanggal', ['2026-03-10'])` **cocok di
produksi tapi tidak cocok di test**: bug diam-diam yang arahnya terbalik dari biasanya
(produksi benar, test salah), jadi sangat mudah "diperbaiki" ke arah yang keliru.

Pola yang benar — normalkan ke string `Y-m-d` di model (lihat [`Simpanan`](app/Models/Simpanan.php)):

```php
protected function tanggal(): Attribute
{
    return Attribute::make(
        get: fn ($v) => $v === null ? null : Carbon::parse($v)->toDateString(),
        set: fn ($v) => $v === null ? null : Carbon::parse($v)->toDateString(),
    );
}
```

`SimpananDashboardTest::test_tanggal_tersimpan_sebagai_y_m_d_tanpa_komponen_jam`
mengunci ini. Tanggal posisi memang tidak punya komponen jam, jadi tidak ada yang hilang.

### Fixture test unggahan harus meniru unggahan sungguhan

Test unggahan berkas WAJIB memakai berkas sementara yang **tidak** berekstensi asli
(`tempnam()`), dengan nama asli dikirim terpisah lewat `UploadedFile` — persis seperti
yang dilakukan PHP pada unggahan HTTP.

Fixture yang membuat berkas sementara ber-`.csv` membuat test hijau sementara produksi
gagal 500, karena importer diam-diam membaca ekstensi dari path. Bug ini lolos sekali
dan hanya ketahuan saat admin mengunggah berkas sungguhan.
`SimpananImportTest::test_format_ditentukan_dari_nama_asli_bukan_dari_berkas_sementara`
mengunci hal ini.

Aturan umumnya: **kalau fixture test lebih rapi daripada input nyata, test itu sedang
menguji jalur yang tidak pernah dipakai.**

### Mengubah indeks unique yang menopang foreign key

MySQL memakai indeks unique yang **diawali kolom FK** untuk menopang foreign key itu.
Men-drop indeks tersebut lebih dulu ditolak dengan errno 1553 ("needed in a foreign key
constraint") — dan karena DDL MySQL auto-commit, migration bisa berhenti di tengah:
kolomnya sudah bertambah tapi migration tidak tercatat.

Urutannya harus **buat indeks pengganti dulu, baru drop yang lama** (lihat
`2026_08_05_140000_add_segmentasi_to_rka_simpanan_table`). SQLite tidak mempermasalahkan
urutan ini, jadi test **tidak akan menangkapnya** — periksa langsung di MySQL.

### Timezone

`config/app.php` memakai `Asia/Jakarta`. Dengan UTC, `Carbon::today()` masih menunjuk
kemarin sepanjang pukul 17.00–24.00 WIB — tanggal default seluruh dashboard mundur sehari
tiap sore. Jangan mengembalikannya ke UTC.
- Setiap domain dashboard mendapat feature test sebagai jaring pengaman perilaku, dan
  setiap formula bisnis (lihat §11 PRD) dikunci minimal satu test.

## 9. Format kode & gerbang CI

- Backend diformat **Laravel Pint** dengan house style terkunci di
  [`pint.json`](pint.json). Jalankan `./vendor/bin/pint` sebelum commit.
- [`.github/workflows/ci.yml`](.github/workflows/ci.yml) menjalankan tiap push & PR:
  1. `./vendor/bin/pint --test` — gagal kalau ada file belum terformat
  2. `php artisan test`
  3. `npm run build`

  Ketiganya **gerbang**, bukan saran. Jangan menonaktifkan langkah CI untuk "sementara".
- Kalau sebuah aturan Pint terasa salah, ubah `pint.json` lalu format ulang seluruh repo
  dalam satu commit terpisah — jangan menambah pengecualian per file.

## 10. Environment

- [`.env.example`](.env.example) **wajib ada dan ter-track git** — CI menyalinnya jadi
  `.env`. `.gitignore` memakai pola `.env.*` dengan negasi `!.env.example` tepat di
  bawahnya; **jangan pindahkan atau hapus baris negasi itu**, dan jangan menambah pola
  `.env*` baru di bawahnya.
- Setiap variabel env baru **harus** ditambahkan ke `.env.example` (nilai kosong/dummy)
  pada commit yang sama dengan kode yang membacanya.
- Nilai yang sudah ditetapkan dan tidak boleh diubah tanpa alasan:
  - `APP_NAME="Dashboard Keragaan RSF"` (dipakai judul tab)
  - `SESSION_DRIVER=database` (prasyarat fitur "online sekarang")
  - `SESSION_LIFETIME=10` (idle timeout 10 menit)
- Jangan pernah commit `.env`, dump database, atau file sumber berisi data nasabah.

## 11. Struktur direktori

```
app/
  Http/Controllers/          <Domain>DashboardController (tipis), Admin/*
  Http/Middleware/           AdminMiddleware, EnforceUserScope, …
  Console/Commands/          users:sync, import:* (CLI operasional)
  Models/                    Region, Area, Cabang, Uker, User + model domain
  Services/                  <Domain>Service — query & kalkulasi; *CsvImportService
  Support/                   Satuan.php (satuan uang), Csv.php (pembaca CSV bersama)
database/
  migrations/                skema; dilarang edit migration lama in-place
  seeders/MasterSeeder.php   master organisasi — baca CSV, jangan hardcode
  seeders/UserSeeder.php     akun massal (TRUNCATE — produksi pakai users:sync)
  seeders/data/*.csv         code_region, code_uker, peta_area, user
resources/js/
  Pages/<Domain>Dashboard/   halaman Inertia
  Layouts/                   DashboardLayout.vue (layout tunggal)
  Components/                komponen reusable (KpiCard, ApplyButton, …)
  services/<domain>Api.js    satu-satunya tempat axios
  utils/                     helper bersama (format angka, tanggal, warna chart)
routes/web.php               halaman + endpoint api/*
tests/Feature|Unit/
```

## 12. Definition of done (perubahan backend)

1. `php artisan test` hijau (dan dijalankan juga **sebelum** mulai).
2. `./vendor/bin/pint` sudah dijalankan; `pint --test` bersih.
3. Tidak ada query di controller, tidak ada axios di komponen.
4. Route dashboard/`api/*` baru berada di grup `['auth', 'scope']`, dan endpoint-nya
   membaca filter dari Request (bukan dari `auth()->user()`).
4. Tidak ada `MONTH()`/`YEAR()` mentah atau fungsi khusus MySQL lain.
5. Tidak ada pembagian 1.000.000 di luar `Satuan`, tidak ada literal `855` di luar
   `Region::OFFICE_ID`.
6. Data master baru masuk lewat CSV di `database/seeders/data/`, bukan lewat kode.
7. Migration baru — tidak mengedit migration lama in-place.
8. Variabel env baru sudah masuk `.env.example`.
9. `npm run build` lolos kalau ada perubahan frontend.
