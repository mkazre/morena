# Morena Properties CRM/ERP — Developer Handover

UI delivered as 41 static HTML pages (Design Components — plain HTML/CSS/JS, no build step) under this project. Build the Laravel backend to power these exact pages. This doc maps every page to its data, database tables, and API contract.

## 1. Recommended stack
- **Backend:** Laravel 11, MySQL/PostgreSQL, Laravel Sanctum (SPA session auth), Spatie `laravel-permission` for roles.
- **Frontend:** Vue 3 + Inertia.js (keeps the page-per-route structure already built; no separate REST client needed) OR Vue 3 + Pinia + this UI's existing pages converted to Vue SFCs calling a JSON API — either works with the contracts below, which are written API-first so both are supported.
- **File storage:** S3-compatible bucket (documents, templates, uploaded files) via Laravel's `Storage` facade. Never store documents on local disk in production (5-year retention + multi-branch access).
- **Queues:** Laravel queues for commission runs, retention-expiry notifications, upload-link emails/WhatsApp.
- **Integrations:** PayProp (rentals, stubbed — not built in this UI pass) and WhatsApp Business API (for sending client upload links) are config-only placeholders in `admin/Settings.dc.html` — build as separate service classes implementing a common `NotificationChannel` interface.

## 2. Roles & auth
Spatie roles: `admin`, `branch_manager`, `hr`, `agent`, `landlord`, `tenant`. Each maps 1:1 to the nav sets in `shell/nav-config.js`. Landlord/tenant are portal-only (no `users` table password login required if you prefer magic-link auth — recommended given low-frequency use).

`users` table: id, name, email, phone, password, role (or pivot via Spatie), branch_id (nullable), agent_profile_id (nullable FK), status (active/suspended), last_login_at.

## 3. Database schema by module

### Sales CRM
- `branches` (id, name, address)
- `deal_stage_history` (id, deal_id, pipeline_stage_id, entered_at) — append-only log written on every stage advance; powers Analytics' sales-velocity-by-stage widget
- `deals.lost_reason` enum (nullable: bond_declined, price_disagreement, buyer_withdrew, chose_another_property, seller_withdrew_mandate) — set when a deal is marked lost; powers Analytics' lost-deal-reasons widget
- `leads.source` enum (referral, website, whatsapp, walk_in, portal) — powers Analytics' lead-source-performance widget
- `pipeline_stages` (id, name, position, color_hex, is_default_seed boolean) — admin-editable list backing `sales/PipelineSettings.dc.html`
- `pipeline_stage_documents` (id, pipeline_stage_id, document_template_id) — required docs per stage shown as chips
- `leads` (id, name, phone, email, interest_description, source enum[website,referral,walkin,whatsapp], assigned_agent_id, status enum[new,contacted,qualified,converted,lost], created_at)
- `deals` (id, listing_id nullable, lead_id nullable, property_title, sale_value, pipeline_stage_id, listing_agent_id, selling_agent_id, buyer_name, seller_name, mandate_type enum[sole,open], bond_required boolean, expected_close_date, closed_at)
- `deal_stage_history` (id, deal_id, pipeline_stage_id, entered_at, exited_at) — powers the activity log & "days in stage" on `sales/Pipeline.dc.html` / `sales/DealDetail.dc.html`
- `deal_activity_log` (id, deal_id, user_id, text, created_at)
- `listings` (id, title, suburb, price, status enum[active,under_offer,sold], beds, baths, agent_id, photo_path)
- `commission_settings` (id, base_rate_percent default 6.0, vat_registered boolean, vat_rate default 15.0, co_listing_split_default_percent default 50, referral_fee_percent default 10)
- `commission_tiers` (id, name, min_deals nullable, min_volume nullable, agent_split_percent, agency_split_percent, position) — rows shown in `sales/CommissionSettings.dc.html` table, fully admin-editable
- `commissions` (id, deal_id, agent_id, sale_value, commission_amount, vat_amount, agent_share, agency_share, commission_tier_id, status enum[pending,paid], paid_at)

