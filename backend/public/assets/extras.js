// Workspace extras loaded by app.shell() on every staff page: the ⌘K command palette, the notification bell,
// quick "new task / book appointment" forms, the WhatsApp + email composer and CSV import/export helpers.
// Everything here lives outside the React tree (plain DOM), so page re-renders never remove it.
import { api, run, toast, form, formData, date, when, ago, me } from './app.js';
import { icon } from './icons.js';
import { NAV_ICONS } from '../shell/nav-config.js';

const STAFF = ['admin', 'branch_manager', 'hr', 'finance', 'agent'];
const el = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; };

/** Pages every staff role can open, added to the palette alongside the role's own sidebar links. */
export const WORK_PAGES = [
  { label: 'Tasks', href: '/work/Tasks.html', icon: 'tasks' },
  { label: 'Calendar', href: '/work/Calendar.html', icon: 'calendar' },
  { label: 'Calculators & CMA', href: '/work/Tools.html', icon: 'calculator' },
  { label: 'Account security (2FA)', href: '/work/Security.html', icon: 'lock' },
];

let installed = false;
let session;
let navItems = [];

export function install(s, navSections) {
  session = s;
  navItems = navSections.flatMap((sec) => sec.items.map((i) => ({ label: i.label, href: i.href, icon: NAV_ICONS[i.key] || 'chevron-right', group: sec.title })));
  if (installed) return;
  installed = true;
  const staff = STAFF.includes(s.user.role);
  mountTools(staff);
  setInterval(() => { if (!document.querySelector('.mx-tools')) mountTools(staff); }, 2000);
  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); openPalette(); }
  });
  pollUnread();
}

// ---- Header tools ---------------------------------------------------------------------------

let badgeEls = [];
function mountTools(staff) {
  const header = document.querySelector('header');
  const tools = el('div', 'mx-tools');
  if (staff) {
    const search = el('button', 'mx-search');
    search.type = 'button';
    search.innerHTML = icon('search', 16) + '<span class="mx-label">Search</span><kbd>Ctrl K</kbd>';
    search.onclick = () => openPalette();
    tools.appendChild(search);
  }
  if (staff) tools.appendChild(exportButton());
  const bell = el('button', 'mx-bell');
  bell.type = 'button';
  bell.setAttribute('aria-label', 'Notifications');
  bell.innerHTML = icon('bell', 17);
  const badge = el('span', 'mx-badge');
  bell.appendChild(badge);
  bell.onclick = (e) => { e.stopPropagation(); togglePanel(bell); };
  tools.appendChild(bell);
  badgeEls = [badge];
  setBadge(lastUnread);
  if (header) { header.classList.add('mx-hdr'); header.appendChild(tools); }
  else { tools.classList.add('mx-float'); document.body.appendChild(tools); }
}

// ---- Export (CSV / Excel / PDF of the page's tables) ------------------------------------------

const hasExportData = () => typeof window.__morenaExport === 'function'
  || [...document.querySelectorAll('main table')].some((t) => t.tBodies[0] && [...t.tBodies[0].rows].some((r) => r.cells.length > 1 && !r.querySelector('.empty')));

function exportButton() {
  const wrap = el('div', 'mx-export');
  const btn = el('button', 'mx-search');
  btn.type = 'button';
  btn.innerHTML = icon('download', 16) + '<span class="mx-label">Export</span>';
  btn.title = 'Export this page (CSV, Excel, PDF)';
  wrap.style.display = 'none';
  const menu = el('div', 'mx-menu');
  const opts = [['csv', 'csv', 'CSV', 'Opens in Excel, Sheets, Numbers'], ['xlsx', 'excel', 'Excel (.xlsx)', 'Formatted workbook, one sheet per table'], ['pdf', 'pdf', 'PDF', 'Branded report with logo and page numbers']];
  for (const [fmt, ic, label, hint] of opts) {
    const b = el('button', 'mx-menu-item');
    b.type = 'button';
    b.innerHTML = icon(ic, 16) + '<span><strong></strong><small></small></span>';
    b.querySelector('strong').textContent = label;
    b.querySelector('small').textContent = hint;
    b.onclick = async () => {
      menu.classList.remove('open');
      try {
        const x = await import('./export.js');
        const rows = await x.exportPage(fmt, session);
        toast(`Exported ${rows} row${rows === 1 ? '' : 's'} as ${label}`);
      } catch (e) { toast(e.message || 'Export failed', 'danger'); }
    };
    menu.appendChild(b);
  }
  btn.onclick = (e) => { e.stopPropagation(); menu.classList.toggle('open'); };
  document.addEventListener('click', (e) => { if (!wrap.contains(e.target)) menu.classList.remove('open'); });
  wrap.append(btn, menu);
  const check = () => { wrap.style.display = hasExportData() ? '' : 'none'; };
  check();
  setInterval(check, 1500);
  return wrap;
}

