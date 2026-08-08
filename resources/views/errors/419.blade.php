<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>419 — Sesi Habis</title>
    <style>
        :root {
            --bg-start: #07152d;
            --bg-end: #0b1e42;
            --card: rgba(10, 23, 51, 0.74);
            --card-border: rgba(148, 163, 184, 0.16);
            --text: #e5eefc;
            --muted: #9db0d5;
            --brand: #4f8cff;
            --brand-2: #1fd0ff;
            --success: #70f0b2;
            --warning: #ffd36e;
            --shadow: 0 30px 80px rgba(1, 10, 29, 0.45);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top left, rgba(79, 140, 255, 0.28), transparent 30%),
                radial-gradient(circle at bottom right, rgba(31, 208, 255, 0.2), transparent 28%),
                linear-gradient(135deg, var(--bg-start), var(--bg-end));
            display: grid;
            place-items: center;
            overflow: hidden;
        }

        body::before,
        body::after {
            content: "";
            position: fixed;
            border-radius: 999px;
            filter: blur(12px);
            opacity: 0.55;
            pointer-events: none;
        }

        body::before {
            width: 320px;
            height: 320px;
            left: -80px;
            top: -80px;
            background: rgba(79, 140, 255, 0.16);
        }

        body::after {
            width: 280px;
            height: 280px;
            right: -60px;
            bottom: -60px;
            background: rgba(31, 208, 255, 0.15);
        }

        .shell {
            width: min(920px, calc(100vw - 32px));
            position: relative;
        }

        .panel {
            position: relative;
            overflow: hidden;
            border: 1px solid var(--card-border);
            background: linear-gradient(180deg, rgba(10, 24, 54, 0.8), rgba(10, 24, 54, 0.72));
            backdrop-filter: blur(18px);
            border-radius: 28px;
            box-shadow: var(--shadow);
            padding: 34px;
        }

        .panel::before {
            content: "";
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at top right, rgba(79, 140, 255, 0.16), transparent 26%),
                radial-gradient(circle at bottom left, rgba(31, 208, 255, 0.12), transparent 30%);
            pointer-events: none;
        }

        .grid {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: 1.05fr 0.95fr;
            gap: 26px;
            align-items: stretch;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: 999px;
            border: 1px solid rgba(79, 140, 255, 0.3);
            background: rgba(79, 140, 255, 0.08);
            color: #cfe0ff;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }

        .dot {
            width: 9px;
            height: 9px;
            border-radius: 999px;
            background: linear-gradient(135deg, var(--brand-2), var(--brand));
            box-shadow: 0 0 0 6px rgba(79, 140, 255, 0.12);
        }

        .icon-wrap {
            margin-top: 26px;
            width: 92px;
            height: 92px;
            border-radius: 26px;
            display: grid;
            place-items: center;
            background: linear-gradient(145deg, #1f5ecf, #2f8aff);
            box-shadow: 0 24px 50px rgba(19, 78, 181, 0.42);
        }

        .icon-wrap svg {
            width: 46px;
            height: 46px;
        }

        h1 {
            margin: 22px 0 10px;
            font-size: clamp(30px, 4vw, 44px);
            line-height: 1.05;
            letter-spacing: -0.03em;
        }

        .lead {
            margin: 0;
            max-width: 540px;
            color: var(--muted);
            font-size: 16px;
            line-height: 1.8;
        }

        .meta {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            margin-top: 26px;
        }

        .meta-box {
            min-width: 138px;
            padding: 14px 16px;
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.045);
            border: 1px solid rgba(148, 163, 184, 0.12);
        }

        .meta-label {
            color: #89a1cc;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.16em;
            text-transform: uppercase;
        }

        .meta-value {
            margin-top: 6px;
            font-size: 20px;
            font-weight: 800;
            letter-spacing: -0.02em;
        }

        .side {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .card {
            padding: 22px;
            border-radius: 24px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(148, 163, 184, 0.12);
        }

        .card h2 {
            margin: 0 0 14px;
            font-size: 15px;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #b9caeb;
        }

        .tips {
            margin: 0;
            padding-left: 18px;
            color: var(--muted);
            line-height: 1.8;
            font-size: 14px;
        }

        .tips li + li { margin-top: 8px; }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 22px;
        }

        .btn {
            appearance: none;
            border: 0;
            text-decoration: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            min-height: 50px;
            padding: 0 20px;
            border-radius: 16px;
            font-weight: 800;
            font-size: 15px;
            transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
        }

        .btn:hover { transform: translateY(-1px); }

        .btn-primary {
            color: white;
            background: linear-gradient(135deg, var(--brand), #2a6df4);
            box-shadow: 0 18px 34px rgba(42, 109, 244, 0.34);
        }

        .btn-secondary {
            color: var(--text);
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(148, 163, 184, 0.15);
        }

        .helper {
            margin-top: 16px;
            color: #88a3d8;
            font-size: 13px;
            line-height: 1.7;
        }

        .helper strong { color: var(--warning); }

        @media (max-width: 860px) {
            .panel { padding: 24px; }
            .grid { grid-template-columns: 1fr; }
            .icon-wrap { width: 82px; height: 82px; border-radius: 24px; }
            .actions { flex-direction: column; }
            .btn { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="shell">
        <div class="panel">
            <div class="grid">
                <section>
                    <span class="badge"><span class="dot"></span> Session Expired</span>

                    <div class="icon-wrap" aria-hidden="true">
                        <svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <rect x="10" y="6" width="28" height="36" rx="8" stroke="white" stroke-width="2.6" opacity="0.95"/>
                            <path d="M24 14V25" stroke="white" stroke-width="3.2" stroke-linecap="round"/>
                            <circle cx="24" cy="31.5" r="2.4" fill="white"/>
                            <path d="M16 10H32" stroke="white" stroke-width="2.6" stroke-linecap="round" opacity="0.75"/>
                        </svg>
                    </div>

                    <h1>Sesi Anda Sudah Habis</h1>
                    <p class="lead">
                        Halaman ini terbuka terlalu lama atau token keamanan sudah tidak berlaku.
                        Permintaan terakhir ditolak sehingga Anda perlu memuat ulang halaman atau masuk kembali.
                    </p>

                    <div class="meta">
                        <div class="meta-box">
                            <div class="meta-label">Kode</div>
                            <div class="meta-value">419</div>
                        </div>
                        <div class="meta-box">
                            <div class="meta-label">Status</div>
                            <div class="meta-value">Page Expired</div>
                        </div>
                    </div>
                </section>

                <aside class="side">
                    <div class="card">
                        <h2>Apa yang perlu dilakukan</h2>
                        <ul class="tips">
                            <li>Muat ulang halaman lalu ulangi proses terakhir.</li>
                            <li>Jika masih muncul, masuk ulang ke aplikasi.</li>
                            <li>Jika sedang unggah file, pilih ulang file setelah halaman dimuat kembali.</li>
                        </ul>

                        <div class="actions">
                            <button type="button" class="btn btn-primary" onclick="window.location.reload()">Muat Ulang Halaman</button>
                            <a class="btn btn-secondary" href="{{ route('login') }}">Masuk Ulang</a>
                            <button type="button" class="btn btn-secondary" onclick="window.history.back()">Kembali</button>
                        </div>

                        <p class="helper">
                            Jika masalah sering berulang setelah <strong>refresh</strong>, periksa sesi login atau buka kembali aplikasi dari tab baru.
                        </p>
                    </div>
                </aside>
            </div>
        </div>
    </div>
</body>
</html>