### HR & Records
- `staff` (id, user_id, role_title, branch_id, employment_type enum[independent_contractor,permanent], start_date, ffc_number, ffc_expiry, status)
- `contracts` (id, staff_id, type, start_date, expiry_date nullable, status enum[active,renewal_due,probation,terminated], file_path)
- `leave_balances` (id, staff_id, annual_days, sick_days, taken_days, year)
- `leave_requests` (id, staff_id, type enum[annual,sick,other], start_date, end_date, days, status enum[pending,approved,declined], approved_by)
- `onboarding_checklists` (id, staff_id, start_date, role_title)
- `onboarding_tasks` (id, checklist_id, label, is_done boolean, order)

### Document Management
- `document_categories` (id, name, retention_years default 5)
- `document_templates` (id, name, category_id, used_for, current_version_id)
- `document_template_versions` (id, template_id, version_number, file_path, uploaded_by, created_at)
- `documents` (id, name, category_id, linked_model_type, linked_model_id, version, file_path, uploaded_by, created_at) — polymorphic link to `deals`, `leads`, `staff`, `listings`
- `client_upload_links` (id, token unique, agent_id, client_name, deal_id nullable, status enum[awaiting_client,uploaded,filed,expired], expires_at)
- `client_upload_link_items` (id, link_id, document_label, is_required boolean, uploaded_document_id nullable, status enum[required,uploaded,filed])

### Training / LMS
- `courses` (id, title, category, duration_minutes)
- `lessons` (id, course_id, title, duration_minutes, order)
- `course_completions` (id, course_id, staff_id, status enum[not_started,in_progress,complete], completed_at)
- `lesson_completions` (id, lesson_id, staff_id, completed_at)
- `certifications` (id, staff_id, ffc_number, ffc_expiry, cpd_points, cpd_target default 10, status enum[compliant,behind,pending])

## 4. API endpoints (REST, JSON — prefix `/api/v1`)

| Area | Method & path | Purpose |
|---|---|---|
| Auth | `POST /auth/login`, `POST /auth/logout`, `GET /auth/me` | Sanctum session auth, returns user + role + nav scope |
| Dashboard | `GET /dashboard/admin` | KPI cards, pipeline-by-stage, top agents, recent deals, alerts for `admin/Dashboard.dc.html` |
| Analytics | `GET /analytics?range=` | Full analytics suite for `admin/Analytics.dc.html`: 6 KPI cards (sales value, deal count, avg deal size, avg days to close, lead→deal conversion %, commission paid — each with YoY delta), 12-month volume+deal-count chart, pipeline funnel (count per stage), deals/value by branch, lead source performance %, property-type mix %, agent leaderboard (deals, value, avg days, commission — YTD), sales velocity (avg days per stage transition), 6-month commission payout history, listings inventory health (active/under offer/avg days on market/expired mandates), lost-deal reasons (last 90 days) |
| Pipeline | `GET /pipeline-stages`, `POST /pipeline-stages`, `PUT /pipeline-stages/{id}`, `DELETE /pipeline-stages/{id}`, `POST /pipeline-stages/reorder` | Admin-editable stage config for `sales/PipelineSettings.dc.html` |
| Deals | `GET /deals`, `GET /deals/{id}`, `POST /deals`, `PUT /deals/{id}`, `POST /deals/{id}/advance-stage` | `sales/Deals.dc.html`, `sales/Pipeline.dc.html` (grouped by stage), `sales/DealDetail.dc.html` |
| Leads | `GET /leads`, `POST /leads`, `PUT /leads/{id}`, `POST /leads/{id}/convert` | `sales/Leads.dc.html`, `portal/MyLeads.dc.html` (scoped to `agent_id = auth user`) |
| Listings | `GET /listings`, `POST /listings`, `PUT /listings/{id}` | `sales/Listings.dc.html` |
| Commission settings | `GET /commission-settings`, `PUT /commission-settings`, `GET /commission-tiers`, `POST/PUT/DELETE /commission-tiers/{id}` | `sales/CommissionSettings.dc.html` |
| Commissions | `GET /commissions` (filter by month/agent), `POST /commissions/{id}/mark-paid` | `sales/Commissions.dc.html`, `portal/MyCommission.dc.html` (scoped) |
| Staff | `GET /staff`, `GET /staff/{id}`, `POST /staff`, `PUT /staff/{id}` | `hr/StaffDirectory.dc.html`, `hr/StaffProfile.dc.html` |
| Contracts | `GET /contracts`, `POST /contracts`, `PUT /contracts/{id}` | `hr/Contracts.dc.html` |
| Leave | `GET /leave-requests`, `POST /leave-requests`, `POST /leave-requests/{id}/approve`, `POST /leave-requests/{id}/decline`, `GET /leave-balances` | `hr/Leave.dc.html`, `manager/LeaveApprovals.dc.html` (scoped to branch) |
| Onboarding | `GET /onboarding`, `PUT /onboarding-tasks/{id}` | `hr/Onboarding.dc.html` |
| Documents | `GET /documents?category=&search=`, `POST /documents`, `GET /documents/{id}/download` | `docs/Library.dc.html`, `portal/MyDocuments.dc.html` |
| Templates | `GET /document-templates`, `POST /document-templates/{id}/new-version` | `docs/Templates.dc.html` |
| Upload links | `GET /upload-links`, `POST /upload-links` (generate), `GET /upload-links/{token}` (public, no auth), `POST /upload-links/{token}/submit` (public), `POST /upload-links/{id}/file` (agent files a submission) | `docs/UploadLinks.dc.html`, `portal/MyUploadLinks.dc.html`, `client-upload/Upload.dc.html` |
| Retention | `GET /retention/summary`, `GET /retention/expiring?days=90` | `docs/Retention.dc.html` |
| Courses | `GET /courses`, `GET /courses/{id}`, `POST /courses`, `PUT /lessons/{id}/complete` | `training/Courses.dc.html`, `training/CourseDetail.dc.html`, `portal/MyTraining.dc.html` |
| Certifications | `GET /certifications` | `training/Certifications.dc.html` |
| Users/Roles | `GET /users`, `POST /users/invite`, `PUT /users/{id}/role` | `admin/Users.dc.html` |
| Settings | `GET /settings`, `PUT /settings` | `admin/Settings.dc.html` |

