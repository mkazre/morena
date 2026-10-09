// Export any page's data as CSV, Excel (.xlsx) or PDF.
// Data comes from the page's tables (header row + body rows; action-only columns such as "Edit · Delete" are dropped),
// or from window.__morenaExport() → [{ title, columns: [..], rows: [[..], ..] }] on pages without a table (cards, boards).
// Excel is a real Office Open XML workbook (one sheet per table, styled header, numbers as numbers) built here;
// PDF uses jsPDF + AutoTable (vendored, MIT) with the company logo, title, date and page numbers.

const VENDOR = new URL('./vendor/', import.meta.url).href;
const loaded = {};
function script(file) {
  return (loaded[file] ??= new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = VENDOR + file;
    s.onload = resolve;
    s.onerror = () => reject(new Error('Could not load ' + file));
    document.head.appendChild(s);
  }));
}

const clean = (t) => String(t ?? '').replace(/\s+/g, ' ').trim();

/** A cell's text without its buttons/action links; stacked lines (name / phone) joined with " · ". */
function cellText(td) {
  const c = td.cloneNode(true);
  c.querySelectorAll('button, input, select, textarea, .check, .wa-btn, a[href="#"], a:not([href]), .i').forEach((x) => x.remove());
  // A detached clone has no layout (innerText would be flat), so mark block boundaries ourselves.
  let text = '';
  const walk = (n) => {
    for (const ch of n.childNodes) {
      if (ch.nodeType === 3) text += ch.textContent;
      else if (ch.nodeType === 1) {
        const block = /^(DIV|P|BR|LI|UL|OL)$/.test(ch.tagName);
        if (block) text += '\n';
        walk(ch);
        if (block) text += '\n';
      }
    }
  };
  walk(c);
  return text.split('\n').map((l) => clean(l).replace(/^[·\s]+|[·\s]+$/g, '')).filter(Boolean).join(' · ');
}

/** jsPDF's built-in fonts only cover Windows-1252, so map the few other symbols we use. */
const pdfSafe = (s) => String(s ?? '').replace(/→/g, '->').replace(/←/g, '<-').replace(/[✓✔]/g, 'Yes').replace(/[✕✗]/g, 'x')
  .replace(/≥/g, '>=').replace(/≤/g, '<=').replace(/[^\x00-\xFF–—‘’“”•…€]/g, '');
const ACTION_WORDS = /^(edit|delete|remove|view|open|void|journal|convert →|\+ task|message|whatsapp|set target|pause|resume|·|\s)+$/i;

/** Datasets on the current page. */
export function collect() {
  if (typeof window.__morenaExport === 'function') {
    const custom = (window.__morenaExport() || []).filter((d) => d && d.rows && d.rows.length);
    if (custom.length) return custom;
  }
  const main = document.querySelector('main') || document.body;
  const out = [];
  main.querySelectorAll('table').forEach((table, n) => {
    if (table.closest('.mx-panel, .mx-palette, form')) return;
    const head = [...(table.tHead ? table.tHead.rows[0]?.cells || [] : [])].map((c) => clean(c.innerText));
    const body = [...(table.tBodies[0]?.rows || [])].filter((r) => r.cells.length > 1 && !r.querySelector('.empty'))
      .map((r) => [...r.cells].map(cellText));
    if (!body.length) return;
    const width = Math.max(head.length, ...body.map((r) => r.length));
    // Drop columns that hold only buttons/links ("Edit · Delete", "WhatsApp Message · + Task") or nothing at all.
    const keep = [...Array(width).keys()].filter((i) => !body.every((r) => !r[i] || ACTION_WORDS.test(r[i])));
    const card = table.closest('.card, [style*="border-radius: 14px"]');
    const label = card && card.querySelector('.card-title, [style*="font-weight: 700"]');
    const title = clean(label && !label.contains(table) ? label.innerText : '') || (n === 0 ? pageTitle() : pageTitle() + ' (' + (n + 1) + ')');
    out.push({ title: title.slice(0, 80), columns: keep.map((i) => head[i] || ''), rows: body.map((r) => keep.map((i) => r[i] ?? '')) });
  });
  return out;
}

// ---- Helpers for pages without tables (cards, boards, record pages) ---------------------------

