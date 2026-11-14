# MTQ-AttendEase

QR attendance for Mary the Queen College of Quezon City.
Laravel 13, MySQL, Blade + Tailwind (Vite).

## Setup

```sh
composer install
npm install && npm run build
cp .env.example .env        # then set DB_PASSWORD and the MAIL_* SMTP settings
php artisan key:generate
mysql -u root -p -e "CREATE DATABASE attendease CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
php artisan migrate
php artisan db:seed          # optional: test@mqcqc.ph.education / password
```

Run it (two terminals):

```sh
php artisan serve
php artisan queue:work       # sends queued email (welcome, alerts, class notifications)
```

## Configuration (`.env`)

| Key | Purpose |
| --- | --- |
| `SCHOOL_NAME` | Shown in views, PDFs, reports and mail via `config('app.school_name')` |
| `ALLOWED_EMAIL_DOMAIN` | Only this domain can register (default `mqcqc.ph.education`) |
| `APP_TIMEZONE` | `Asia/Manila`; all session-date logic uses it |
| `SESSION_DRIVER=database` | Required so password resets can log out every session |
| `QUEUE_CONNECTION=database` | Mail is queued; in-app notifications are written immediately |
| `MAIL_MAILER` / `MAIL_*` | SMTP. `log` writes mail to `storage/logs/laravel.log` instead |
| `APP_URL` | Used in the QR link. Must be reachable from students' phones |

**Phones need HTTPS.** Browsers only allow geolocation on HTTPS (or `localhost`), and
the QR encodes `APP_URL`. For classroom testing, put the app behind HTTPS on the LAN
(for example a reverse proxy or a tunnel) and set `APP_URL` to that address.

## Tests

```sh
php artisan test
```

Feature tests use in-memory SQLite. Test names carry the PRD requirement ID
(for example `test_fr_att_10_ip_burst_flags_every_scan_in_the_burst`).

## Where things live

| Area | Code |
| --- | --- |
| Access control (FR-ACL) | `app/Policies/*` |
| Rotating QR tokens (FR-ATT-05) | `app/Services/QrTokenService.php` |
| Scans, flags, absences, grace (FR-ATT) | `app/Services/AttendanceService.php` |
| Attendance report (FR-RPT) | `app/Services/ClassStatsService.php`, `app/Http/Controllers/ReportController.php` |
| New-device detection (FR-AUTH-09) | `app/Services/DeviceService.php` |
| Notifications and mail (FR-NOTIF, FR-MAIL) | `app/Notifications/*`, `resources/views/vendor/mail` |

## Removed features

Assessments, submissions, exam papers (bubble answer sheets, answer keys, automatic reading
and grading) and the class review grid (with its Google Sheets link) were removed. The
migration `2026_11_13_000005_remove_assessments_and_review` drops their tables, the
`classes.sheet_token` column and stored submission photos on databases that had them.
PRD sections FR-ASM, FR-SUB, FR-PDF and FR-REV no longer apply.

## Decisions where the PRD left room

- **Reopening stages.** Time In can be reopened until Time Out starts; the grace period
  still counts from the first opening. Time Out can't be reopened after it closes.
- **Manual records win.** Closing Time Out (FR-ATT-13) doesn't override a status the
  teacher set by hand.
- **"Held" sessions.** A session counts toward attendance once Time In has been opened.
  A held session with no record for a student counts as Absent. The semester report counts
  only sessions inside the semester dates; day, week and month reports use calendar dates.
- **Removed students** still appear (marked "removed") in the attendance report, since their
  history is kept (FR-CLS-06).
