<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sesi Habis</title>
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: #e8f0ff;
            background:
                radial-gradient(circle at 18% 14%, rgba(46, 124, 255, 0.26), transparent 30%),
                radial-gradient(circle at 84% 82%, rgba(29, 204, 255, 0.16), transparent 28%),
                linear-gradient(145deg, #07142b 0%, #0a1d3d 100%);
        }

        .card {
            width: min(440px, 100%);
            position: relative;
            overflow: hidden;
            padding: 30px;
            border-radius: 26px;
            border: 1px solid rgba(151, 177, 224, 0.16);
            background: linear-gradient(180deg, rgba(15, 35, 72, 0.94), rgba(10, 27, 59, 0.94));
            box-shadow: 0 28px 70px rgba(0, 8, 26, 0.42);
            text-align: center;
        }

        .card::before {
            content: "";
            position: absolute;
            width: 180px;
            height: 180px;
            right: -70px;
            top: -80px;
            border-radius: 999px;
            background: rgba(71, 131, 255, 0.12);
        }

        .badge {
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            border: 1px solid rgba(74, 139, 255, 0.28);
            background: rgba(74, 139, 255, 0.09);
            color: #cfe0ff;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.16em;
            text-transform: uppercase;
        }

        .badge::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: #52b8ff;
            box-shadow: 0 0 0 5px rgba(82, 184, 255, 0.12);
        }

        .icon {
            position: relative;
            width: 72px;
            height: 72px;
            margin: 22px auto 0;
            display: grid;
            place-items: center;
            border-radius: 22px;
            background: linear-gradient(145deg, #2c75f5, #3998ff);
            box-shadow: 0 18px 36px rgba(30, 99, 220, 0.34);
        }

        .icon svg { width: 36px; height: 36px; }

        h1 {
            position: relative;
            margin: 20px 0 10px;
            font-size: 30px;
            line-height: 1.1;
            letter-spacing: -0.03em;
        }

        p {
            position: relative;
            margin: 0;
            color: #9fb2d8;
            font-size: 14px;
            line-height: 1.75;
        }

        .status {
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 18px;
            padding: 8px 12px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.05);
            color: #b9cae9;
            font-size: 12px;
            font-weight: 700;
        }

        .status strong { color: #ffffff; }

        .actions {
            position: relative;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 24px;
        }

        .btn {
            min-height: 48px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            border-radius: 14px;
            border: 0;
            font: inherit;
            font-size: 14px;
            font-weight: 800;
            text-decoration: none;
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
        }

        .btn:hover { transform: translateY(-1px); }

        .btn-primary {
            color: #ffffff;
            background: linear-gradient(135deg, #347cff, #2b68e9);
            box-shadow: 0 14px 28px rgba(43, 104, 233, 0.3);
        }

        .btn-secondary {
            color: #e7efff;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(151, 177, 224, 0.15);
        }

        .note {
            margin-top: 16px;
            font-size: 12px;
            color: #7890bc;
        }

        @media (max-width: 520px) {
            .card { padding: 26px 22px; }
            .actions { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    @php
        $previousUrl = url()->previous();
        $refreshUrl = $previousUrl !== url()->current()
            ? $previousUrl
            : route('dashboard');
    @endphp

    <main class="card" role="alert" aria-live="assertive">
        <span class="badge">Session Expired</span>

        <div class="icon" aria-hidden="true">
            <svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect x="11" y="7" width="26" height="34" rx="7" stroke="white" stroke-width="2.8"/>
                <path d="M24 15V26" stroke="white" stroke-width="3" stroke-linecap="round"/>
                <circle cx="24" cy="32" r="2.3" fill="white"/>
            </svg>
        </div>

        <h1>Sesi Anda Sudah Habis</h1>
        <p>Silakan refresh halaman untuk melanjutkan. Bila sesi tetap tidak aktif, masuk ulang ke aplikasi.</p>

        <div class="status">Kode <strong>419</strong> · Page Expired</div>

        <div class="actions">
            <a
                class="btn btn-primary"
                href="{{ $refreshUrl }}"
                target="_top"
                onclick="window.top.location.assign(this.href); return false;"
            >Refresh Halaman</a>
            <a
                class="btn btn-secondary"
                href="{{ route('session.expired.login') }}"
                target="_top"
                onclick="window.top.location.assign(this.href); return false;"
            >Login Ulang</a>
        </div>

        <p class="note">Proses yang belum tersimpan perlu dijalankan kembali.</p>
    </main>
</body>
</html>
