// Shared runtime for every page: API client (Sanctum session + CSRF), page shell (auth, nav, user),
// display formatters, status badge tones, and a small modal form used by the "+ New …" buttons.
// Pages load it with:  const app = await import('../assets/app.js');

const API = '/api/v1';

// ---- API ------------------------------------------------------------------------------------

export class ApiError extends Error {
  constructor(status, message, errors) {
    super(message);
    this.status = status;
    this.errors = errors || {};
  }
}

function cookie(name) {
  const m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
  return m ? decodeURIComponent(m[1]) : null;
}

export async function csrf() {
  await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' });
}

/**
 * api('/deals')                         GET → parsed JSON
 * api('/leads', { method: 'POST', body: {...} })
 * api('/documents', { method: 'POST', form: formData })
 */
export async function api(path, { method = 'GET', body, form, retry = true, redirectOn401 = true } = {}) {
  if (method !== 'GET' && !cookie('XSRF-TOKEN')) await csrf();
  const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
  const xsrf = cookie('XSRF-TOKEN');
  if (xsrf) headers['X-XSRF-TOKEN'] = xsrf;
  let payload;
  if (form) payload = form;
  else if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
    payload = JSON.stringify(body);
  }

  const res = await fetch(API + path, { method, headers, body: payload, credentials: 'same-origin' });
  if (res.status === 419 && retry) {
    await csrf();
    return api(path, { method, body, form, retry: false, redirectOn401 });
  }
  if (res.status === 401 && redirectOn401) {
    location.href = '/auth/Login.html?next=' + encodeURIComponent(location.pathname + location.search);
    return new Promise(() => {}); // page is navigating to login — never settle
  }
  if (res.status === 204) return null;
  const data = await res.json().catch(() => null);
  if (!res.ok) {
    const first = data && data.errors ? Object.values(data.errors)[0] : null;
    throw new ApiError(res.status, (first && first[0]) || (data && data.message) || 'Something went wrong', data && data.errors);
  }
  return data;
}

/** Wraps an async action: shows success/failure toast, returns the result (or undefined on error). */
export async function run(action, successMessage) {
  try {
    const out = await action();
    if (successMessage) toast(successMessage, 'ok');
    return out;
  } catch (e) {
    if (e.status !== 401) toast(e.message, 'danger');
  }
}

// ---- Shell ----------------------------------------------------------------------------------

let mePromise;
export function me() {
  return (mePromise ??= api('/auth/me'));
}

/**
 * Every page's componentDidMount starts with this. Returns the values the shared sidebar template
 * binds to. `roles` (Spatie role names) guards the page — anyone else is sent to their own home page.
 */
export async function shell(activeKey, roles) {
  const [session, nav] = await Promise.all([me(), import('../shell/nav-config.js')]);
  const u = session.user;
  if (roles && !roles.includes(u.role)) {
    location.replace(session.home);
    return new Promise(() => {}); // never resolves; page is navigating away
  }
  applyLogoWidth(session.settings.sidebar_logo_width);
  return {
    me: u,
    navSections: nav.decorateNav(u.nav_role, activeKey),
    roleLabel: nav.roleLabels[u.nav_role],
    userName: u.name,
    userInitials: u.initials,
    onSignOut: signOut,
  };
}

/** The sidebar/topbar values every page's renderVals() passes through from state. */
export function shellVals(s) {
  return {
    navSections: s.navSections || [], roleLabel: s.roleLabel || '', userName: s.userName || '',
    userInitials: s.userInitials || '', onSignOut: s.onSignOut || signOut,
  };
}

export async function signOut(e) {
  if (e && e.preventDefault) e.preventDefault();
  await api('/auth/logout', { method: 'POST' }).catch(() => {});
  location.href = '/auth/Login.html';
}

export function applyLogoWidth(w) {
  window.__morenaLogoWidth = w;
  if (window.applyMorenaLogo) window.applyMorenaLogo();
}

