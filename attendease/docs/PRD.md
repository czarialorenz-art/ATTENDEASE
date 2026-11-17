# MTQ-AttendEase — Product Requirements Document

**Version 2.0 (as built)** · Nov 14, 2026 · @Teresa

This version describes the system as it is built today. It replaces v1 (Nov 12, 2026). What changed is listed in section 13.

---

## 1. Overview

MTQ-AttendEase is a web app for QR-code attendance. Teachers run attendance for their classes with a rotating QR code; students scan it from their phones. The app records Time In and Time Out, flags suspicious scans for the teacher to review, and produces attendance reports.

**Stack:** Laravel 13 (PHP 8.3+) with a MySQL database. Blade + Tailwind CSS (built with Vite). Mobile-first responsive UI, since students scan from phone browsers.

**Global constant:**

```
SCHOOL_NAME="Mary the Queen College of Quezon City"
```

Stored in `.env` and read through `config('app.school_name')`. Never hard-coded in views, PDFs or emails.

### Goals

- Replace paper roll calls with a rotating QR code that only works on the session date, while a stage is open.
- Let students scan with either their phone's camera app or a scanner built into the app.
- Flag suspicious scans for the teacher to review, without blocking students.
- Produce attendance reports by day, week, month or semester, on screen and as PDF files.

### Non-goals

- No admin or registrar role. No email verification.
- No assessments, quizzes, exams, score submission or grading. (Built in v1 and later removed; see section 13.)
- No class review or gradebook grid, and no Google Sheets link.
- No native mobile app; the phone's browser and camera are enough.

Requirement IDs use the form `FR-<AREA>-<NN>` so tasks, commits and tests can reference them. IDs are kept from v1 where the requirement still exists.

---

## 2. Actors and access control

Everyone signs up through the same form, with no role choice. Roles are per class: the user who creates a class is its teacher, and users approved into it are its students. One account can teach some classes and be a student in others.