const SKIP_KEY = /(^id$|_id$|^ids$|url$|href|token|style|color|colour|hex|photo|path|password|secret|initials|avatar|^can_|^on[A-Z])/i;
const human = (k) => k.replace(/_/g, ' ').replace(/([a-z])([A-Z])/g, '$1 $2').replace(/^\w/, (c) => c.toUpperCase());
function flat(v) {
  if (v === null || v === undefined) return '';
  if (typeof v === 'boolean') return v ? 'Yes' : 'No';
  if (typeof v === 'number') return String(v);
  if (typeof v === 'string') return /^\d{4}-\d{2}-\d{2}T/.test(v) ? v.slice(0, 16).replace('T', ' ') : v;
  if (Array.isArray(v)) return v.every((x) => typeof x !== 'object' || x === null) ? v.join(', ') : String(v.length);
  if (typeof v === 'object') return flat(v.name ?? v.label ?? v.title ?? '');
  return '';
}

/** Any list of API records → one dataset (columns from the records' own fields, internal ones skipped). */
export function records(title, list, { omit = [], only = null } = {}) {
  const rows = (list || []).filter(Boolean);
  const keys = only || [...new Set(rows.flatMap((r) => Object.keys(r)))]
    .filter((k) => !SKIP_KEY.test(k) && !omit.includes(k) && rows.some((r) => typeof r[k] !== 'function' && flat(r[k]) !== ''));
  return { title, columns: keys.map(human), rows: rows.map((r) => keys.map((k) => flat(r[k]))) };
}

/** One record → Field / Value rows (nested objects are listed with a prefix, lists are left for records()). */
export function keyValue(title, obj, prefix = '') {
  const rows = [];
  for (const [k, v] of Object.entries(obj || {})) {
    if (SKIP_KEY.test(k) || typeof v === 'function') continue;
    if (v && typeof v === 'object' && !Array.isArray(v)) rows.push(...keyValue('', v, prefix + human(k) + ' — ').rows);
    else if (!Array.isArray(v) || v.every((x) => typeof x !== 'object')) { const f = flat(v); if (f !== '') rows.push([prefix + human(k), f]); }
  }
  return { title, columns: ['Field', 'Value'], rows };
}

/** A record page: its own fields, then one table per list of records inside it (activity, documents…). */
export function recordSets(title, obj, extra = {}) {
  if (!obj) return [];
  const sets = [keyValue(title, obj)];
  for (const [k, v] of Object.entries({ ...obj, ...extra })) {
    if (Array.isArray(v) && v.some((x) => x && typeof x === 'object')) sets.push(records(human(k), v));
  }
  return sets.filter((d) => d.rows.length);
}

export function pageTitle() {
  const h1 = document.querySelector('main h1');
  const crumb = document.querySelector('header > div');
  return clean(h1 ? h1.innerText : crumb ? crumb.innerText.split('/').pop() : document.title) || 'Export';
}