All list endpoints accept `?branch_id=`, `?agent_id=`, pagination (`?page=`), and relevant filters shown in each page's dropdowns. Agent-portal endpoints (`portal/*`) are the same resources as admin endpoints but auto-scoped server-side to `auth()->user()->staff->id` — do not expose a client-passable agent filter on those routes.

## 5. Key Eloquent relationships

```
User hasOne Staff
Staff hasMany Deals (as listing_agent_id / selling_agent_id)
Staff hasMany Leads (as assigned_agent_id)
Staff hasOne Certification
Staff hasMany Contracts
Staff hasOne LeaveBalance (per year)
Staff hasMany LeaveRequests
Staff hasOne OnboardingChecklist
Staff belongsToMany Course (through CourseCompletion) withPivot('status','completed_at')

Deal belongsTo PipelineStage
Deal hasMany DealStageHistory
Deal hasMany DealActivityLog
Deal hasOne Commission
Deal morphMany Document (as linked_model)

PipelineStage hasMany Deal
PipelineStage belongsToMany DocumentTemplate (through PipelineStageDocument)

Commission belongsTo Deal, belongsTo Staff (agent), belongsTo CommissionTier

ClientUploadLink belongsTo Staff (agent), hasMany ClientUploadLinkItem
ClientUploadLinkItem belongsTo Document (nullable, once uploaded)

DocumentTemplate hasMany DocumentTemplateVersion
DocumentTemplate belongsTo currentVersion (DocumentTemplateVersion)
```

