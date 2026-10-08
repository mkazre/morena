# Morena Properties CRM/ERP — UI Project

Client: Morena Properties (SA real estate agency). Building exhaustive UI in Design Components, for Laravel+Vue backend handoff. See `HANDOVER.md` (being built progressively) for DB/API mapping.

## Color tokens (from logo, use everywhere — never invent new colors)
- `--ink: #1c1a19` (near-black, primary text/icons)
- `--maroon: #7a1528` (primary brand accent — buttons, active states, highlights)
- `--maroon-dark: #561020` (hover/pressed)
- `--maroon-light: #c23552` (secondary accent, badges, charts)
- `--paper: #ffffff`
- `--cream: #faf8f6` (app background)
- `--line: #e8e3e0` (borders/dividers)
- `--ash: #6b6660` (secondary/muted text)
- `--success: #2f7d5a` / `--warning: #c07c1e` / `--danger: #b42318`

## Typography
UI font: **Plus Jakarta Sans** (Google Fonts, weights 400/500/600/700) via `<helmet>` link, loaded once per page. The logo's script wordmark is brand-only — never recreate it in UI type; use the logo image asset for the wordmark.

## Assets
- `assets/logo.png` — Morena Properties logo (copied from upload).

## Structure
- `shell/nav-config.js` — plain JS module exporting `getNavForRole(role)`, the single source of truth for sidebar nav items per role (admin, agent, hr, manager, landlord, tenant). Every page's logic class imports it dynamically and exposes nav items to the template.
- Each page is a standalone `.dc.html` (per system rules — no shared shell DC; sidebar/topbar markup is repeated inline per page like deck slides, driven by nav-config.js data so content stays consistent).
- Folders: `auth/`, `admin/`, `sales/`, `hr/`, `docs/`, `training/`, `portal/` (agent self-service), `manager/`, `landlord/`, `tenant/`, `client-upload/` (public link pages, no sidebar).
- Shared page chrome pattern: fixed left sidebar (240px, `--ink` bg, logo top, nav grouped by section, role switcher removed in prod — nav-config drives active state via `activeKey` passed into each page's logic), topbar (breadcrumb + search + user menu), content area `--cream` bg with white cards.

## Commission logic (SA market research, for Settings UI + handover)
Standard commission 5–7.5% of sale price (VAT extra if agency is VAT vendor). Agency/agent split typically tiered by experience/volume: Junior 20–40%, Experienced 40–60%, Top/senior 60–70%+. Settings page must let admin configure: base commission %, VAT toggle, agent split tiers (editable table: tier name, min deals/volume threshold, split %), referral/co-listing agent splits, and whether commission is on gross or net-of-VAT amount.

## Sales pipeline
Fully admin-editable stages (not hardcoded). Default seed: Lead → Qualified → Viewing Scheduled → Offer Made → Offer Accepted → FICA/Due Diligence → Bond Approved → Deed of Sale Signed → Registration (Transfer) → Deal Closed. Admin can add/remove/reorder/rename stages and attach milestones/required documents per stage from a Pipeline Settings page.

## Progress log
- [ ] Shell pattern + nav-config.js
- [ ] Auth
- [ ] Admin dashboards
- [ ] Sales CRM
- [ ] HR
- [ ] Documents + client upload links
- [ ] Training/LMS
- [ ] Agent portal
- [ ] Manager/Landlord/Tenant views
- [ ] HANDOVER.md
