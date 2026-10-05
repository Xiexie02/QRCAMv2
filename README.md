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
2. Set `DB_DRIVER=supabase`, `SUPABASE_URL`, and `SUPABASE_SERVICE_ROLE_KEY` in the server's environment. Put Supabase's server-only **Secret API key** (`sb_secret_...`) in `SUPABASE_SERVICE_ROLE_KEY`; a legacy JWT `service_role` key is also supported. The legacy environment-variable name is retained for compatibility.
3. Set `ADMIN_USERNAME` and `ADMIN_PASSWORD_HASH` as above.
4. Keep `.env` outside source control and never expose the service-role key to browser JavaScript.

The PHP server accesses Supabase through its REST API using the server-only key. For `sb_secret_...` keys, requests send the key only in the `apikey` header because new secret keys are not JWTs; legacy `service_role` JWTs are sent as both `apikey` and bearer authorization. The dashboard refreshes every three seconds. Supabase mode needs an internet connection.

## Deploy on Render (free tier)

The repository includes a Render Blueprint and Dockerfile for the PHP app. The service connects to the existing Supabase project; student and attendance records are stored in Supabase, not the container filesystem.

1. Sign in to [Render](https://dashboard.render.com/) with GitHub and create a **New → Blueprint** deployment for `Xiexie02/QRCAMv2`. Render will read `render.yaml` from the repository.
2. When prompted, enter the Supabase **Secret API key** (`sb_secret_...`) from **Project Settings → API Keys** as `SUPABASE_SERVICE_ROLE_KEY`. Enter `ADMIN_PASSWORD_HASH` as a hash generated locally (never enter the plain-text password as the hash):

   ```powershell
   php -r "echo password_hash('choose-a-strong-password', PASSWORD_DEFAULT), PHP_EOL;"
   ```

   Keep the service-role key and password hash in Render's private environment-variable settings. Do not add either value to GitHub or a committed `.env` file.
3. Deploy the Blueprint and open the `qrcamv2` service URL shown in Render. The adviser console is at `/`; student check-in is at `/checkin.php`.

Render's free web services can spin down after 15 minutes without traffic, so the first request after idle may take time. Render describes free instances as intended for testing and hobby use, not production. Use fictional records until you have confirmed that the hosting and data handling meet your school's privacy requirements. The free service also depends on Render's current free-tier limits.

## Deploy on Hostinger (backup shared hosting)

Hostinger is a paid hosting provider. You need an active Web or Cloud hosting plan and a domain/site configured for **Custom PHP/HTML** before uploading. This app uses PHP, Apache-compatible `.htaccess` rules, HTTPS requests to Supabase, and the PHP `curl` and `mbstring` extensions; confirm the selected plan exposes those extensions and permits outbound HTTPS to your Supabase project.

1. In hPanel, add a Custom PHP/HTML website, open its **File Manager**, and upload the contents of this project to that website's `public_html` directory. Do not upload `.git`, `node_modules`, Docker deployment files, or any `.env.example` file as `.env`.
2. In hPanel's PHP settings, select PHP 8.1 or newer and enable `curl` and `mbstring`. Enable HTTPS/SSL for the domain before using the camera scanner.
3. In the `public_html` directory, create a server-side file named `.env` (the included `.htaccess` denies public HTTP access to dotfiles) with:

   ```dotenv
   DB_DRIVER=supabase
   SUPABASE_URL=https://ejzlysntgphnizgiekui.supabase.co
   SUPABASE_SERVICE_ROLE_KEY=PASTE_THE_PRIVATE_SUPABASE_SECRET_KEY_HERE
   ADMIN_USERNAME=YOUR_ADMIN_LOGIN_EMAIL
   ADMIN_PASSWORD_HASH=PASTE_A_PASSWORD_HASH_HERE
   APP_TIMEZONE=Asia/Manila
   ```

   Generate the hash on your own computer with PHP: `php -r "echo password_hash('your-strong-password', PASSWORD_DEFAULT), PHP_EOL;"`. Use the matching login identifier and password when signing in. Never store the plain-text password or service-role key in the repository or upload them in a public archive.
4. Visit `https://your-domain/` for the adviser console and `https://your-domain/checkin.php` for student check-in. Verify sign-in and Supabase reads before importing real student data.

Hostinger's exact menu labels and available PHP extensions depend on the plan and hPanel version. If the host does not provide `curl`/`mbstring` or blocks outbound HTTPS, this Supabase deployment will not work there; contact Hostinger support or use a supported PHP host. The database remains in Supabase and is not stored on Hostinger.

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
