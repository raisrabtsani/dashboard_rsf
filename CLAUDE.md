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
`/branch`, `/cabang/{areaId}`, `/uker/{cabangId}`. Nama method controller seragam
(`getSnapshot`, `getChart`, …) supaya domain baru bisa disalin dari domain yang sudah ada.

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
- File master diekspor dari Excel dan **ber-BOM UTF-8**; pembaca CSV melucuti BOM di
  kolom pertama. Kalau menambah importer CSV baru, tangani hal yang sama — kalau tidak,
  nama kolom pertama terbaca sebagai `\u{FEFF}id_region`.
- Kolom wajib divalidasi saat baca; file yang strukturnya berubah harus **gagal keras**
  dengan pesan jelas, bukan diam-diam menghasilkan master kosong.

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

## 6. Satuan uang: rupiah penuh di DB, juta di tampilan

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

## 7. Test & portabilitas query

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
- Setiap domain dashboard mendapat feature test sebagai jaring pengaman perilaku, dan
  setiap formula bisnis (lihat §11 PRD) dikunci minimal satu test.

## 8. Format kode & gerbang CI

- Backend diformat **Laravel Pint** dengan house style terkunci di
  [`pint.json`](pint.json). Jalankan `./vendor/bin/pint` sebelum commit.
- [`.github/workflows/ci.yml`](.github/workflows/ci.yml) menjalankan tiap push & PR:
  1. `./vendor/bin/pint --test` — gagal kalau ada file belum terformat
  2. `php artisan test`
  3. `npm run build`

  Ketiganya **gerbang**, bukan saran. Jangan menonaktifkan langkah CI untuk "sementara".
- Kalau sebuah aturan Pint terasa salah, ubah `pint.json` lalu format ulang seluruh repo
  dalam satu commit terpisah — jangan menambah pengecualian per file.

## 9. Environment

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

## 10. Struktur direktori

```
app/
  Http/Controllers/          <Domain>DashboardController (tipis), Admin/*
  Http/Middleware/           AdminMiddleware, EnforceUserScope, …
  Models/                    Region, Area, Cabang, Uker + model domain
  Services/                  <Domain>Service — query & kalkulasi
  Support/                   Satuan.php (satuan uang), Bulan.php (rencana: konstanta bulan)
database/
  migrations/                skema; dilarang edit migration lama in-place
  seeders/MasterSeeder.php   master organisasi — baca CSV, jangan hardcode
  seeders/data/*.csv         code_region.csv, code_uker.csv, peta_area.csv
resources/js/
  Pages/<Domain>Dashboard/   halaman Inertia
  Layouts/                   DashboardLayout.vue (layout tunggal)
  Components/                komponen reusable (KpiCard, ApplyButton, …)
  services/<domain>Api.js    satu-satunya tempat axios
  utils/                     helper bersama (format angka, tanggal, warna chart)
routes/web.php               halaman + endpoint api/*
tests/Feature|Unit/
```

## 11. Definition of done (perubahan backend)

1. `php artisan test` hijau (dan dijalankan juga **sebelum** mulai).
2. `./vendor/bin/pint` sudah dijalankan; `pint --test` bersih.
3. Tidak ada query di controller, tidak ada axios di komponen.
4. Tidak ada `MONTH()`/`YEAR()` mentah atau fungsi khusus MySQL lain.
5. Tidak ada pembagian 1.000.000 di luar `Satuan`, tidak ada literal `855` di luar
   `Region::OFFICE_ID`.
6. Data master baru masuk lewat CSV di `database/seeders/data/`, bukan lewat kode.
7. Migration baru — tidak mengedit migration lama in-place.
8. Variabel env baru sudah masuk `.env.example`.
9. `npm run build` lolos kalau ada perubahan frontend.