let lastUnread = 0;
function setBadge(n) {
  lastUnread = n;
  for (const b of badgeEls) { b.textContent = n > 9 ? '9+' : String(n); b.style.display = n ? '' : 'none'; }
}

async function pollUnread() {
  const tick = async () => {
    if (document.hidden) return;
    try { setBadge((await api('/notifications/unread-count', { redirectOn401: false })).unread); } catch { /* offline or signed out */ }
  };
  tick();
  setInterval(tick, 60000);
}

// ---- Notification panel ---------------------------------------------------------------------

let panel;
async function togglePanel(anchor) {
  if (panel) { closePanel(); return; }
  panel = el('div', 'mx-panel');
  const head = el('div', 'mx-panel-head');
  head.appendChild(el('strong', null, 'Notifications'));
  const all = el('button', 'link small', 'Mark all read');
  all.type = 'button';
  head.appendChild(all);
  const list = el('div', 'mx-panel-list');
  list.appendChild(el('div', 'empty', 'Loading…'));
  panel.append(head, list);
  document.body.appendChild(panel);
  const r = anchor.getBoundingClientRect();
  panel.style.top = (r.bottom + 8) + 'px';
  panel.style.right = Math.max(12, innerWidth - r.right) + 'px';
  setTimeout(() => document.addEventListener('click', outside), 0);

  const res = await run(() => api('/notifications'));
  if (!res || !panel) return;
  setBadge(res.unread);
  list.innerHTML = '';
  if (!res.data.length) list.appendChild(el('div', 'empty', "You're all caught up."));
  for (const n of res.data) {
    const row = el(n.url ? 'a' : 'div', 'mx-note' + (n.read ? '' : ' unread'));
    if (n.url) row.href = n.url;
    row.appendChild(el('div', 'mx-note-title', n.title));
    if (n.body) row.appendChild(el('div', 'mx-note-body', n.body));
    row.appendChild(el('div', 'mx-note-at', ago(n.at)));
    row.onclick = () => { if (!n.read) api('/notifications/read', { method: 'POST', body: { ids: [n.id] } }).catch(() => {}); };
    list.appendChild(row);
  }
  all.onclick = async () => {
    const out = await run(() => api('/notifications/read', { method: 'POST', body: {} }));
    if (out) { setBadge(out.unread); list.querySelectorAll('.unread').forEach((x) => x.classList.remove('unread')); }
  };
}
function outside(e) { if (panel && !panel.contains(e.target)) closePanel(); }
function closePanel() { panel?.remove(); panel = null; document.removeEventListener('click', outside); }

// ---- Command palette ------------------------------------------------------------------------

function actions() {
  const role = session.user.role;
  const list = [
    { label: 'New task', icon: 'tasks', group: 'Actions', run: () => quickTask() },
    { label: 'Book a viewing or meeting', icon: 'calendar', group: 'Actions', run: () => quickAppointment() },
  ];
  if (['admin', 'branch_manager', 'agent'].includes(role)) list.push({ label: 'Transfer duty & bond calculator', icon: 'calculator', group: 'Actions', href: '/work/Tools.html' });
  if (['admin', 'finance'].includes(role)) {
    list.push({ label: 'New invoice', icon: 'invoice-out', group: 'Actions', href: '/finance/Document.html?kind=invoices&new=1' });
    list.push({ label: 'New bill', icon: 'invoice-in', group: 'Actions', href: '/finance/Document.html?kind=bills&new=1' });
  }
  return list;
}

