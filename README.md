# Restaurant POS — Backend API

REST API for a restaurant management / point-of-sale system, built with **Laravel 11** and **Laravel Sanctum**.
It covers the full restaurant workflow: menu, tables and floor plan, orders, kitchen, delivery, payments and refunds,
cash drawers, staff and shifts, payroll, reports, and a public QR / online ordering interface.

> The matching React frontend lives in a separate repository: `restaurant-frontend`.

## Features

| Area | What it does |
|---|---|
| **Authentication** | Token-based login (Sanctum), password reset by email, change password, **forced password change on first login** for accounts created by an admin |
| **Roles & permissions** | 6 roles (`admin`, `manager`, `cashier`, `waiter`, `chef`, `driver`) plus fine-grained per-user permissions (`config/permissions.php`) |
| **Menu** | Categories, items, modifier groups, tax per item/category, translations, image upload, inventory and low-stock tracking |
| **Orders** | Dine-in / takeaway / delivery, coupons, loyalty points, cent-exact totals and tax, idempotent creation |
| **Kitchen & delivery** | Kitchen status flow, driver assignment, delivery tracking and cancellation |
| **Tables** | Visual floor plan, table status, reservations with double-booking protection |
| **Payments** | Cash / card / online payments, refunds with approval, cash drawers and cash movements, Z-report |
| **Staff** | Clock in/out shifts, shift scheduling with conflict detection, auto-close of forgotten shifts |
| **Payroll** | Hourly or monthly salary, weekly overtime, payslips (PDF), payroll export (CSV / XLSX) |
| **Reports** | Sales, top items, revenue by category, profit, staff performance — exportable to CSV / PDF |
| **Audit log** | Every create / update / delete on sensitive models is recorded (who, when, what changed) |
| **Public ordering** | QR-code table menu, online ordering and order tracking for customers (disabled by default, rate-limited) |
| **Multi-currency** | Configurable currencies for menu display |

## Tech stack

- PHP **8.2+** (CI runs on 8.3 and 8.4)
- Laravel **11**, Laravel Sanctum 4
- MySQL for production (see `.env.production.example`), SQLite for local development and tests
- PHPUnit 11 (feature + unit tests)

## Getting started

### Requirements

- PHP 8.2 or newer with the `mbstring`, `pdo_sqlite` (or `pdo_mysql`), `intl`, `zip` extensions
- [Composer](https://getcomposer.org/)

### Installation

```bash
git clone https://github.com/<your-username>/restaurant-backend.git
cd restaurant-backend

composer install                 # installs everything into vendor/ (not committed to git)
cp .env.example .env
php artisan key:generate

# Local database (SQLite is the default in .env.example)
touch database/database.sqlite
php artisan migrate --seed

php artisan serve                # http://127.0.0.1:8000
```

> `vendor/` and `.env` are intentionally **not** in the repository. `composer install` recreates `vendor/`
> from `composer.lock`, and `.env` is created from `.env.example`.

To use MySQL instead of SQLite, set the `DB_*` values in your `.env` (see `.env.production.example`).

### Demo accounts

`php artisan db:seed` creates a demo restaurant (menu, tables, customers, coupons) and these users:

| Role | Email |
|---|---|
| Admin | `admin@restaurant.com` |
| Cashier | `cashier@restaurant.com` |
| Driver | `driver@restaurant.com` |

In the `local` and `testing` environments the seeded password is `password`.
In any other environment the seeder uses `SEED_DEFAULT_PASSWORD` from `.env`, or generates a random password and prints it once.
**Change or delete the demo accounts before going live.**

## Running the tests

```bash
php artisan test
```

Tests run against an in-memory SQLite database and are executed automatically on every push / pull request by
GitHub Actions (`.github/workflows/tests.yml`).

## Scheduled tasks

The scheduler must be running in production. Add this cron entry:

```
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

| Command | Frequency | Purpose |
|---|---|---|
| `reservations:sync-tables` | every minute | Holds / releases tables around reservation times |
| `shifts:auto-close` | every 15 minutes | Closes shifts staff forgot to clock out of |
| `idempotency:prune` | hourly | Removes expired idempotency keys |
| `sanctum:prune-expired` | daily | Removes expired API tokens |

## API overview

All endpoints are under `/api`. Authenticate with `Authorization: Bearer <token>` (token returned by `POST /api/login`)
and send `Accept: application/json`.

| Group | Endpoints (prefix `/api`) |
|---|---|
| Auth | `login`, `logout`, `me`, `change-password`, `forgot-password`, `reset-password` |
| Catalog | `categories`, `menu-items` (incl. nested `modifier-groups`), `inventory`, `currencies` |
| Sales | `orders` (incl. `orders/{order}/invoice`), `payments`, `refunds`, `coupons`, `customers`, `kitchen`, `deliveries` |
| Floor | `tables`, `floors`, `reservations` |
| Cash | `cash-drawers` (incl. nested `movements`), `expenses` |
| Staff | `users`, `permissions`, `shifts`, `shift-schedules`, `staff-directory`, `payroll` |
| Insights | `dashboard`, `reports` (incl. `reports/export`), `audit-logs`, `notifications`, `reviews` |
| Public (no login) | `public/info`, `public/menu`, `public/tables/{token}`, `public/orders` |

Run `php artisan route:list --path=api` for the complete, current list. A Postman collection is available in `postman/`.

### Authorization model

Each user has a **role**, which defines default permissions. Access to a route is checked with the
`permission:<name>` middleware (for example `orders.manage`, `reports.export`). An admin can grant extra permissions to,
or revoke permissions from, a single user without changing their role:

```
PUT /api/users/{user}/permissions
{ "extra_permissions": ["reports.view"], "revoked_permissions": [] }
```

User and permission management is restricted to the `admin` role by design, to prevent privilege escalation.

### Forced password change

When an admin creates an account, or resets another user's password, the account is flagged `must_change_password`.
That user can log in, but every endpoint except `GET /api/me`, `POST /api/change-password` and `POST /api/logout`
returns **403** with `{"code": "password_change_required"}` until they choose a new password. This is enforced on the
server (`EnsurePasswordIsChanged` middleware), not only in the UI.

## Security notes

- Login and password-reset routes are throttled (6 requests / minute / IP).
- Tokens expire (`SANCTUM_EXPIRATION`). Other sessions are revoked on password change, and all tokens on role change, deactivation, or deletion.
- Deactivated users lose access immediately, even with a still-valid token.
- CORS origins are restricted via `CORS_ALLOWED_ORIGINS`.
- Sensitive money-related operations use database transactions and row locks; order creation and payments are idempotent.
- Passwords are excluded from the audit log.

## Production notes

- Copy `.env.production.example` to `.env` on the server and fill in the values. Use `APP_DEBUG=false` and a fresh `APP_KEY`.
- Use **MySQL**, not SQLite, when more than one cashier works at the same time (concurrency protection relies on row locks).
- Run `php artisan migrate --force` on every deploy.

## Known limitations

- PDF exports use the built-in Helvetica font, so Arabic text is transliterated or shown as `?`. CSV exports keep Arabic text intact.
  Full Arabic PDF support requires adding a library such as mPDF or dompdf.

## Project structure

```
app/
  Actions/        Single-purpose business actions (payroll, reservations)
  Console/        Scheduled artisan commands
  Enums/          Status enums
  Http/           Controllers, middleware, API resources
  Models/         Eloquent models
  Services/       Order, sales report, payroll, PDF / CSV / XLSX exporters
config/           Application config (incl. permissions.php, cors.php)
database/         Migrations, factories, demo seeder
routes/api.php    All API routes
tests/            Feature and unit tests
```