const stamp = () => { const d = new Date(); const p = (n) => String(n).padStart(2, '0'); return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`; };
const fileBase = (title) => (title || 'export').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 60) + '-' + stamp();
function download(blob, name) {
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = name;
  document.body.appendChild(a);
  a.click();
  setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
}

/** "R 1,234.50" / "-1,234" / "(1,234.00)" / "48" → number; anything else → null (dates, phones, codes stay text). */
export function asNumber(v) {
  const s = String(v).trim();
  if (!/^\(?-?(R\s?)?-?\d{1,3}(,\d{3})*(\.\d+)?\)?$|^\(?-?(R\s?)?-?\d+(\.\d+)?\)?$/.test(s)) return null;
  if (/^0\d/.test(s.replace(/^R\s?/, ''))) return null; // leading zero = phone/code
  const neg = s.startsWith('(') || s.replace(/^R\s?/, '').startsWith('-');
  const n = parseFloat(s.replace(/[R\s,()]/g, '').replace(/^-/, ''));
  return isNaN(n) ? null : (neg ? -n : n);
}

// ---- CSV ------------------------------------------------------------------------------------

export function toCsv(sets, title) {
  const q = (v) => { const s = String(v ?? ''); return /[",\n;]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s; };
  const parts = sets.map((d) => (sets.length > 1 ? q(d.title) + '\r\n' : '') + [d.columns, ...d.rows].map((r) => r.map(q).join(',')).join('\r\n'));
  download(new Blob(['﻿' + parts.join('\r\n\r\n')], { type: 'text/csv;charset=utf-8' }), fileBase(title) + '.csv');
}

// ---- Excel (.xlsx) --------------------------------------------------------------------------

const xmlEsc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/[\u0000-\u0008\u000b\u000c\u000e-\u001f]/g, '');
const colName = (i) => { let s = ''; i++; while (i) { const m = (i - 1) % 26; s = String.fromCharCode(65 + m) + s; i = Math.floor((i - 1) / 26); } return s; };

function sheetXml(d, meta) {
  const width = Math.max(1, d.columns.length);
  const numeric = d.columns.map((_, i) => d.rows.length > 0 && d.rows.every((r) => !r[i] || asNumber(r[i]) !== null) && d.rows.some((r) => r[i]));
  const widths = d.columns.map((c, i) => Math.min(60, Math.max(10, String(c).length + 2, ...d.rows.map((r) => String(r[i] ?? '').length + 2))));
  const cell = (ref, v, style, num) => (num !== null && num !== undefined
    ? `<c r="${ref}" s="${style}"><v>${num}</v></c>`
    : `<c r="${ref}" t="inlineStr" s="${style}"><is><t xml:space="preserve">${xmlEsc(v)}</t></is></c>`);
  const rows = [];
  rows.push(`<row r="1" ht="22" customHeight="1">${cell('A1', d.title, 3)}</row>`);
  rows.push(`<row r="2">${cell('A2', meta, 4)}</row>`);
  rows.push(`<row r="4" ht="20" customHeight="1">${d.columns.map((c, i) => cell(colName(i) + 4, c, numeric[i] ? 5 : 1)).join('')}</row>`);
  d.rows.forEach((r, ri) => {
    const n = ri + 5;
    rows.push(`<row r="${n}">${d.columns.map((_, i) => {
      const num = numeric[i] ? asNumber(r[i]) : null;
      return cell(colName(i) + n, r[i] ?? '', numeric[i] ? 2 : 0, num);
    }).join('')}</row>`);
  });
  const last = colName(width - 1) + (d.rows.length + 4);
  return `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<sheetViews><sheetView workbookViewId="0"><pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>
<cols>${widths.map((w, i) => `<col min="${i + 1}" max="${i + 1}" width="${w}" customWidth="1"/>`).join('')}</cols>
<sheetData>${rows.join('')}</sheetData>
<autoFilter ref="A4:${last}"/>
<pageSetup orientation="${width > 6 ? 'landscape' : 'portrait'}" fitToWidth="1" fitToHeight="0"/>
</worksheet>`;
}

const STYLES = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00;[Red]-#,##0.00"/></numFmts>
<fonts count="4"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="14"/><color rgb="FF7A1528"/><name val="Calibri"/></font><font><i/><sz val="10"/><color rgb="FF6B6660"/><name val="Calibri"/></font></fonts>
<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF7A1528"/><bgColor indexed="64"/></patternFill></fill></fills>
<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left/><right/><top/><bottom style="thin"><color rgb="FFE8E3E0"/></bottom><diagonal/></border></borders>
<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
<cellXfs count="6">
<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>
<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"><alignment vertical="center"/></xf>
<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>
<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>
<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>
<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"><alignment horizontal="right" vertical="center"/></xf>
</cellXfs>
</styleSheet>`;

export function toXlsx(sets, title, meta) {
  const used = new Set();
  const names = sets.map((d, i) => {
    let n = (d.title || 'Sheet ' + (i + 1)).replace(/[\[\]:*?/\\]/g, ' ').slice(0, 31).trim() || 'Sheet ' + (i + 1);
    while (used.has(n.toLowerCase())) n = n.slice(0, 28) + ' ' + (i + 1);
    used.add(n.toLowerCase());
    return n;
  });
  const files = {
    '[Content_Types].xml': `<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>${sets.map((_, i) => `<Override PartName="/xl/worksheets/sheet${i + 1}.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>`).join('')}</Types>`,
    '_rels/.rels': `<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>`,
    'xl/workbook.xml': `<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>${names.map((n, i) => `<sheet name="${xmlEsc(n)}" sheetId="${i + 1}" r:id="rId${i + 1}"/>`).join('')}</sheets></workbook>`,
    'xl/_rels/workbook.xml.rels': `<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">${sets.map((_, i) => `<Relationship Id="rId${i + 1}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet${i + 1}.xml"/>`).join('')}<Relationship Id="rId${sets.length + 1}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>`,
    'xl/styles.xml': STYLES,
  };
  sets.forEach((d, i) => { files[`xl/worksheets/sheet${i + 1}.xml`] = sheetXml(d, meta); });
  download(new Blob([zip(files)], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }), fileBase(title) + '.xlsx');
}