export function param(name) {
  return new URLSearchParams(location.search).get(name);
}

// ---- Formatting -----------------------------------------------------------------------------

/** 2450000 → "2,450,000" (pages put the "R" in the template). */
export function money(n) {
  return n == null ? '—' : Math.round(Number(n)).toLocaleString('en-US');
}

/** 48200000 → "R 48.2m", 1360900 → "R 1.36m", 894200 → "R 894k". */
export function randShort(n, space = true) {
  const v = Number(n) || 0;
  const s = space ? 'R ' : 'R';
  if (Math.abs(v) >= 1e7) return s + trim((v / 1e6).toFixed(1)) + 'm';
  if (Math.abs(v) >= 1e6) return s + trim((v / 1e6).toFixed(2)) + 'm';
  if (Math.abs(v) >= 1e3) return s + Math.round(v / 1e3) + 'k';
  return s + Math.round(v);
}

/** Number without "R": 21400000 → "21.4m". */
export function short(n) {
  return randShort(n, false).slice(1);
}

function trim(s) {
  return s.replace(/\.?0+$/, '');
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sept', 'Oct', 'Nov', 'Dec'];

function toDate(v) {
  if (!v) return null;
  // "YYYY-MM-DD" is a calendar date — don't let the timezone shift it.
  return /^\d{4}-\d{2}-\d{2}$/.test(v) ? new Date(v + 'T00:00:00') : new Date(v);
}

/** "30 Oct 2026" */
export function date(v, fallback = '—') {
  const d = toDate(v);
  return d ? d.getDate() + ' ' + MONTHS[d.getMonth()] + ' ' + d.getFullYear() : fallback;
}

/** "20–24 Oct 2026" / "9 Oct 2026" / "28 Sept – 2 Oct 2026" */
export function dateRange(a, b) {
  const x = toDate(a), y = toDate(b);
  if (!y || x.getTime() === y.getTime()) return date(a);
  if (x.getMonth() === y.getMonth() && x.getFullYear() === y.getFullYear()) return x.getDate() + '–' + date(b);
  return x.getDate() + ' ' + MONTHS[x.getMonth()] + ' – ' + date(b);
}

function daysAgo(d) {
  const start = (t) => new Date(t.getFullYear(), t.getMonth(), t.getDate());
  return Math.round((start(new Date()) - start(d)) / 86400000);
}

/** "Today", "Yesterday", "4 days ago", "2 weeks ago" */
export function ago(v) {
  const d = toDate(v);
  if (!d) return '—';
  const n = daysAgo(d);
  if (n <= 0) return 'Today';
  if (n === 1) return 'Yesterday';
  if (n < 7) return n + ' days ago';
  if (n < 30) { const w = Math.floor(n / 7); return w + (w === 1 ? ' week ago' : ' weeks ago'); }
  return date(v);
}

/** "Today, 09:40" / "Yesterday, 16:12" / "3 days ago" */
export function when(v) {
  const d = toDate(v);
  if (!d) return '—';
  const n = daysAgo(d);
  const hm = String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
  if (n <= 0) return 'Today, ' + hm;
  if (n === 1) return 'Yesterday, ' + hm;
  return ago(v);
}

// ---- Badges & tones -------------------------------------------------------------------------

export const TONES = {
  ok: ['#eaf3ee', '#2f7d5a'],
  warn: ['#fbf3e3', '#c07c1e'],
  danger: ['#f5e9e9', '#b42318'],
  info: ['#eef0fb', '#3b4cca'],
  brand: ['#f1ede9', '#7a1528'],
  muted: ['#f1ede9', '#6b6660'],
};
export const TONE_TEXT = { up: '#2f7d5a', down: '#b42318', neutral: '#6b6660', warn: '#c07c1e', ok: '#2f7d5a', danger: '#b42318', brand: '#7a1528' };

export function badge(tone, extra = {}) {
  const [bg, color] = TONES[tone] || TONES.muted;
  return { fontSize: 11.5, fontWeight: 700, padding: '3px 10px', borderRadius: 999, background: bg, color, ...extra };
}

/** Badge for a pipeline stage, derived from the stage's admin-chosen colour. */
export function stageBadge(hex, extra = {}) {
  if (!hex) return badge('muted', extra);
  const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
  const light = (0.299 * r + 0.587 * g + 0.114 * b) > 170;
  if (light) return badge('muted', extra);
  const mix = (c) => Math.round(c * 0.1 + 255 * 0.9);
  return { fontSize: 11.5, fontWeight: 700, padding: '3px 10px', borderRadius: 999, background: `rgb(${mix(r)},${mix(g)},${mix(b)})`, color: hex, ...extra };
}

/** [label, tone] per status key, per entity. */
export const STATUS = {
  lead: { new: ['New', 'info'], contacted: ['Contacted', 'warn'], qualified: ['Qualified', 'ok'], converted: ['Converted', 'brand'], lost: ['Lost', 'danger'] },
  listing: { active: ['Active', 'ok'], under_offer: ['Under Offer', 'brand'], sold: ['Sold', 'muted'] },
  commission: { paid: ['Paid', 'ok'], pending: ['Pending', 'warn'] },
  contract: { active: ['Active', 'ok'], renewal_due: ['Renewal due', 'warn'], probation: ['Probation', 'info'], terminated: ['Terminated', 'danger'] },
  staff: { active: ['Active', 'ok'], on_leave: ['On Leave', 'warn'], terminated: ['Terminated', 'danger'] },
  link: { uploaded: ['Uploaded — needs filing', 'warn'], filed: ['Filed', 'ok'], awaiting_client: ['Awaiting client', 'muted'], expired: ['Expired', 'danger'] },
  user: { active: ['Active', 'ok'], invited: ['Invited', 'warn'], suspended: ['Suspended', 'danger'] },
  cert: { compliant: ['Compliant', 'ok'], behind: ['CPD behind', 'warn'], pending: ['Registration pending', 'danger'] },
  course: { complete: ['Complete', 'ok'], in_progress: ['In progress', 'warn'], not_started: ['Not started', 'muted'] },
  item: { required: ['Required', 'warn'], uploaded: ['Uploaded', 'ok'], filed: ['Filed', 'ok'] },
};

/** status('lead', 'new') → { label: 'New', style: {...badge} } */
export function status(kind, key, extra) {
  const [label, tone] = (STATUS[kind] && STATUS[kind][key]) || [key, 'muted'];
  return { label, style: badge(tone, extra), tone };
}

export const ROLE_LABELS = {
  admin: 'Administrator', branch_manager: 'Branch Manager', hr: 'HR Staff', finance: 'Finance', agent: 'Sales Agent',
  landlord: 'Landlord (portal)', tenant: 'Tenant (portal)',
};

export const bar = (pct, color = '#7a1528', extra = {}) => ({ width: Math.max(0, Math.min(100, pct)) + '%', height: '100%', borderRadius: 4, background: color, ...extra });

// ---- Toast ----------------------------------------------------------------------------------

export function toast(message, tone = 'ok') {
  const [bg, color] = TONES[tone] || TONES.ok;
  const el = document.createElement('div');
  el.textContent = message;
  Object.assign(el.style, {
    position: 'fixed', right: '24px', bottom: '24px', zIndex: 10001, maxWidth: '380px',
    padding: '12px 16px', borderRadius: '10px', background: bg, color, border: '1px solid ' + color + '33',
    font: "600 13px 'Plus Jakarta Sans', sans-serif", boxShadow: '0 8px 24px rgba(28,26,25,0.12)',
  });
  document.body.appendChild(el);
  setTimeout(() => el.remove(), tone === 'danger' ? 6000 : 3200);
}

// ---- Modal form -----------------------------------------------------------------------------

/**
 * form({ title, intro?, submitLabel?, fields: [{ name, label, type?, options?, value?, required?, hint?, rows? }],
 *        onSubmit: async (values) => {...} })
 * type: text | number | email | date | password | textarea | select | file | checkbox | lines (textarea → string[])
 * options: [{ value, label }] for select. Resolves with onSubmit's result, or null if cancelled.
 * Server validation errors (ApiError.errors) are shown beneath the matching field.
 */
export function form({ title, intro, fields, submitLabel = 'Save', onSubmit }) {
  return new Promise((resolve) => {
    const css = {
      label: 'display:block;font-size:12px;font-weight:600;color:#6b6660;margin-bottom:5px',
      input: 'width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #e8e3e0;border-radius:8px;font-family:inherit;font-size:13.5px;background:#fff;color:#1c1a19',
    };
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;z-index:10000;background:rgba(28,26,25,0.45);display:flex;align-items:flex-start;justify-content:center;padding:6vh 16px;overflow-y:auto;font-family:"Plus Jakarta Sans",sans-serif';
    const box = document.createElement('form');
    box.noValidate = true;
    box.style.cssText = 'width:100%;max-width:480px;background:#fff;border-radius:14px;padding:24px;box-shadow:0 20px 50px rgba(28,26,25,0.25);color:#1c1a19';
    box.innerHTML = `<div style="font-size:17px;font-weight:700;margin-bottom:${intro ? 6 : 18}px"></div>` +
      (intro ? '<div style="font-size:13px;color:#6b6660;margin-bottom:18px"></div>' : '');
    box.firstChild.textContent = title;
    if (intro) box.children[1].textContent = intro;

    const errEls = {};
    const inputs = {};
    for (const f of fields) {
      const wrap = document.createElement('div');
      wrap.style.marginBottom = '14px';
      const type = f.type || 'text';
      let input;
      if (type === 'select') {
        input = document.createElement('select');
        for (const o of f.options || []) {
          const opt = document.createElement('option');
          opt.value = o.value ?? '';
          opt.textContent = o.label;
          if (String(o.value) === String(f.value ?? '')) opt.selected = true;
          input.appendChild(opt);
        }
      } else if (type === 'textarea' || type === 'lines') {
        input = document.createElement('textarea');
        input.rows = f.rows || 4;
        input.value = Array.isArray(f.value) ? f.value.join('\n') : (f.value ?? '');
      } else {
        input = document.createElement('input');
        input.type = type;
        if (type === 'checkbox') input.checked = !!f.value;
        else if (type !== 'file') input.value = f.value ?? '';
        if (f.accept) input.accept = f.accept;
        if (f.step) input.step = f.step;
      }
      input.name = f.name;
      if (type === 'checkbox') {
        const l = document.createElement('label');
        l.style.cssText = 'display:flex;align-items:center;gap:8px;font-size:13.5px';
        l.appendChild(input);
        l.appendChild(document.createTextNode(f.label));
        wrap.appendChild(l);
      } else {
        const l = document.createElement('label');
        l.style.cssText = css.label;
        l.textContent = f.label + (f.required ? ' *' : '');
        input.style.cssText = css.input;
        wrap.appendChild(l);
        wrap.appendChild(input);
      }
      if (f.hint) {
        const h = document.createElement('div');
        h.style.cssText = 'font-size:11.5px;color:#6b6660;margin-top:4px';
        h.textContent = f.hint;
        wrap.appendChild(h);
      }
      const err = document.createElement('div');
      err.style.cssText = 'font-size:12px;color:#b42318;margin-top:4px;display:none';
      wrap.appendChild(err);
      errEls[f.name] = err;
      inputs[f.name] = { el: input, f };
      box.appendChild(wrap);
    }

    const general = document.createElement('div');
    general.style.cssText = 'font-size:12.5px;color:#b42318;margin:-4px 0 12px;display:none';
    box.appendChild(general);
    const actions = document.createElement('div');
    actions.style.cssText = 'display:flex;gap:10px;justify-content:flex-end;margin-top:6px';
    actions.innerHTML =
      '<button type="button" style="padding:10px 18px;border:1px solid #e8e3e0;border-radius:10px;background:#fff;color:#1c1a19;font-weight:600;font-size:13.5px;font-family:inherit;cursor:pointer">Cancel</button>' +
      '<button type="submit" style="padding:10px 18px;border:none;border-radius:10px;background:#7a1528;color:#fff;font-weight:700;font-size:13.5px;font-family:inherit;cursor:pointer"></button>';
    actions.lastChild.textContent = submitLabel;
    box.appendChild(actions);
    overlay.appendChild(box);
    document.body.appendChild(overlay);
    const first = box.querySelector('input,select,textarea');
    if (first) first.focus();

    const close = (v) => { overlay.remove(); document.removeEventListener('keydown', onKey); resolve(v); };
    const onKey = (e) => { if (e.key === 'Escape') close(null); };
    document.addEventListener('keydown', onKey);
    actions.firstChild.onclick = () => close(null);
    overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) close(null); });

    box.onsubmit = async (e) => {
      e.preventDefault();
      Object.values(errEls).forEach((el) => { el.style.display = 'none'; });
      general.style.display = 'none';
      const values = {};
      let missing = false;
      for (const [name, { el, f }] of Object.entries(inputs)) {
        const t = f.type || 'text';
        let v = t === 'checkbox' ? el.checked : t === 'file' ? (el.files[0] || null)
          : t === 'lines' ? el.value.split('\n').map((s) => s.trim()).filter(Boolean)
          : t === 'number' ? (el.value === '' ? null : Number(el.value)) : el.value.trim();
        if (v === '' ) v = null;
        if (f.required && (v === null || (Array.isArray(v) && !v.length))) {
          errEls[name].textContent = 'Required';
          errEls[name].style.display = 'block';
          missing = true;
        }
        values[name] = v;
      }
      if (missing) return;
      const submit = actions.lastChild;
      submit.disabled = true;
      submit.style.opacity = '0.6';
      try {
        close(await onSubmit(values));
      } catch (err) {
        submit.disabled = false;
        submit.style.opacity = '1';
        let placed = false;
        for (const [k, msgs] of Object.entries(err.errors || {})) {
          const key = k.split('.')[0];
          if (errEls[key]) { errEls[key].textContent = msgs[0]; errEls[key].style.display = 'block'; placed = true; }
        }
        if (!placed) { general.textContent = err.message || 'Something went wrong'; general.style.display = 'block'; }
      }
    };
  });
}