const GROUP_ICONS = { Deals: 'deals', Leads: 'leads', Listings: 'listings', People: 'staff', Invoices: 'invoice-out', Contacts: 'contacts', Documents: 'documents' };

export async function openPalette() {
  if (document.querySelector('.mx-palette')) return;
  session ??= await me();
  const overlay = el('div', 'mx-overlay');
  const box = el('div', 'mx-palette');
  const input = el('input', 'mx-palette-input');
  input.placeholder = 'Search deals, leads, people, invoices, documents… or jump to a page';
  input.setAttribute('aria-label', 'Search');
  const list = el('div', 'mx-palette-list');
  const foot = el('div', 'mx-palette-foot', '↑↓ to move · Enter to open · Esc to close');
  box.append(input, list, foot);
  overlay.appendChild(box);
  document.body.appendChild(overlay);
  input.focus();

  const pages = [...navItems.map((p) => ({ ...p, group: 'Go to' })), ...WORK_PAGES.filter((w) => !navItems.some((n) => n.label === w.label)).map((p) => ({ ...p, group: 'Go to' }))];
  let items = [];
  let active = 0;
  let seq = 0;

  const close = () => { overlay.remove(); document.removeEventListener('keydown', onKey, true); };
  const choose = (it) => { close(); if (it.run) it.run(); else location.href = it.href || it.url; };
  const paint = () => {
    list.innerHTML = '';
    if (!items.length) { list.appendChild(el('div', 'empty', input.value.trim().length < 2 ? 'Type to search' : 'No matches')); return; }
    let group = null;
    items.forEach((it, i) => {
      if (it.group !== group) { group = it.group; list.appendChild(el('div', 'mx-palette-group', group)); }
      const row = el('div', 'mx-palette-row' + (i === active ? ' on' : ''));
      const ico = el('span', 'mx-palette-ico');
      ico.innerHTML = icon(it.icon || GROUP_ICONS[it.group] || 'chevron-right', 14);
      row.appendChild(ico);
      const txt = el('span', 'mx-palette-txt');
      txt.appendChild(el('span', null, it.label || it.title));
      if (it.sub) txt.appendChild(el('span', 'mx-palette-sub', it.sub));
      row.appendChild(txt);
      row.onmouseenter = () => { active = i; [...list.querySelectorAll('.mx-palette-row')].forEach((r, j) => r.classList.toggle('on', j === i)); };
      row.onclick = () => choose(it);
      list.appendChild(row);
    });
    list.querySelector('.on')?.scrollIntoView({ block: 'nearest' });
  };
  const update = async () => {
    const q = input.value.trim().toLowerCase();
    const local = [...actions(), ...pages].filter((p) => !q || p.label.toLowerCase().includes(q));
    items = q ? local.slice(0, 8) : local;
    active = 0;
    paint();
    if (q.length < 2 || !STAFF.includes(session.user.role)) return;
    const mine = ++seq;
    const res = await api('/search?q=' + encodeURIComponent(q)).catch(() => null);
    if (!res || mine !== seq || !document.body.contains(overlay)) return;
    items = [...res.data.map((r) => ({ ...r, label: r.title })), ...local.slice(0, 6)];
    paint();
  };
  let t;
  input.oninput = () => { clearTimeout(t); t = setTimeout(update, 180); };
  const onKey = (e) => {
    if (e.key === 'Escape') { e.preventDefault(); close(); }
    else if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(items.length - 1, active + 1); paint(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(0, active - 1); paint(); }
    else if (e.key === 'Enter' && items[active]) { e.preventDefault(); choose(items[active]); }
  };
  document.addEventListener('keydown', onKey, true);
  overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) close(); });
  update();
}

// ---- Quick task / appointment ---------------------------------------------------------------

