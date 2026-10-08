# Morena Properties CRM — backend + wired UI

Laravel 12 implementation of `../export/HANDOVER.md`. It serves the 41 designed pages from `public/` and
powers them with the `/api/v1` JSON API described in handover §4. The original static designs stay
untouched in `../export/` as the visual reference.

## Run it locally

Requires PHP 8.2+, Composer, and SQLite (bundled with PHP). Node is not needed: the pages have no build step.

```bash
composer install
cp .env.example .env && php artisan key:generate      # first time only
# .env: APP_URL=http://localhost:8000 and SANCTUM_STATEFUL_DOMAINS=localhost:8000,127.0.0.1:8000
php artisan migrate:fresh --seed                       # reference data + demo agency
php artisan storage:link
php artisan serve --port=8000
```

Open http://localhost:8000. Every demo account uses the password **`password`**:

| Role | Email | Lands on |
|---|---|---|
| Admin | thandiwe@morenaproperties.co.za | admin/Dashboard |
| Branch manager (Pretoria) | kagiso.m@morenaproperties.co.za | manager/Dashboard |
| HR | busisiwe.n@morenaproperties.co.za | hr/StaffDirectory |
| Agent (senior) | naledi.m@morenaproperties.co.za | portal/Dashboard |
| Landlord (seller of 14 Rivonia Views) | abrahams@example.co.za | landlord/Dashboard |
| Tenant | r.dube@example.co.za | tenant/Dashboard |

Public client upload link (no login): http://localhost:8000/u/df73lp

Mail uses the `log` driver in development. Invite and password-reset links are written to `storage/logs/laravel.log`.

