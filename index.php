<?php
declare(strict_types=1);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#10251f">
    <title>QRCAMv2 — Attendance</title>
    <link rel="stylesheet" href="styles.css">
    <script defer src="assets/qrcode-browser.js"></script>
    <script defer src="assets/html5-qrcode.min.js"></script>
    <script defer src="app.js"></script>
</head>
<body>
    <main id="login-view" class="login-wrap" hidden>
        <section class="login-card">
            <div class="brand-mark">Q</div>
            <p class="eyebrow">ATTENDANCE MANAGEMENT</p>
            <h1>Welcome back</h1>
            <p class="muted">Sign in to manage your class attendance.</p>
            <p><a class="text-button" href="checkin.php">Student QR check-in →</a></p>
            <form id="login-form">
                <label>Username<input name="username" autocomplete="username" required></label>
                <label>Password<input name="password" type="password" autocomplete="current-password" required></label>
                <button class="button primary wide" type="submit">Sign in</button>
                <p id="login-error" class="error-text" role="alert"></p>
            </form>
        </section>
    </main>

    <div id="app-view" class="app-shell" hidden>
        <aside class="sidebar">
            <a class="brand" href="#" data-screen="dashboard"><span class="brand-mark small">Q</span><span>QRCAM<span class="brand-light">v2</span></span></a>
            <p class="side-label">WORKSPACE</p>
            <nav>
                <button class="nav-link active" data-screen="dashboard"><span>▦</span> Overview</button>
                <button class="nav-link" data-screen="students"><span>♙</span> Students</button>
                <button class="nav-link" data-screen="reports"><span>▤</span> Reports</button>
            </nav>
            <div class="side-bottom">
                <div class="connection"><span class="live-dot"></span><span>Connected</span></div>
                <div class="profile"><div class="avatar">A</div><div><strong id="adviser-name">Adviser</strong><small>Administrator</small></div><button id="logout-button" class="icon-button" aria-label="Sign out" title="Sign out">↪</button></div>
            </div>
        </aside>

        <section class="main-area">
            <header class="topbar">
                <button id="menu-toggle" class="icon-button menu-toggle" aria-label="Open menu">☰</button>
                <div><p class="eyebrow">CLASSROOM CONSOLE</p><h1 id="page-title">Overview</h1></div>
                <div class="topbar-date"><span class="live-dot"></span><span id="header-date"></span></div>
            </header>
            <div id="notice" class="notice" role="status" hidden></div>

            <section id="dashboard-screen" class="screen">
                <div class="welcome-row"><div><h2>Today's attendance</h2><p class="muted">Live attendance overview for your students.</p></div><button id="open-scanner" class="button primary"><span>▣</span> Scan QR code</button></div>
                <div class="stats-grid">
                    <article class="stat-card"><div class="stat-top"><span>Total students</span><span class="stat-icon neutral">♙</span></div><strong id="total-count">0</strong><small>Enrolled in your class</small></article>
                    <article class="stat-card"><div class="stat-top"><span>Present</span><span class="stat-icon green">✓</span></div><strong id="present-count">0</strong><small id="present-percent">0% of students</small></article>
                    <article class="stat-card"><div class="stat-top"><span>Absent</span><span class="stat-icon amber">—</span></div><strong id="absent-count">0</strong><small id="absent-percent">0% of students</small></article>
                </div>
                <div class="content-grid">
                    <article class="panel attendance-panel">
                        <div class="panel-heading"><div><h3>Attendance feed</h3><p class="muted">Latest check-ins update automatically</p></div><span class="live-pill"><span class="live-dot"></span> LIVE</span></div>
                        <div class="table-wrap"><table><thead><tr><th>STUDENT</th><th>TIME</th><th>STATUS</th><th></th></tr></thead><tbody id="feed-rows"><tr><td colspan="4" class="empty">No students enrolled yet.</td></tr></tbody></table></div>
                    </article>
                    <article class="panel quick-panel">
                        <div class="panel-heading"><div><h3>Quick actions</h3><p class="muted">Manage your classroom</p></div></div>
                        <button class="action-tile" data-screen="students"><span class="tile-icon">♙</span><span><strong>Manage students</strong><small>View student records and QR codes</small></span><b>›</b></button>
                        <button class="action-tile" id="quick-import"><span class="tile-icon">⇧</span><span><strong>Import students</strong><small>Upload a CSV roster</small></span><b>›</b></button>
                        <button class="action-tile" data-screen="reports"><span class="tile-icon">▤</span><span><strong>Attendance reports</strong><small>Print a date-range report</small></span><b>›</b></button>
                        <input id="csv-file" type="file" accept=".csv,text/csv" hidden>
                    </article>
                </div>
                <article class="panel audit-panel">
                    <div class="panel-heading"><div><h3>Change history</h3><p class="muted">Latest adviser attendance adjustments</p></div></div>
                    <div class="table-wrap"><table><thead><tr><th>WHEN</th><th>ADVISER</th><th>CHANGE</th></tr></thead><tbody id="audit-rows"><tr><td colspan="3" class="empty">No manual changes recorded.</td></tr></tbody></table></div>
                </article>
            </section>

            <section id="students-screen" class="screen" hidden>
                <div class="welcome-row"><div><h2>Student roster</h2><p class="muted">Manage enrollment and individual attendance QR codes.</p></div><button id="students-import" class="button primary">＋ Import CSV</button></div>
                <article class="panel">
                    <div class="panel-heading roster-heading"><div><h3>Enrolled students</h3><p id="roster-count" class="muted">0 students</p></div><input id="student-search" class="search-input" type="search" placeholder="Search students…" aria-label="Search students"></div>
                    <div class="table-wrap"><table><thead><tr><th>STUDENT</th><th>STUDENT ID</th><th>SECTION</th><th>ENROLLED</th><th></th></tr></thead><tbody id="student-rows"></tbody></table></div>
                </article>
            </section>

            <section id="reports-screen" class="screen" hidden>
                <div class="welcome-row"><div><h2>Attendance reports</h2><p class="muted">Build and print a complete daily attendance report.</p></div></div>
                <article class="panel report-panel">
                    <form id="report-form" class="report-controls">
                        <label>Start date<input id="report-start" type="date" required></label>
                        <label>End date<input id="report-end" type="date" required></label>
                        <button class="button primary" type="submit">Generate report</button>
                        <button class="button secondary" id="print-report" type="button" disabled>Print report</button>
                    </form>
                    <div id="report-title" class="report-title"></div>
                    <div class="table-wrap"><table><thead><tr><th>DATE</th><th>STUDENT ID</th><th>STUDENT</th><th>SECTION</th><th>STATUS</th><th>TIME</th><th>NOTE</th><th></th></tr></thead><tbody id="report-rows"><tr><td colspan="8" class="empty">Select a date range to generate a report.</td></tr></tbody></table></div>
                </article>
            </section>
        </section>
    </div>

    <dialog id="scanner-dialog" class="modal">
        <div class="modal-heading"><div><p class="eyebrow">ATTENDANCE CHECK-IN</p><h2>Scan student QR</h2></div><button class="icon-button close-dialog" aria-label="Close">×</button></div>
        <p class="muted">Point the camera at a student's personal QR code.</p>
        <div id="reader" class="reader"></div>
        <p id="scan-result" class="scan-result" role="status">Camera permission may be required.</p>
        <button id="scanner-stop" class="button secondary wide">Stop camera</button>
    </dialog>

    <dialog id="qr-dialog" class="modal qr-modal">
        <div class="modal-heading"><div><p class="eyebrow">STUDENT QR CODE</p><h2 id="qr-student-name">Student</h2></div><button class="icon-button close-dialog" aria-label="Close">×</button></div>
        <p id="qr-student-meta" class="muted"></p>
        <canvas id="qr-canvas" width="280" height="280"></canvas>
        <div class="modal-actions"><button id="download-qr" class="button primary">Download QR</button><button id="print-qr" class="button secondary">Print QR</button></div>
    </dialog>

    <dialog id="attendance-dialog" class="modal">
        <div class="modal-heading"><div><p class="eyebrow">ADVISER ACTION</p><h2>Adjust attendance</h2></div><button class="icon-button close-dialog" aria-label="Close">×</button></div>
        <form id="attendance-form">
            <input id="attendance-student-id" type="hidden">
            <label>Student<select id="attendance-student" required></select></label>
            <label>Date<input id="attendance-date" type="date" required></label>
            <label>Status<select id="attendance-status"><option value="present">Present</option><option value="absent">Absent</option><option value="excused">Excused</option></select></label>
            <label>Note (optional)<textarea id="attendance-note" maxlength="500" rows="3"></textarea></label>
            <div class="modal-actions"><button class="button primary" type="submit">Save change</button><button class="button secondary close-dialog" type="button">Cancel</button></div>
        </form>
    </dialog>
</body>
</html>