const toLocalInput = (d) => { const p = (n) => String(n).padStart(2, '0'); return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`; };
const iso = (local) => (local ? new Date(local).toISOString() : null);

/** quickTask({ title?, related_type?, related_id?, relatedLabel? }) → created task or null. */
export async function quickTask(pre = {}) {
  const due = new Date(); due.setDate(due.getDate() + 1); due.setHours(9, 0, 0, 0);
  const fields = [
    { name: 'title', label: 'Task', value: pre.title || '', required: true },
    { name: 'due_at', label: 'Due', type: 'datetime-local', value: toLocalInput(pre.due || due) },
    { name: 'priority', label: 'Priority', type: 'select', value: 'normal', options: [{ value: 'low', label: 'Low' }, { value: 'normal', label: 'Normal' }, { value: 'high', label: 'High' }] },
    { name: 'notes', label: 'Notes', type: 'textarea', rows: 3 },
  ];
  if (['admin', 'branch_manager', 'hr'].includes(session?.user.role)) {
    const users = await assignees();
    fields.splice(2, 0, { name: 'assigned_to', label: 'Assign to', type: 'select', value: session.user.id, options: users });
  }
  const out = await form({
    title: 'New task', intro: pre.relatedLabel ? 'Linked to ' + pre.relatedLabel : undefined, submitLabel: 'Add task', fields,
    onSubmit: (v) => api('/tasks', { method: 'POST', body: { ...v, due_at: iso(v.due_at), assigned_to: v.assigned_to ? Number(v.assigned_to) : undefined, related_type: pre.related_type, related_id: pre.related_id } }),
  });
  if (out) toast('Task added');
  return out;
}

let assigneeList;
async function assignees() {
  assigneeList ??= api('/staff?per_page=200').then((r) => r.data.filter((s) => s.user_id).map((s) => ({ value: s.user_id, label: s.name }))).catch(() => []);
  const list = await assigneeList;
  return list.some((o) => o.value === session.user.id) ? list : [{ value: session.user.id, label: session.user.name + ' (me)' }, ...list];
}

export const APPOINTMENT_TYPES = [
  { value: 'viewing', label: 'Viewing' }, { value: 'valuation', label: 'Valuation' }, { value: 'meeting', label: 'Meeting' },
  { value: 'signing', label: 'Signing' }, { value: 'inspection', label: 'Inspection' }, { value: 'other', label: 'Other' },
];

/** quickAppointment({ type?, title?, starts?, location?, attendee_name?, attendee_phone?, listing_id?, lead_id?, deal_id?, existing? }) */
export async function quickAppointment(pre = {}) {
  const start = pre.starts || (() => { const d = new Date(); d.setDate(d.getDate() + 1); d.setHours(10, 0, 0, 0); return d; })();
  const ex = pre.existing;
  const fields = [
    { name: 'type', label: 'Type', type: 'select', value: ex?.type || pre.type || 'viewing', options: APPOINTMENT_TYPES },
    { name: 'title', label: 'Title', value: ex?.title || pre.title || '', required: true, hint: 'e.g. Viewing — 14 Rivonia Rd' },
    { name: 'starts_at', label: 'Starts', type: 'datetime-local', value: toLocalInput(ex ? new Date(ex.starts_at) : start), required: true },
    { name: 'minutes', label: 'Duration (minutes)', type: 'number', value: ex ? Math.round((new Date(ex.ends_at) - new Date(ex.starts_at)) / 60000) : 60 },
    { name: 'location', label: 'Location', value: ex?.location || pre.location || '' },
    { name: 'attendee_name', label: 'Client name', value: ex?.attendee_name || pre.attendee_name || '' },
    { name: 'attendee_phone', label: 'Client phone', value: ex?.attendee_phone || pre.attendee_phone || '' },
    { name: 'notes', label: 'Notes', type: 'textarea', rows: 2, value: ex?.notes || '' },
  ];
  if (ex) fields.push({ name: 'status', label: 'Status', type: 'select', value: ex.status, options: [{ value: 'scheduled', label: 'Scheduled' }, { value: 'done', label: 'Done' }, { value: 'no_show', label: 'No-show' }, { value: 'cancelled', label: 'Cancelled' }] });
  const out = await form({
    title: ex ? 'Edit appointment' : 'Book appointment', submitLabel: ex ? 'Save' : 'Book', fields,
    onSubmit: (v) => {
      const startsAt = new Date(v.starts_at);
      const body = { ...v, starts_at: startsAt.toISOString(), ends_at: new Date(startsAt.getTime() + (v.minutes || 60) * 60000).toISOString(),
        listing_id: pre.listing_id, lead_id: pre.lead_id, deal_id: pre.deal_id };
      delete body.minutes;
      return ex ? api('/appointments/' + ex.id, { method: 'PUT', body }) : api('/appointments', { method: 'POST', body });
    },
  });
  if (out) toast(ex ? 'Appointment saved' : 'Appointment booked — it will show in your phone calendar if you subscribed');
  return out;
}

// ---- WhatsApp + email -----------------------------------------------------------------------

/** SA numbers: 082 123 4567 / +27 82… → 2782…; returns '' if there's nothing usable. */
export function waNumber(phone) {
  let d = String(phone || '').replace(/\D/g, '');
  if (d.startsWith('00')) d = d.slice(2);
  if (d.startsWith('0') && d.length === 10) d = '27' + d.slice(1);
  return d.length >= 10 ? d : '';
}
export function waUrl(phone, text) {
  const n = waNumber(phone);
  return n ? `https://wa.me/${n}${text ? '?text=' + encodeURIComponent(text) : ''}` : '';
}

