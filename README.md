# QRCAMv2 — QR Code Attendance Management System

A responsive adviser console and mobile-browser QR attendance scanner. Students do not need accounts. Each enrolled student gets a unique, opaque QR token; scanning it records that day's attendance. The application supports either an offline XAMPP/MySQL database or hosted Supabase. Credentials remain server-side.

## Features

- Adviser login with PHP password hashing, session protection, and CSRF checks.
- Student enrollment and CSV import (`student_no,full_name,class_section`).
- Per-student QR codes that can be downloaded or printed.
- Mobile camera scanning; duplicate scans do not create duplicate attendance.
- Automatically refreshed dashboard, present/absent totals, and attendance list.
- Atomic manual attendance changes with before/after audit records and an adviser-visible history.
- Printable daily attendance reports across a date range (up to 366 days).
- Mobile-friendly layout and local copies of the QR libraries; no third-party runtime CDN.

## Requirements

- PHP 8.1+ with `mbstring` and `pdo_mysql` (MySQL) or `curl` (Supabase).
- Apache/XAMPP and MySQL for offline mode.
- Node.js/npm only if you want to rebuild the checked-in browser assets.
- Supabase project for hosted mode.

Camera access requires HTTPS or `localhost` in modern browsers. On a LAN, serve over HTTPS if phones cannot access the camera over plain HTTP.

## Install with XAMPP (offline runtime)

1. Copy this folder into `C:\xampp\htdocs\QRCAMv2`.
2. In phpMyAdmin, create a database named `qrcam`; import `database\mysql.sql`.
3. Copy `.env.example` to `.env`. Keep `DB_DRIVER=mysql` and adjust the MySQL settings if necessary.
4. Set `ADMIN_PASSWORD_HASH` in `.env` to the output of:

   ```powershell
   php -r "echo password_hash('choose-a-strong-password', PASSWORD_DEFAULT), PHP_EOL;"
   ```

   Set `ADMIN_USERNAME` as desired. Never commit `.env`.
5. Start Apache and MySQL in XAMPP and open `http://localhost/QRCAMv2/`.

The browser QR libraries are included in `assets`, so the app has no runtime CDN dependency and needs no internet connection in MySQL mode. The included assets can be regenerated with `npm install` and `npm run build:qr`; copy the updated scanner bundle from `node_modules\html5-qrcode\html5-qrcode.min.js` into `assets\html5-qrcode.min.js` if you upgrade that package.

## Use Supabase

1. In the Supabase SQL Editor, run `database\supabase.sql`.
2. Set `DB_DRIVER=supabase`, `SUPABASE_URL`, and `SUPABASE_SERVICE_ROLE_KEY` in the server's `.env`.
3. Set `ADMIN_USERNAME` and `ADMIN_PASSWORD_HASH` as above.
4. Keep `.env` outside source control and never expose the service-role key to browser JavaScript.

The PHP server accesses Supabase through its REST API using the server-only service-role key. The dashboard refreshes every three seconds. Supabase mode needs an internet connection.

## CSV format

Use a UTF-8 CSV with a header row. Required columns are `student_no` and `full_name`; `class_section` is optional. Example:

```csv
student_no,full_name,class_section
2026001,Alex Rivera,BSCS-1A
2026002,Jamie Santos,BSCS-1A
```

Existing student numbers are skipped during import. QR tokens are random and cannot be derived from student numbers.

## Security and operations

- The Supabase service-role key bypasses Supabase row-level security. This is intentional for a server-side-only app; never move it into a public client bundle.
- Restrict the PHP app to the adviser/network that should manage records. Use HTTPS for deployment.
- Apache rules deny direct HTTP access to `.env`, SQL schemas, and backend library files.
- Keep database backups. Audit events are append-only through the UI and include actor, timestamp, and before/after data.
- The installer does not provide a default password; sign-in is disabled until a password hash is configured.

## Project layout

- `index.php`, `api.php`, `app.js`, `styles.css` — application UI and JSON API.
- `lib\Database.php` — MySQL and Supabase REST adapters.
- `database\mysql.sql`, `database\supabase.sql` — database schemas and indexes.