## 6. Commission calculation logic (server-side service, not client-computed)
`CommissionService::calculateForDeal($deal)`:
1. `commission_amount = deal.sale_value * commission_settings.base_rate_percent / 100`
2. If `vat_registered`, `vat_amount = commission_amount * vat_rate / 100`
3. Resolve agent's `commission_tier` by their trailing-12-month deal count or sale volume against `commission_tiers` thresholds.
4. `agent_share = commission_amount * tier.agent_split_percent / 100`; `agency_share = commission_amount - agent_share`.
5. If deal has a co-listing agent, split `agent_share` by `commission_settings.co_listing_split_default_percent` (editable per deal).
6. If deal has a referring agent, deduct `referral_fee_percent` of `agent_share` before payout.
Persist all of the above on `commissions` — never recompute in the UI; the UI only displays stored values (this matches what's rendered in `sales/DealDetail.dc.html` and `sales/Commissions.dc.html`).

## 7. Client upload link flow (`docs/UploadLinks.dc.html` + `client-upload/Upload.dc.html`)
1. Agent/admin generates a link: `POST /upload-links` with `client_name`, `deal_id` (optional), and a list of required document labels → creates `client_upload_links` + `client_upload_link_items`, returns `token`.
2. Link shared as `https://app.morenaproperties.co.za/u/{token}` (public route, no auth — validate token + expiry server-side).
3. Client visits `client-upload/Upload.dc.html`, sees the checklist (`GET /upload-links/{token}`), uploads files (`POST /upload-links/{token}/submit`) — creates `documents` rows with `linked_model_type = ClientUploadLink`, flips item status to `uploaded`, link status to `uploaded`.
4. Agent reviews in `docs/UploadLinks.dc.html` / `portal/MyUploadLinks.dc.html`, clicks "Review & file" → `POST /upload-links/{id}/file` re-links each `Document` to the correct `linked_model` (a `Deal` or `Staff`/client record) and sets item status `filed`.
5. Admin sees all links and statuses regardless of which agent generated them (no scoping on `docs/UploadLinks.dc.html`).

## 8. Page → data quick reference
Every `.dc.html` file's `renderVals()` currently returns hardcoded arrays — replace each with a fetch to the matching endpoint above on `componentDidMount`, keeping the same shape (field names already match column names 1:1 where practical, e.g. `deal.property`, `deal.agent`, `deal.stage`, `deal.value`). `shell/nav-config.js`'s `decorateNav(role, activeKey)` should be fed the authenticated user's role and the current route key — no change needed there, it's pure UI.

**`admin/Analytics.dc.html` field-level mapping** (all driven by the `?range=` query param — `ytd`/`quarter`/`month`/`12m`):
- `kpis[]` → aggregate queries over `deals` (sum `sale_value` where `status=closed` in range; count; avg; avg `DATEDIFF(closed_at, created_at)`), `leads→deals` conversion = `COUNT(deals)/COUNT(leads)` in range, `SUM(commissions.commission_amount)`. Delta % = same aggregate for the prior equivalent period.
- `months[]` (12-month bars) → `deals` grouped by `MONTH(closed_at)`, `SUM(sale_value)` and `COUNT(*)` per month.
- `funnel[]` → `COUNT(deals)` grouped by `pipeline_stage_id`, ordered by `pipeline_stages.position`.
- `branches[]` → `deals` grouped by `branch_id` joined to `branches.name`.
- `sources[]` → `leads.source` enum grouped and normalized to % of total leads in range.
- `propTypes[]` → `deals` joined to `listings.property_type` grouped to %.
- `leaderboard[]` → per-agent aggregate: `COUNT(deals)`, `SUM(sale_value)`, `AVG(DATEDIFF(closed_at,created_at))`, `SUM(commissions.commission_amount)` — same source as `sales/Commissions.dc.html`, just ranked.
- `velocity[]` → avg time between consecutive `deal_stage_history` timestamps per stage transition (requires a `deal_stage_history` table: id, deal_id, pipeline_stage_id, entered_at — log a row every time `POST /deals/{id}/advance-stage` fires).
- `commissionHist[]` → `commissions` grouped by month, `SUM(commission_amount)` where `status=paid`.
- `inventory[]` → `listings` counts by status (`active`, `under_offer`), `AVG(DATEDIFF(NOW(), listed_at))` for active, count where `mandate_expiry < NOW()`.
- `lostReasons[]` → requires a `lost_reason` enum column on `deals` (set when a deal is marked lost) grouped and counted over the last 90 days.

## 9a. Branding setting
`admin/Settings.dc.html` has a "Sidebar logo size" slider (100–260px) that writes `morena_logo_width` to `localStorage` and every page's sidebar logo (`#sidebarLogo`) reads it via `assets/logo-scale.js`. In the Laravel build, move this to a real setting: `GET/PUT /settings` with a `sidebar_logo_width` column on a `settings` table (singleton row), applied server-side (e.g. inline style from a Blade/Inertia shared prop) instead of `localStorage`, so it's consistent per-organization rather than per-browser.

## 9. Not built in this pass (flagged, out of scope per client brief)
- Full Rentals module (client chose to keep PayProp; only placeholder mentions exist in `admin/Settings.dc.html`).
- PayProp API integration itself (stub a `PayPropService` class for later).