let templates;
const fill = (s, v) => s.replace(/\{(\w+)\}/g, (m, k) => (v[k] != null && v[k] !== '' ? v[k] : m));

/**
 * message({ name, phone, email, property?, link?, related_type?, related_id?, channel? })
 * Lets the agent pick a template, edit it, then opens WhatsApp (click-to-chat) or sends the email from the CRM.
 */
export async function message(to) {
  templates ??= api('/message-templates');
  const t = await run(() => templates);
  if (!t) return;
  const vals = { ...t.defaults, client: (to.name || '').split(' ')[0] || 'there', property: to.property || '', link: to.link || '' };
  const channels = [];
  if (waNumber(to.phone)) channels.push({ value: 'whatsapp', label: 'WhatsApp (' + to.phone + ')' });
  if (to.email) channels.push({ value: 'email', label: 'Email (' + to.email + ')' });
  if (!channels.length) { toast('No phone number or email on record', 'warn'); return; }
  const channel = to.channel && channels.some((c) => c.value === to.channel) ? to.channel : channels[0].value;
  const set = t[channel];
  const keys = Object.keys(set);
  const pick = await form({
    title: 'Message ' + (to.name || ''), submitLabel: 'Next',
    fields: [
      { name: 'channel', label: 'Send by', type: 'select', value: channel, options: channels },
      { name: 'template', label: 'Template', type: 'select', value: keys[0], options: [...new Set([...Object.keys(t.whatsapp), ...Object.keys(t.email)])].map((k) => ({ value: k, label: (t.whatsapp[k] || t.email[k]).label })) },
    ],
    onSubmit: async (v) => v,
  });
  if (!pick) return;
  const tpl = t[pick.channel][pick.template] || Object.values(t[pick.channel])[0];
  if (pick.channel === 'whatsapp') {
    const out = await form({
      title: 'WhatsApp ' + to.name, intro: 'Opens WhatsApp with this message ready — you press send there.', submitLabel: 'Open WhatsApp',
      fields: [{ name: 'body', label: 'Message', type: 'textarea', rows: 6, value: fill(tpl.body, vals), required: true }],
      onSubmit: async (v) => v,
    });
    if (out) window.open(waUrl(to.phone, out.body), '_blank', 'noopener');
    return;
  }
  const out = await form({
    title: 'Email ' + to.name, submitLabel: 'Send email',
    fields: [
      { name: 'to', label: 'To', type: 'email', value: to.email, required: true },
      { name: 'subject', label: 'Subject', value: fill(tpl.subject, vals), required: true },
      { name: 'body', label: 'Message', type: 'textarea', rows: 10, value: fill(tpl.body, vals), required: true },
    ],
    onSubmit: (v) => api('/emails', { method: 'POST', body: { ...v, related_type: to.related_type, related_id: to.related_id } }).then((r) => ({ ...r, to: v.to })),
  });
  if (out) toast('Email sent to ' + out.to);
}