// Minimal ZIP writer (stored entries) — enough for an .xlsx package.
const CRC = (() => { const t = new Uint32Array(256); for (let n = 0; n < 256; n++) { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; t[n] = c >>> 0; } return t; })();
const crc32 = (b) => { let c = 0xffffffff; for (let i = 0; i < b.length; i++) c = CRC[(c ^ b[i]) & 255] ^ (c >>> 8); return (c ^ 0xffffffff) >>> 0; };
function zip(files) {
  const enc = new TextEncoder();
  const chunks = [];
  const central = [];
  let offset = 0;
  for (const [name, text] of Object.entries(files)) {
    const data = enc.encode(text);
    const nm = enc.encode(name);
    const crc = crc32(data);
    const h = new DataView(new ArrayBuffer(30));
    h.setUint32(0, 0x04034b50, true); h.setUint16(4, 20, true); h.setUint16(6, 0x0800, true); h.setUint16(8, 0, true);
    h.setUint32(14, crc, true); h.setUint32(18, data.length, true); h.setUint32(22, data.length, true); h.setUint16(26, nm.length, true);
    chunks.push(new Uint8Array(h.buffer), nm, data);
    const c = new DataView(new ArrayBuffer(46));
    c.setUint32(0, 0x02014b50, true); c.setUint16(4, 20, true); c.setUint16(6, 20, true); c.setUint16(8, 0x0800, true);
    c.setUint32(16, crc, true); c.setUint32(20, data.length, true); c.setUint32(24, data.length, true); c.setUint16(28, nm.length, true);
    c.setUint32(42, offset, true);
    central.push(new Uint8Array(c.buffer), nm);
    offset += 30 + nm.length + data.length;
  }
  const size = central.reduce((n, a) => n + a.length, 0);
  const e = new DataView(new ArrayBuffer(22));
  e.setUint32(0, 0x06054b50, true); e.setUint16(8, Object.keys(files).length, true); e.setUint16(10, Object.keys(files).length, true);
  e.setUint32(12, size, true); e.setUint32(16, offset, true);
  return new Blob([...chunks, ...central, new Uint8Array(e.buffer)]);
}

// ---- PDF ------------------------------------------------------------------------------------

let logoData;
async function logo() {
  logoData ??= fetch(new URL('./logo.png', import.meta.url)).then((r) => r.blob()).then((b) => new Promise((res) => {
    const fr = new FileReader(); fr.onload = () => res(fr.result); fr.readAsDataURL(b);
  })).catch(() => null);
  return logoData;
}

