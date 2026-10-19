# Event Attendance Monitoring System

A secure PHP and PostgreSQL application for managing events, attendee registration, live check-in/check-out, occupancy, and attendance reporting.

## Features

- Event lifecycle: draft, registration open, ongoing, completed, or cancelled.
- Event schedules, venues, registration deadlines, capacity limits, and waitlists.
- Reusable attendee directory with unique attendee codes and secure passwords.
- Bulk generation of temporary student accounts with forced first-login onboarding.
- Permanent student QR codes for marshal or administrator camera scanning.
- Restricted marshal accounts with check-in-desk access only.
- Per-event ticket codes and registration status tracking.
- Fast check-in desk using an attendee code or ticket code.
- Manual roster check-in/check-out and an attendee self-service portal.
- Live totals for registered, attended, currently inside, and checked out.
- Event reports with attendance rate, timestamps, duration, and UTF-8 CSV export.
- Department folders, school-year/semester classification, and Morning/Afternoon/Evening session attendance.
- Optional on-device face-presence checking with compressed time-in/time-out proof photos.
- Student information-correction requests with approval status, reviewer, remarks, and audit history.
- Official-enrollment CSV import with department, course, year, section, and temporary credentials.
- Automatic absence/missing-scan fines, student receipt uploads, and an administrator payment ledger.
- Officer and player assignments that reuse the student's single attendance identity.
- Administrator authentication, optional TOTP two-factor authentication, CSRF protection, rate limiting, audit logging, and secure sessions.
- Responsive light/dark administration interface.

The previous voting and feedback implementations remain in the repository as migration reference, but they are no longer linked from the live attendance interface.

## Requirements

- PHP 8.0 or newer with `pgsql`, `session`, `openssl`, `mbstring`, and `fileinfo`.
- PostgreSQL 12 or newer.
- Apache, XAMPP, or another PHP-capable web server.

## Installation

1. Create an empty PostgreSQL database.
2. Apply the attendance schema:

   ```text
   psql -U attendance_app -d attendance_system -f database/attendance_schema.sql
   ```

   The schema uses `CREATE TABLE IF NOT EXISTS`, so it can also be applied to the existing project database without deleting legacy records.

3. Copy `.env.example` to `.env` and set the database credentials. Generate `APP_KEY` with:

   ```text
   php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
   ```

4. Create the first administrator:

   ```text
   php database/create_admin.php
   ```

5. Serve the project directory and open `index.php`.

## Main workflow

1. Sign in as an administrator.
2. Create an event and set its schedule, capacity, status, and self-check-in policy.
3. Import the official student roster or generate temporary accounts, then distribute the one-time credentials securely.
4. Students complete first-login onboarding; their permanent Student ID credentials and personal QR replace the temporary login.
5. Register students for the event, configure the Morning/Afternoon/Evening sessions, and open only the active session.
6. Use **Check-in Desk** to scan QR/ID codes. Defined time-in and time-out windows are enforced by the server; authorized manual overrides are audit-logged.
7. Open **Reports** to review turnout and export CSV records, then generate applicable fines after the session closes.

## Department sessions and evidence

The **Events** page works like a department filing cabinet: create a folder for each department, open a folder to see only its events, and choose the destination folder while creating or editing an event. School-wide activities have a separate built-in folder. Folder cards show event totals and identify departments with ongoing events.

Open **Events → Sessions & rules**, select an event, and configure its department folder, school year, semester, attendance rules, fine amounts, and the Morning/Afternoon/Evening windows. Changing the department moves the event to the corresponding folder automatically. Unscheduled sessions remain drafts and collect no attendance.

When photo evidence is enabled, the scanning device captures a compressed JPEG during the scan and uses the browser's on-device `FaceDetector` capability to require a visible face. It does not identify the person or store a biometric face template. Browser/device builds without this capability must use an authorized manual override. Evidence is kept under protected `storage/evidence` and displayed only through the authenticated **Photo Archive**.

## Official student import

Download the CSV template from **Students → Import Official List**. Imported students receive temporary passwords and retain their official Student ID as the login identifier. Their first login still requires profile confirmation and a new password. Duplicate Student IDs are skipped instead of overwritten.

## Fines and payments

Enable fines and set the absence and missing-scan amounts in the event rules. After a session, **Fines** can generate idempotent charges from session attendance. Students upload receipt images through **Fines & Payments**; administrators verify or reject them in **Payment Ledger**. Verification updates both the payment and linked fine in one transaction.

## Export-first deletion and reset

Administrators can open **Data Management** to delete individual administrator/marshal accounts, students, events, department folders, courses, fines, payments, officer/player assignments, or information requests. The signed-in account and the last remaining administrator are protected from deletion. Each delete button remains locked until a fresh ZIP backup is downloaded. The backup contains a complete JSON snapshot plus uploaded attendance evidence and payment receipts, and the one-time deletion authorization expires after 15 minutes.

The same page provides a full operational-data reset. It requires downloading a complete backup and typing `RESET ATTENDANCE DATA`. The reset removes attendance operations and uploaded evidence while preserving administrator accounts, security settings, login history, branding, and audit logs so the administrator is not locked out.

## Bulk student account and QR workflow

1. An administrator opens **Attendees → Bulk Temporary Accounts** and generates up to 200 credentials at a time. The batch may be registered to an event immediately.
2. The administrator downloads the one-time CSV containing each temporary ID and password.
3. A student signs in with the temporary credentials. The system requires the student to enter their Student ID, complete name, email/phone, course, year level, and section, then choose a new password.
4. Saving the form replaces the temporary login with the permanent Student ID and password. The temporary ID can no longer be used.
5. The student portal creates and displays a permanent personal attendance QR, which can also be downloaded.
6. An administrator can create restricted accounts under **Marshals**. Marshal accounts can access only the Check-in Desk.
7. At the event, the marshal selects the event, opens the camera scanner, and scans the student's QR. The student must already be registered for the selected event; a QR alone cannot create unauthorized attendance.

## Data model

- `departments` and `courses`: official academic organization used by imports, searches, and event folders.
- `events` and `event_sessions`: event details, department ownership, rules, and Morning/Afternoon/Evening scan windows.
- `attendees`: student profiles, enrollment data, temporary/permanent account state, and QR identity.
- `event_registrations`: many-to-many event registration, waitlist state, and ticket code.
- `session_attendance`: per-session time-in/out, recorder, face-presence flags, and protected evidence references.
- `student_information_requests`: requested corrections, approval status, reviewer, and remarks.
- `fines` and `payments`: absence/missing-scan charges, receipt verification, and payment ledger.
- `officer_assignments` and `player_assignments`: event roles tied to the same student attendance identity.
- `admins`, `settings`, `audit_logs`, and `login_attempts`: administration and security.

## Security notes

- Passwords are stored using PHP password hashing.
- New write paths use parameterized PostgreSQL queries and CSRF tokens.
- A unique attendance record per registration prevents duplicate check-ins.
- Self check-in verifies the authenticated attendee, registration, event status, and event policy.
- Event deletion is blocked after registrations exist, preserving attendance history.
- CSV exports are available only to authenticated administrators.
- By default, the application restricts web access to the local network. Adjust `includes/session.php` only if deliberate external deployment is required and add TLS plus an appropriate reverse-proxy policy.