// ---- CSV ------------------------------------------------------------------------------------

/** csvExport('leads.csv', rows, [['Name', r => r.name], …]) — downloads in the browser (Excel-friendly BOM). */
export function csvExport(filename, rows, columns) {
  const q = (v) => { const s = v == null ? '' : String(v); return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s; };
  const text = [columns.map((c) => q(c[0])).join(','), ...rows.map((r) => columns.map((c) => q(c[1](r))).join(','))].join('\r\n');
  const a = el('a');
  a.href = URL.createObjectURL(new Blob(['﻿' + text], { type: 'text/csv;charset=utf-8' }));
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
}

/** csvImport({ endpoint, title, columns }) → server result ({ created, skipped }) or null. */
export async function csvImport({ endpoint, title, columns }) {
  const out = await form({
    title, intro: 'CSV with a header row. Columns: ' + columns + '. Rows that already exist are skipped and listed.', submitLabel: 'Import',
    fields: [{ name: 'file', label: 'CSV file', type: 'file', accept: '.csv,text/csv', required: true }],
    onSubmit: (v) => api(endpoint, { method: 'POST', form: formData(v) }),
  });
  if (!out) return null;
  const skipped = out.skipped || [];
  toast(`Imported ${out.created} row${out.created === 1 ? '' : 's'}` + (skipped.length ? ` · skipped ${skipped.length} (${skipped.slice(0, 3).map((s) => 'row ' + s.row + ': ' + s.reason).join('; ')}${skipped.length > 3 ? '…' : ''})` : ''), skipped.length ? 'warn' : 'ok');
  return out;
}

// ---- My Day (dashboards) ---------------------------------------------------------------------

/** Renders the "My Day" panel into every `.myday-slot` on the page (an empty div the template provides). */
const chipIcon = (name, text) => { const a = el('a', 'chip'); a.innerHTML = icon(name, 14); a.appendChild(document.createTextNode(text)); return a; };