| Role in a class | Can do | Cannot do |
| --- | --- | --- |
| **Teacher** (the class's creator) | Manage that class's students, schedule, location, sessions, flags and reports | See or change classes created by someone else |
| **Student** (approved member) | Scan attendance, view their own attendance in that class | See other students' data; use teacher actions in that class |

- **FR-ACL-01** Every route is behind sign-in, except sign-up, log in, forgot password and reset password. There is no role column or role middleware. Teacher actions are allowed only for the class's creator (`classes.teacher_id`), and student actions only for users with an approved enrollment in that class. Anything else returns HTTP 403. A user cannot join a class they created.
- **FR-ACL-02** Teacher-owned resources (class, session, scan flag, enrollment, report) are authorized with Laravel Policies, using route model binding plus `authorize()` on every action, including downloads and the live QR endpoint.
- **FR-ACL-03** Student-facing reads are scoped on the server to `student_id = auth()->id()` and to classes where the student's enrollment is approved.
- **FR-ACL-04** A missing or unauthorized resource returns 403 or 404 and never leaks its data.

---

## 3. Accounts and authentication

Teachers and students share one sign-up and one login. Only school email addresses can register, and there is no email verification.

### Sign-up

- **FR-AUTH-01** A single sign-up form has these fields: Full Name, ID Number, School Email, Password. There is no role field.
- **FR-AUTH-02** The email must end in `@mqcqc.ph.education`, checked case-insensitively after trimming. Any other domain is rejected with a field error. The domain is kept in config: `ALLOWED_EMAIL_DOMAIN`.
- **FR-AUTH-03** Email and ID Number are each unique across all users.
- **FR-AUTH-04** Passwords are 8–64 characters and must include an uppercase letter, a lowercase letter, a number and a special character. The same rule applies to sign-up, password reset and change password. Passwords are stored with Laravel's default hasher. New-password fields show a live checklist of these rules, and every password field has a Show/Hide toggle.
- **FR-AUTH-05** On success the account is active right away. The user is signed in, the browser is remembered as a known device, and a welcome email is queued (FR-MAIL-01).

### Login and rate limiting

- **FR-AUTH-06** Users log in with email and password, with an optional "Remember me".
- **FR-AUTH-07** After 5 failed attempts for the same email + IP pair, further attempts are blocked for 60 seconds. The error shows the seconds remaining. Keyed on `strtolower(email)|ip`; a successful login clears the counter.
- **FR-AUTH-08** Error messages never reveal whether an email exists.
- **FR-AUTH-09** A successful login from a browser the account hasn't used before sends a "New sign-in" security email (FR-MAIL-02). The browser is identified by a long-lived cookie token, saved as a hash in `user_devices`.

### Forgot password

- **FR-AUTH-10** The user enters an email. The response is always the same generic message. If the account exists, a reset link is emailed.
- **FR-AUTH-11** The link token is random, stored hashed, valid for 15 minutes and single-use. Asking again replaces any older token.
- **FR-AUTH-12** A successful reset updates the password, rotates `remember_token`, deletes all of the user's sessions (database session driver), and sends a "Password changed" alert. The user then logs in again.

### Change password

- **FR-AUTH-13** A signed-in user can change their password by giving the current one, then the new one twice.
- **FR-AUTH-14** On success, other sessions are signed out, the current session stays, and a "Password changed" alert is sent.

### Profile

- **FR-AUTH-15** Users can edit: name, email, ID number, course, year, section, department. Name, email and ID number are required; the rest are optional.
- **FR-AUTH-16** A new email must still pass the domain rule and stay unique. When it changes, a "Your email was changed" alert goes to the old address and a notice goes to the new one.
- **FR-AUTH-17** ID number stays unique on edit.

---

## 4. Classes and enrollment

A teacher creates a class and shares its code. Students ask to join with that code, and each request waits for the teacher's approval.

- **FR-CLS-01** Teachers create a class with: subject, year level, section, school term (e.g. "2026-2027"), semester (1st, 2nd or Summer), semester start date and semester end date. The end date must be after the start date.
- **FR-CLS-02** Each class gets a unique 7-character code of uppercase letters and digits, leaving out the look-alikes 0/O and 1/I. The code is shown on the class page with a Copy button.
- **FR-CLS-03** A teacher can edit a class's details and set its weekly schedule: one or more rows of day of week, start time and end time. The **classroom location** is the class's **building, floor and room** (all optional), shown to the teacher on the class overview ("Classroom: Tesda Lab · 3rd floor · Main Building") and to students on their class page. (Map coordinates and a radius were removed from the form, so the TOO_FAR distance check in FR-ATT-10 is off.)
- **FR-CLS-04** A student enters a class code and creates an enrollment with status `pending`. A wrong code gives a clear error. A duplicate request, a request for a class the student is already in, or a request to join one's own class is rejected.
- **FR-CLS-05** The teacher sees pending requests and approves or rejects each one. Approving sets `approved`; rejecting sets `rejected`, and the student may ask again later.
- **FR-CLS-06** A teacher can remove an approved student, which sets `removed`. The student loses access to the class, but their past attendance is kept for reports.
- **FR-CLS-07** Each step notifies the other side (FR-NOTIF-02): a new request notifies the teacher; an approval, rejection or removal notifies the student.
- **FR-CLS-08** Every user has one dashboard with: "Classes I teach" (with pending-request counts), then "Today's sessions" for those classes, "Classes I'm in" (approved classes plus pending requests), a box to join a class by code, and a "Scan attendance QR" shortcut for students. "Today's sessions" lists only today's unfinished sessions, ongoing ones (a stage open) first; a session drops off once Time Out closes. The card refreshes itself every 15 seconds, so new or newly opened sessions appear without reloading. Students get the same live "Today's sessions" card for the classes they're approved in, showing each session's stage, their own status (e.g. Present, Time In 8:00 AM), and a "Scan Time In" / "Scan Time Out" button while that stage is open and they haven't scanned it yet. A teacher-only account (teaches classes, no approved or pending enrollments) doesn't see "Classes I'm in", the student cards or the top-bar Scan QR button; its two teacher cards use the full width.
- **FR-CLS-09** *(new)* The Students page shows each student's own section (from their profile) next to their name, on join requests, the enrolled list and the removed list. Students with no section set show "No section".
- **FR-CLS-10** *(new)* The Students page has a search box (student name, number or email, any case) and a Section filter listing the sections that occur among the class's students, plus "No section". Both apply to join requests, enrolled and removed students; counts show "x of y" while filtering, and Clear resets them.

### Announcements and class cancellations *(new)*

- **FR-ANN-01** The class's teacher posts announcements from the class's **Announcements** tab: a title and message (both required, up to 120 / 2000 characters).
- **FR-ANN-02** An announcement can instead be a **Class cancellation**: the date of the class that won't meet (today or later; one cancellation per date), an optional title (default "Class cancelled") and an optional reason. Posting either kind notifies every approved student in the class (in-app and email).
- **FR-ANN-03** Students see a class's announcements on its class page, newest first, with upcoming cancellations highlighted ("No class on Monday, November 16, 2026 (tomorrow)"). Their dashboard has an **Announcements** card: upcoming cancellations first (soonest first), then anything posted in the last 7 days, from their approved classes only.
- **FR-ANN-04** If a session exists, or is created, on a cancelled date, the teacher is warned (after posting, after creating the session, and on the session page) and can archive it. The session isn't blocked.
- **FR-ANN-05** The teacher can edit (students are not notified again) or delete an announcement. Only the class's teacher can post, edit or delete.

---

## 5. Attendance

A teacher opens a dated session, runs a Time In stage and then a Time Out stage, and students scan a rotating QR code once per stage. Suspicious scans are recorded and flagged, never blocked.

### Sessions and stages

- **FR-ATT-01** A teacher creates an attendance session for one of their classes, with a date (required), an optional title and a grace period in whole minutes (default 15, minimum 0). The grace period can be edited at any time; a change recalculates Present/Late for QR Time In records that were not set by hand.
- **FR-ATT-02** Each session has two stages, Time In and Time Out. The teacher opens and closes each by hand, and only one stage is open at a time. `opened_at` and `closed_at` are stored for each.
  - Time In can be reopened until Time Out starts; the grace period still counts from its first opening.
  - Time Out can be opened only after Time In has been opened and closed, and can't be reopened once closed.
- **FR-ATT-03** A stage can be opened only on the session's date, in the app timezone Asia/Manila.

### Rotating QR code

- **FR-ATT-04** While a stage is open, the teacher's screen shows a full-screen QR code that changes every 30 seconds, a countdown to the next code, the number of students scanned out of those enrolled, and the number of unreviewed flags. The screen checks for updates every 5 seconds.
- **FR-ATT-05** The QR encodes a URL such as `/scan?t=<token>`. The token is signed with HMAC using `APP_KEY` and carries session ID, stage and issue time. The server accepts it only if:
  - its signature is valid;
  - it is the current 30-second token or the one just before it (an overlap for slow phones);
  - today is the session's date;
  - the stage is still open.

  In every other case it answers "This QR code has expired."

### Scanning

- **FR-ATT-06** A student scans with the phone's normal camera app, which opens the URL in the browser. If not signed in, the student logs in and is returned to the scan. Only students with an approved enrollment in that class can record attendance.
- **FR-ATT-06a** *(new)* The app also has its own QR scanner (`/scanner`), linked from the top bar on every page, from the dashboard, and from "Scan again" on any failed scan. It uses the phone's back camera, accepts only this app's own scan links, and opens the same scan page as FR-ATT-06. It shows clear messages when the camera is blocked, missing or busy, or when the page isn't on HTTPS.
- **FR-ATT-07** The scan page asks for the browser's location (10-second timeout), then posts the token, latitude, longitude, accuracy and a device ID. The device ID is a random UUID kept in a long-lived cookie and in localStorage.
- **FR-ATT-08** There is one record per student per session. A repeat scan in the same stage shows "Already recorded at <time>" and changes nothing.
- **FR-ATT-09** Time In status: **Present** if the scan time is within the Time In opening time + grace minutes, otherwise **Late**. A Time Out scan records the time and keeps that status.

### Suspicious-scan flags (record, never block)

- **FR-ATT-10** Each scan is saved, then checked. A flag is added for each rule that matches:

| Flag code | Rule |
| --- | --- |
| `NO_LOCATION` | Location was denied, unavailable or timed out |
| `TOO_FAR` | Distance from the class's map location is greater than its radius; skipped when the class has no map coordinates, which is now always (the coordinates were removed from the form, FR-CLS-03) |
| `SHARED_DEVICE` | The same device ID has already scanned for a different student in the same session |
| `IP_BURST` | 3 or more scans from the same IP in the same session within 3 seconds; every scan in that burst is flagged |

- **FR-ATT-11** The teacher's session page lists flagged scans with the student, reason, stage, distance (and GPS accuracy), IP and time. The teacher can clear a flag, which records `cleared_by` and `cleared_at`. A cleared flag stays in the log.

### Manual changes and absences

- **FR-ATT-12** A teacher can set any enrolled student's status to Present, Late or Absent by hand. Manual entries are marked `source = manual`.
- **FR-ATT-13** When Time Out closes, every approved student missing a Time In or Time Out is marked Absent and gets an absence alert (FR-NOTIF-02). Statuses the teacher set by hand are left as they are. The teacher can still change any status afterwards.
- **FR-ATT-14** Students can view their own attendance history, filtered by class and school term. It shows date, class, status, and Time In and Time Out times, but no flag details.
- **FR-ATT-16** *(new)* Each session in the Sessions list has an **Edit** menu with two choices:
  - **Edit session:** change the title and grace period at any time, and the date only until Time In has been opened.
  - **Archive:** hides the session from the active list, reports, the dashboard and students' records. Not allowed while a stage is open. Archived sessions are listed under **Archived**, read-only, with the date they will be deleted, and can be **restored** or **deleted** right away (after a confirmation; only archived sessions can be deleted). **One year after archiving, the session is deleted for good** with its records, scans and flags (a daily scheduled `model:prune`, with the Sessions page also cleaning up as a backup).
- **FR-ATT-17** *(new)* When the teacher opens Time In for the first time in a session, every approved student in the class gets a "Time In is open" notification (in-app and email) with the class, the session title, the time they must scan by to be marked Present (opening time + grace period), and a link to the scanner. Reopening Time In, opening Time Out, or a failed attempt to open sends nothing.
- **FR-ATT-18** *(new)* **Correction requests.** In their attendance history, each Absent or Late record has a **Request correction** button. The student picks the correct status (Absent → Present or Late; Late → Present) and gives a reason (10–1000 characters). Each request is **Pending**, **Approved** or **Rejected**, shown on the record with any note from the teacher. Only one pending request per record at a time; after a rejection the student may ask again. Only the record's own student, while approved in the class, can ask, and not for an archived session. The teacher reviews requests on the class's **Corrections** tab (pending count shown on the tab and on the dashboard), with an optional note. Approving changes the record to the requested status as a teacher change (`source = manual`), so it counts in reports. Both sides are notified.
- **FR-ATT-19** *(new)* **Sessions stay inside the semester.** A session can't be created, or have its date changed, outside the class's semester start and end dates; the error says why (e.g. "This date is after the semester ends (Nov 14, 2026); it wouldn't count in semester totals") with a **Fix semester dates** link. The New session form limits the date picker to the semester, shows the semester range, and warns live as soon as an outside date is picked. Sessions already outside the semester (e.g. after the semester dates were changed) are marked **Outside semester** in the list and get a banner with the same link; their title and grace period can still be edited.
- **FR-ATT-15** *(new)* A student's class page shows their own attendance this semester: Present, Late and Absent counts, attendance rate, and sessions held so far, with links to the full history and the scanner.

---

## 6. Attendance report

- **FR-RPT-01** For a class, the report shows each student's Present, Late and Absent counts, out of all sessions held in the chosen period. A session counts as held once Time In has been opened; a held session with no record for a student counts as Absent. The report has no attendance-rate column.
- **FR-RPT-01a** *(new)* The teacher chooses the period:
  - **Day:** the date picked;
  - **Week:** Monday to Sunday of the week containing the date picked;
  - **Month:** the calendar month containing the date picked;
  - **Semester** (default): the class's semester start to end date.

  The period is kept in the URL, so a view can be bookmarked.
- **FR-RPT-02** The report header shows the school name, subject, section, school term, semester, the teacher's name and when it was generated. The period covered is part of the Semester line, e.g. "1st semester (Aug 3, 2026 – Nov 14, 2026)" or "1st semester · Week of Nov 9 – Nov 15, 2026"; there is no separate Period line. The title follows the period: "Daily / Weekly / Monthly / Semester Attendance Summary".
- **FR-RPT-02a** *(new)* Each row shows the student number, name, the student's own section (or "—" if not set) and the counts. Removed students still appear, marked "removed", since their history is kept.
- **FR-RPT-04** *(new)* The report is an **attendance sheet grid**: one column per session held in the period, in date order (headed by date and weekday; two sessions on one day show as "#1", "#2"), with **P**, **L** or **A** for each student, then the Present, Late and Absent totals. A held session with no record shows A. On screen the grid scrolls sideways with the student's name kept in view, and hovering a cell shows the full date and session title. The PDF download is portrait A4, with the dates printed 8 per table, the student list repeated and the totals on the last table.
- **FR-RPT-03** The report can be viewed on screen and downloaded as a PDF (via barryvdh/laravel-dompdf), for the same period. (A Word .docx download existed earlier and was removed.) File names include the subject, section and period (e.g. `ITE101_BSIT-1A_Attendance_2026-09.pdf`). Only the class's teacher can view or download it.

---

## 7. Notifications and email

Every class event creates an in-app notification and an email copy. Account events send email only. All email is queued, so a failed email never fails the request that triggered it.

- **FR-NOTIF-01** In-app notifications use Laravel's database channel and are written immediately (no queue worker needed). A bell in the top bar shows the unread count. Users can open the list, mark one as read, or mark all as read. Each item links to the related page.
- **FR-NOTIF-02** Class notifications use both the database and mail channels:

| Event | Recipient |
| --- | --- |
| Join request received | Teacher |
| Join request approved / rejected | Student |
| Removed from class | Student |
| Time In is open (FR-ATT-17) | Every approved student in the class |
| Announcement or class cancellation posted (FR-ANN-02) | Every approved student in the class |
| Correction requested (FR-ATT-18) | Teacher |
| Correction approved / rejected (FR-ATT-18) | Student |
| Absence alert (marked Absent by FR-ATT-13 or a manual change) | Student |

- **FR-MAIL-01** A welcome email is sent after sign-up. It greets the user by name and links to the dashboard.
- **FR-MAIL-02** Security alerts are sent by email only. Each shows the time (Asia/Manila), IP address and browser, and says "If this wasn't you, reset your password" with a link:
  - new sign-in from an unrecognized browser (FR-AUTH-09);
  - email changed, sent to the old address (FR-AUTH-16);
  - password changed, after a reset or a change (FR-AUTH-12, FR-AUTH-14).
- **FR-MAIL-03** Emails use a shared Markdown mail layout showing `SCHOOL_NAME`. The mail driver is set through `.env` (SMTP).

---

## 8. Data model

All tables have `id` and timestamps. Foreign keys use `constrained()`.

| Table | Key columns |
| --- | --- |
| `users` | name, id_number (unique), email (unique), password, course, year, section, department, remember_token |
| `user_devices` | user_id, token_hash, user_agent, ip, last_seen_at |
| `password_reset_tokens` | email, token (hashed), created_at; expires after 15 minutes, single use |
| `sessions` | Laravel database session driver |
| `classes` | teacher_id, subject, year_level, section, school_term, semester, semester_start, semester_end, code (unique), building, floor, room; latitude, longitude, radius_m (no longer set) |
| `class_schedules` | class_id, day_of_week, start_time, end_time (room no longer used) |
| `enrollments` | class_id, student_id, status (pending / approved / rejected / removed), decided_at; unique(class_id, student_id) |
| `attendance_sessions` | class_id, date, title, grace_minutes (default 15), time_in_opened_at, time_in_closed_at, time_out_opened_at, time_out_closed_at, archived_at (soft delete; FR-ATT-16) |
| `attendance_records` | attendance_session_id, student_id, status (present / late / absent), time_in_at, time_out_at, source (qr / manual); unique(session, student) |
| `announcements` | class_id, author_id, type (general / cancellation), title, body, cancelled_date (FR-ANN) |
| `attendance_corrections` | attendance_record_id, student_id, current_status, requested_status, reason, status (pending / approved / rejected), teacher_note, decided_by, decided_at (FR-ATT-18) |
| `scan_logs` | attendance_record_id, stage, ip, device_id, latitude, longitude, accuracy, distance_m, scanned_at |
| `scan_flags` | scan_log_id, code (NO_LOCATION / TOO_FAR / SHARED_DEVICE / IP_BURST), cleared_by, cleared_at; unique(scan_log_id, code) |
| `notifications` | Laravel default |

Laravel's `cache`, `jobs` and `failed_jobs` tables are also used (rate limiting, queued email).

---

## 9. Pages

- **Public:** sign up, log in, forgot password, reset password.
- **Shared:** dashboard (FR-CLS-08), profile, change password, notifications, QR scanner (`/scanner`), scan result page (`/scan`).
- **As a class's teacher:**
  - Class overview: code with Copy button, student and pending counts, recent sessions, weekly schedule, location status.
  - Students: join requests, enrolled students and removed students, each with section.
  - Schedule & location: class details, weekly schedule rows, classroom location.
  - Sessions: list and "New session" form.
  - Session detail: stage controls, attendance table with manual status changes, flagged scans, settings.
  - Live QR screen for an open stage (full screen).
  - Report: period picker, on-screen report, PDF download.
- **As a class's student:**
  - Class page: teacher, schedule and own attendance summary (FR-ATT-15).
  - Attendance history (FR-ATT-14).

---

## 10. Non-functional requirements

- **Security:** CSRF on all forms. Laravel validation on every input. Signed, short-lived QR tokens. Policies on every teacher action. Students' data is scoped on the server.
- **Timezone:** Asia/Manila for all date logic. Session dates are compared as local dates.
- **Performance:** the live QR screen checks for updates every 5 seconds and stays responsive with 60 students scanning at once.
- **Mobile:** the scan page and the in-app scanner work in current Chrome (Android) and Safari (iOS). Geolocation and camera access need HTTPS.
- **Testing:** a feature test for each requirement (PHPUnit, in-memory SQLite), with factories for every model. Test names carry the requirement ID, e.g. `test_fr_att_10_ip_burst_flags_every_scan_in_the_burst`.

---

## 11. Deployment requirements

- PHP 8.3 or newer with these extensions: openssl, mbstring, pdo_mysql, fileinfo, curl, gd, intl, zip.
- `APP_URL` must be the public HTTPS address students' phones can reach; the QR code encodes it.
- `SESSION_DRIVER=database` and `QUEUE_CONNECTION=database`, with a queue worker running for email (or the host's managed queue).
- The Laravel scheduler must run every minute (`* * * * * php artisan schedule:run`) so archived sessions are deleted after one year (FR-ATT-16).
- **Behind a load balancer or proxy** (e.g. Laravel Cloud), Laravel must be told to trust the proxy. Otherwise every scan appears to come from one IP, which breaks the IP_BURST rule and the per-IP login lockout.
- Run `npm run build` before deploying; front-end assets are not built on the server.

---

## 12. Acceptance criteria (key paths)

- [ ] Signing up with a non-@mqcqc.ph.education email fails; a school email succeeds, and the welcome email is queued. The form has no role field.
- [ ] The 6th failed login within the window is blocked, with a retry timer.
- [ ] A reset link works once and fails after 15 minutes; after a reset, every session is logged out.
- [ ] Teacher A gets 403 on every route for Teacher B's class.
- [ ] A QR token older than about 60 seconds, from another date, or from a closed stage is rejected.
- [ ] The in-app scanner opens the scan page for this app's QR codes and ignores other QR codes.
- [ ] A scan within the grace period is Present, after it Late, and a second scan in the same stage is refused. A student with no Time Out is Absent once Time Out closes.
- [ ] Each of the 4 flag rules creates a flag without blocking the scan, and the teacher can clear it.
- [ ] The report shows the right counts for a day, a week, a month and the semester, and includes each student's section.
- [ ] The report downloads as a PDF for the chosen period; students get 403.
- [ ] A student's class page and history show only their own attendance, enforced on the server.

---

## 13. Changes from v1

| Area | Change |
| --- | --- |
| Assessments (FR-ASM) | **Removed.** No quizzes, exams or assessment types. |
| Submissions (FR-SUB) | **Removed.** No photo or score submission, and no teacher review queue. |
| Exam papers (FR-PDF) | **Removed**, including bubble answer sheets, answer keys, OMR reading and automatic grading (added after v1, then removed). |
| Class review (FR-REV) | **Removed**, including the Google Sheets live link and CSV download (added after v1, then removed). |
| Report (FR-RPT) | **Extended:** day / week / month / semester period (FR-RPT-01a) and a student section column (FR-RPT-02a). |
| Scanning (FR-ATT) | **Added:** in-app QR scanner (FR-ATT-06a) and the student's own attendance summary (FR-ATT-15). |
| Students page (FR-CLS) | **Added:** each student's section (FR-CLS-09). |
| Notifications | "Submission checked" removed with submissions. |

---

## 14. Open questions

- **Removed features:** should any of the removed features return in a later version? If so, they need their own spec.
- **Hosting:** choose the host (InfinityFree can't run queued email or background jobs; Laravel Cloud needs the proxy setting in section 11).
- **Database:** set the production MySQL password and run the migrations, including the one that drops the old assessment tables.