/** sets: datasets; opts: { title, meta, images: [{ title, dataUrl, w, h }] } (charts for the finance dashboard). */
export async function toPdf(sets, { title, meta, company, system, images = [] } = {}) {
  await script('jspdf.umd.min.js');
  await script('jspdf.plugin.autotable.min.js');
  const { jsPDF } = window.jspdf;
  const wide = sets.some((d) => d.columns.length > 6);
  const doc = new jsPDF({ orientation: wide ? 'landscape' : 'portrait', unit: 'pt', format: 'a4' });
  const W = doc.internal.pageSize.getWidth();
  const H = doc.internal.pageSize.getHeight();
  const M = 40;
  const img = await logo();
  const header = () => {
    doc.setFillColor(122, 21, 40); doc.rect(0, 0, W, 5, 'F');
    if (img) doc.addImage(img, 'PNG', M, 18, 110, 41, 'logo', 'FAST'); // one embedded copy reused on every page
    doc.setFont('helvetica', 'bold'); doc.setFontSize(15); doc.setTextColor(28, 26, 25);
    doc.text(pdfSafe(title || 'Report'), W - M, 36, { align: 'right' });
    doc.setFont('helvetica', 'normal'); doc.setFontSize(9); doc.setTextColor(107, 102, 96);
    doc.text(pdfSafe(meta), W - M, 52, { align: 'right' });
    doc.setDrawColor(232, 227, 224); doc.line(M, 70, W - M, 70);
  };
  let y = 86;
  header();
  for (const im of images) {
    const w = (W - 2 * M - 16) / 2;
    const h = w * (im.h / im.w);
    const col = images.indexOf(im) % 2;
    if (col === 0 && y + h + 30 > H - 50) { doc.addPage(); header(); y = 86; }
    const x = M + col * (w + 16);
    doc.setFont('helvetica', 'bold'); doc.setFontSize(10); doc.setTextColor(28, 26, 25);
    doc.text(pdfSafe(im.title), x, y + 10);
    doc.addImage(im.dataUrl, im.dataUrl.startsWith('data:image/jpeg') ? 'JPEG' : 'PNG', x, y + 16, w, h, undefined, 'FAST');
    if (col === 1 || images.indexOf(im) === images.length - 1) y += h + 34;
  }
  sets.forEach((d, i) => {
    if (y > H - 120) { doc.addPage(); header(); y = 86; }
    if (sets.length > 1 || images.length) {
      doc.setFont('helvetica', 'bold'); doc.setFontSize(11); doc.setTextColor(122, 21, 40);
      doc.text(pdfSafe(d.title), M, y + 4); y += 12;
    }
    const numeric = d.columns.map((_, c) => d.rows.length > 0 && d.rows.every((r) => !r[c] || asNumber(r[c]) !== null) && d.rows.some((r) => r[c]));
    // Money columns (header says "(R)", or values carry decimals/commas/R) get thousands separators + 2 decimals.
    const money = d.columns.map((h, c) => numeric[c] && (/\(R\)/.test(h) || d.rows.some((r) => /[.,]|^R/.test(String(r[c] ?? '')))));
    const body = d.rows.map((r) => r.map((v, c) => (money[c] && asNumber(v) !== null
      ? asNumber(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
      : pdfSafe(v))));
    doc.autoTable({
      head: [d.columns.map(pdfSafe)], body, startY: y, margin: { left: M, right: M, top: 86, bottom: 46 },
      styles: { font: 'helvetica', fontSize: wide ? 7.5 : 8.5, cellPadding: 5, textColor: [28, 26, 25], lineColor: [241, 237, 233], lineWidth: 0.5, overflow: 'linebreak' },
      headStyles: { fillColor: [122, 21, 40], textColor: 255, fontStyle: 'bold' },
      alternateRowStyles: { fillColor: [250, 248, 246] },
      columnStyles: Object.fromEntries(numeric.map((n, c) => [c, n ? { halign: 'right' } : {}])),
      didDrawPage: () => { if (doc.internal.getCurrentPageInfo().pageNumber > 1) header(); },
    });
    y = doc.lastAutoTable.finalY + 26;
    if (i === sets.length - 1) return;
  });
  const pages = doc.internal.getNumberOfPages();
  for (let p = 1; p <= pages; p++) {
    doc.setPage(p);
    doc.setFont('helvetica', 'normal'); doc.setFontSize(8); doc.setTextColor(154, 148, 142);
    doc.text([company, system].filter(Boolean).join(' · ') + ' · Confidential', M, H - 22);
    doc.text('Page ' + p + ' of ' + pages, W - M, H - 22, { align: 'right' });
  }
  doc.save(fileBase(title) + '.pdf');
}

/** Run an export of the current page. format: csv | xlsx | pdf */
export async function exportPage(format, session) {
  const sets = collect();
  if (!sets.length) throw new Error('Nothing to export on this page yet.');
  const title = pageTitle();
  const s = (session && session.settings) || {};
  const rows = sets.reduce((n, d) => n + d.rows.length, 0);
  const meta = `${s.company_name || 'Morena Properties'} · exported ${new Date().toLocaleString('en-ZA', { dateStyle: 'medium', timeStyle: 'short' })} · ${rows} row${rows === 1 ? '' : 's'}`;
  if (format === 'csv') toCsv(sets, title);
  else if (format === 'xlsx') toXlsx(sets, title, meta);
  else await toPdf(sets, { title, meta, company: s.company_name, system: s.system_name, images: typeof window.__morenaExportImages === 'function' ? window.__morenaExportImages() : [] });
  return rows;
}