export async function mountMyDay() {
  const slot = document.querySelector('.myday-slot');
  if (!slot) return;
  session ??= await me();
  const d = await api('/my-day').catch(() => null);
  if (!d) return;
  const { due } = await import('./app.js');
  slot.innerHTML = '';
  const card = el('div', 'card myday');
  const head = el('div', 'card-title');
  head.appendChild(el('span', null, d.greeting + ' — here’s your day'));
  const acts = el('span', 'btn-row');
  const b1 = el('button', 'btn btn-sm', '+ Task'); b1.onclick = async () => { if (await quickTask()) mountMyDay(); };
  const b2 = el('button', 'btn btn-sm', '+ Appointment'); b2.onclick = async () => { if (await quickAppointment()) mountMyDay(); };
  acts.append(b1, b2);
  head.appendChild(acts);
  card.appendChild(head);

  const chips = el('div', 'btn-row');
  chips.style.marginBottom = '12px';
  if (d.uploads_to_file) { const a = chipIcon('inbox', d.uploads_to_file + ' client upload' + (d.uploads_to_file === 1 ? '' : 's') + ' to file'); a.href = '/portal/MyUploadLinks.html'; chips.appendChild(a); }
  if (d.approvals) { const a = chipIcon('calendar-check', d.approvals + ' leave request' + (d.approvals === 1 ? '' : 's') + ' to approve'); a.href = session?.user.role === 'branch_manager' ? '/manager/LeaveApprovals.html' : '/hr/Leave.html'; chips.appendChild(a); }
  if (chips.children.length) card.appendChild(chips);

  const grid = el('div', 'grid grid-3');
  const col = (title, href, items, empty, render) => {
    const c = el('div');
    const t = el('div', 'kpi-label');
    const link = el('a', null, title + ' (' + items.length + ')'); link.href = href; link.style.color = 'inherit';
    t.appendChild(link);
    c.appendChild(t);
    if (!items.length) c.appendChild(el('div', 'muted small', empty));
    items.forEach((it) => c.appendChild(render(it)));
    return c;
  };
  grid.appendChild(col('Appointments', '/work/Calendar.html', d.appointments, 'Nothing booked today or tomorrow.', (a) => {
    const r = el('div', 'list-row');
    const g = el('div', 'grow');
    g.appendChild(el('div', 'strong small', a.title));
    g.appendChild(el('div', 'muted small', due(a.starts_at) + (a.location ? ' · ' + a.location : '')));
    r.appendChild(g);
    if (a.phone && waNumber(a.phone)) { const w = el('a', 'wa-btn', 'WhatsApp'); w.href = waUrl(a.phone, 'Hi ' + (a.attendee || '').split(' ')[0] + ', confirming our ' + a.type + ' ' + due(a.starts_at) + '. — ' + session.user.name); w.target = '_blank'; w.rel = 'noopener'; r.appendChild(w); }
    return r;
  }));
  grid.appendChild(col('Tasks due', '/work/Tasks.html', d.tasks, 'No tasks due — nice.', (t) => {
    const r = el('div', 'list-row');
    const chk = el('span', 'check');
    chk.onclick = async () => { await api('/tasks/' + t.id + '/toggle', { method: 'POST' }).catch(() => {}); mountMyDay(); };
    const g = el('div', 'grow');
    g.appendChild(el('div', 'strong small', t.title));
    const m = el('div', 'small ' + (t.overdue ? 'neg' : 'muted'), t.due_at ? (t.overdue ? 'Overdue · ' : '') + due(t.due_at) : 'No due date');
    g.appendChild(m);
    r.append(chk, g);
    return r;
  }));
  const leadsHref = session?.user.role === 'agent' ? '/portal/MyLeads.html' : '/sales/Leads.html';
  grid.appendChild(col('Leads to follow up', leadsHref + '?sort=score', d.follow_ups, 'Every lead has been contacted this week.', (l) => {
    const r = el('div', 'list-row');
    const sc = el('span', 'score ' + (l.score >= 70 ? 'hot' : l.score >= 40 ? 'warm' : 'cold'), String(l.score ?? 0));
    const g = el('div', 'grow');
    g.appendChild(el('div', 'strong small', l.name));
    g.appendChild(el('div', 'muted small', (l.interest || 'No brief yet') + ' · ' + (l.last_contacted_at ? 'last contact ' + ago(l.last_contacted_at).toLowerCase() : 'never contacted')));
    r.append(sc, g);
    if (waNumber(l.phone)) {
      const w = el('a', 'wa-btn', 'WhatsApp');
      w.href = waUrl(l.phone, 'Hi ' + l.name.split(' ')[0] + ', ' + session.user.name.split(' ')[0] + ' from Morena Properties here — are you still looking?');
      w.target = '_blank'; w.rel = 'noopener';
      w.onclick = () => api('/leads/' + l.id, { method: 'PUT', body: { contacted: true } }).catch(() => {});
      r.appendChild(w);
    }
    return r;
  }));
  card.appendChild(grid);
  slot.appendChild(card);
}

// ---- QR codes (drawn in the browser — 2FA secrets never leave the app) -------------------------

let qrLib;
/** qrSvg('otpauth://…', 5) → '<svg …>' string. */
export async function qrSvg(text, cell = 4) {
  qrLib ??= new Promise((resolve, reject) => {
    if (window.qrcode) return resolve(window.qrcode);
    const s = document.createElement('script');
    s.src = new URL('./vendor/qrcode.js', import.meta.url).href;
    s.onload = () => resolve(window.qrcode);
    s.onerror = reject;
    document.head.appendChild(s);
  });
  const qr = (await qrLib)(0, 'M');
  qr.addData(text);
  qr.make();
  return qr.createSvgTag({ cellSize: cell, margin: 2, scalable: true });
}
/** qrDataUrl(text) → 'data:image/svg+xml,…' for an <img src> binding in page templates. */
export async function qrDataUrl(text, cell = 4) {
  return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(await qrSvg(text, cell));
}

export { date, when, ago };