/** Build FormData from a values object, skipping nulls (files are appended as-is). */
export function formData(values) {
  const fd = new FormData();
  for (const [k, v] of Object.entries(values)) {
    if (v === null || v === undefined) continue;
    if (typeof v === 'boolean') fd.append(k, v ? '1' : '0');
    else fd.append(k, v);
  }
  return fd;
}

/** Cached option lists for form selects. */
const lookups = {};
export function options(kind) {
  const load = {
    agents: async () => (await api('/staff?department=sales&per_page=200')).data.map((s) => ({ value: s.id, label: s.name })),
    staff: async () => (await api('/staff?per_page=200')).data.map((s) => ({ value: s.id, label: s.name })),
    branches: async () => (await api('/branches')).data.map((b) => ({ value: b.id, label: b.name })),
    stages: async () => (await api('/pipeline-stages')).data.map((s) => ({ value: s.id, label: s.name })),
    categories: async () => (await api('/document-categories')).data.map((c) => ({ value: c.id, label: c.name })),
  }[kind];
  return (lookups[kind] ??= load());
}

export const LEAD_SOURCES = [
  { value: 'website', label: 'Website' }, { value: 'referral', label: 'Referral' }, { value: 'walk_in', label: 'Walk-in' },
  { value: 'whatsapp', label: 'WhatsApp' }, { value: 'portal', label: 'Portals (Property24/P24)' },
];