Tests: `php artisan test` (feature tests cover auth and invites, the §6 commission maths, role scoping, the §7 upload-link flow, and a smoke pass over every page's endpoint).

## How it fits together

- **Pages** (`public/{admin,sales,hr,docs,training,portal,manager,landlord,tenant,auth,client-upload}/*.html`) are the
  Design Components pages. The markup is unchanged apart from binding hardcoded values to `{{ … }}`. Each page's
  `componentDidMount` calls `shell(activeKey, roles)`, then fetches its endpoint. `renderVals()` maps the JSON onto
  the template, exactly as handover §8 describes.
- **`public/assets/app.js`** holds the API client (Sanctum session cookie and XSRF), the page shell (auth check, role-driven
  nav via `shell/nav-config.js`, signed-in user, sign-out), formatters, status-badge tones, and the modal form used by the
  "+ New …" buttons. **`public/assets/forms.js`** contains those create/edit dialogs.
- **Auth**: Sanctum SPA (cookie) auth with `statefulApi()`, and Spatie roles `admin, branch_manager, hr, agent, landlord, tenant`.
  Pages redirect to login on 401. Anyone opening a page outside their role is sent to their own home page.
- **Scoping** (`app/Support/Scope.php`): agents only ever see their own deals, leads, listings, commissions, links, and documents.
  Managers see their branch. Landlords and tenants see only documents filed against their sale or lease. A client-passed
  `agent_id` is ignored for scoped roles.
- **Commission** (`app/Services/CommissionService.php`) implements §6 on the server: base rate, VAT, a tier from
  trailing-12-month deals *or* volume, the agent/agency split, the co-listing split, and the referral fee. Results are persisted on
  `commissions` and recalculated while pending (on deal save, stage advance, or a rules change). Paid commissions are never rewritten.
- **Deals** (`app/Services/DealService.php`): every stage change writes `deal_stage_history` and `deal_activity_log`.
  Reaching the last stage closes the deal and marks the listing sold.
- **Files** go through Laravel's `Storage`, using the `local` disk in development. Set `FILESYSTEM_DISK=s3` in production (§1).
- **Client messaging** goes through the `App\Services\Messaging\NotificationChannel` interface. `LogChannel` is bound by default;
  swap in a WhatsApp Business implementation in `AppServiceProvider` once credentials exist.

## Workspace, security and productivity features

- **`public/assets/extras.js`** is loaded by `shell()` on every page and lives outside the React tree: the **Ctrl K command
  palette** (`GET /search`, scoped per role), the **notification bell** (`/notifications`, polled every 60 s), quick
  **task / appointment** dialogs, the **WhatsApp click-to-chat + email composer** (templates in `config/morena.php`),
  CSV import/export helpers, the **My Day** panel on dashboards, and local QR codes (`assets/vendor/qrcode.js`, MIT).
- **Workspace pages** (`public/work/`): Tasks, Calendar (month grid plus a private iCal feed at `/calendar/{token}.ics`),
  Calculators & CMA (transfer duty, bond, commission what-if, and a comparative market analysis from Morena's sold history), and
  Account Security (TOTP 2FA, recovery codes, sign-in history). Also `sales/Targets`, `sales/Brochure` (printable,
  with a WhatsApp QR), `admin/Audit` (record changes and sign-ins), and `finance/Planning` (budgets, budget vs actual, and recurring bills/invoices).
- **Security**: 5 wrong passwords lock the account for 15 minutes (a reset or an admin unlock lifts it). Optional TOTP 2FA with 8
  single-use recovery codes. Admins can reset a lost 2FA device. Every change to core records is written to `audit_logs`
  by the `Audited` trait.
- **Leads**: a 0–100 score (`LeadScoring`, refreshed daily), a duplicate guard (HTTP 409 unless `confirm_duplicate`),
  CSV import, and recorded POPIA marketing consent.
- **Scheduler**: one cPanel cron runs `schedule:run`. `morena:daily` (06:00) raises recurring documents, sends overdue-invoice
  reminders, and fires FFC/contract/mandate/task/appointment alerts, all de-duplicated. `morena:weekly-digest` runs Mondays at 07:00.
- **PWA**: `manifest.webmanifest`, `sw.js` and `offline.html`. The service worker caches static files only, never `/api`.

## Deviations from the handover (and why)

- **Laravel 12, not 11.** Composer refuses every Laravel 11 release on this machine because of published security
  advisories (Laravel 11 is past security support). Laravel 12 has the same structure and runs on PHP 8.2.
- **Pages, not Vue SFCs.** The handover allows either option. Wiring the existing pages to the API keeps the approved design
  intact. The only visible additions are a sidebar "Sign out" link, small row actions where the design had a button but no
  target ("Convert →", "Mark paid", "+ Upload"), empty states, and modal forms for the "+ New …" buttons. A later
  Vue/Inertia port can reuse every endpoint unchanged.
- **Schema additions** needed by the pages but missing from §3: `users.status` / `users.phone`, `staff.department`,
  `deals.status` / `branch_id` / `referral_agent_id` / `co_listing_split_percent` / `seller_user_id` / `bond_ltv_percent`,
  `listings.property_type` / `listed_at` / `mandate_expiry` / `branch_id`, `documents.retention_expires_at`,
  `document_categories.retention_group` / `retention_basis`, `client_upload_link_items.hint`, a `settings` singleton,
  and a minimal `tenancies` table (rentals stay in PayProp, per §9). `leads.source` uses the union of the two enum lists in §3.
- **One commission row per deal**, with `co_agent_id`/`co_agent_share` and `referral_agent_id`/`referral_fee_amount`, rather
  than one row per agent. This keeps "Deal hasOne Commission" (§5) and makes totals impossible to double-count.
- **Extra endpoints** the pages need: `GET /dashboard/{agent,manager,landlord,tenant}`, `PUT /auth/password`,
  `POST /auth/forgot-password`, `POST /auth/reset-password`, `POST /pipeline-stages/reset`, `GET /document-categories`,
  `GET /branches`, `GET /upload-links/{id}/items`, `POST /upload-links/{id}/resend`, `GET /contracts/{id}/download`,
  `GET /document-templates/{id}/download`, and `?format=csv` on `/analytics` for "Export report".
- **Logo width** (§9a) is stored in `settings.sidebar_logo_width` and returned by `/auth/me`. It is no longer kept in `localStorage`.

## Not built (out of scope per §9)

The rentals module and PayProp integration. The WhatsApp sender is stubbed behind `NotificationChannel`.
