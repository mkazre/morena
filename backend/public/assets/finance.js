// Helpers shared by the finance pages. Import alongside app.js:
//   const fin = await import('../assets/finance.js');
import { api, form } from './app.js';

/** 12345.6 → "12,345.60"; negatives keep the minus sign. */
export function amt(n) {
  const v = Number(n) || 0;
  return (v < 0 ? '-' : '') + Math.abs(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/** "R 12,345.60" */
export const rand = (n) => 'R ' + amt(n);

export const today = () => new Date().toISOString().slice(0, 10);
export const addDays = (iso, days) => { const d = new Date(iso + 'T00:00:00'); d.setDate(d.getDate() + days); return d.toISOString().slice(0, 10); };

export const DOC_STATUS = {
  draft: ['Draft', ''], approved: ['Awaiting payment', 'b-info'], partial: ['Part paid', 'b-warn'],
  paid: ['Paid', 'b-ok'], void: ['Void', 'b-danger'], overdue: ['Overdue', 'b-danger'],
  posted: ['Posted', 'b-ok'], unmatched: ['Unmatched', 'b-warn'], matched: ['Matched', 'b-info'],
  reconciled: ['Reconciled', 'b-ok'], ignored: ['Ignored', ''], finalised: ['Finalised', 'b-ok'],
};
export const statusLabel = (s) => (DOC_STATUS[s] || [s])[0];
export const statusClass = (s) => 'badge ' + ((DOC_STATUS[s] || [])[1] || '');

export const TYPE_LABELS = { asset: 'Assets', liability: 'Liabilities', equity: 'Equity', income: 'Income', expense: 'Expenses' };
export const SUBTYPE_LABELS = {
  bank: 'Bank & cash', receivable: 'Accounts receivable', current_asset: 'Current asset', fixed_asset: 'Fixed asset', vat_input: 'VAT input',
  payable: 'Accounts payable', current_liability: 'Current liability', vat_output: 'VAT output', long_term_liability: 'Long-term liability',
  equity: 'Equity', retained_earnings: 'Retained earnings', revenue: 'Revenue', other_income: 'Other income',
  cost_of_sales: 'Cost of sales', operating_expense: 'Operating expense', other_expense: 'Other expense',
};
export const SUBTYPES = {
  asset: ['bank', 'receivable', 'current_asset', 'fixed_asset', 'vat_input'],
  liability: ['payable', 'current_liability', 'vat_output', 'long_term_liability'],
  equity: ['equity', 'retained_earnings'], income: ['revenue', 'other_income'],
  expense: ['cost_of_sales', 'operating_expense', 'other_expense'],
};

const cache = {};
export function lookups() {
  return (cache.all ??= Promise.all([api('/finance/accounts'), api('/finance/tax-rates'), api('/finance/contacts')]).then(([a, t, c]) => ({
    accounts: a.data, taxRates: t.data, contacts: c.data,
    banks: a.data.filter((x) => x.subtype === 'bank'),
  })));
}
export function resetLookups() { delete cache.all; }

/** Options for an account <select>, optionally limited to some types. */
export function accountOptions(accounts, types) {
  return accounts.filter((a) => !types || types.includes(a.type)).map((a) => ({ value: a.id, label: a.code + ' · ' + a.name }));
}

/** Line maths identical to the server (cents, VAT rounded per line). */
export function lineTotals(lines, taxRates) {
  const rate = (id) => (taxRates.find((t) => String(t.id) === String(id)) || { rate: 0 }).rate;
  let sub = 0;
  let vat = 0;
  const rows = lines.map((l) => {
    const net = Math.round((Number(l.quantity) || 0) * (Number(l.unit_price) || 0) * 100);
    const tax = Math.round(net * rate(l.tax_rate_id) / 100);
    sub += net;
    vat += tax;
    return { net: net / 100, tax: tax / 100 };
  });
  return { rows, subtotal: sub / 100, vat: vat / 100, total: (sub + vat) / 100 };
}

/** Quick "new contact" dialog; resolves with the saved contact. */
export async function newContact(type = 'customer') {
  const saved = await form({
    title: type === 'supplier' ? 'New supplier' : 'New customer',
    submitLabel: 'Save contact',
    fields: [
      { name: 'name', label: 'Name', required: true },
      { name: 'type', label: 'Type', type: 'select', value: type, options: [{ value: 'customer', label: 'Customer' }, { value: 'supplier', label: 'Supplier' }, { value: 'both', label: 'Both' }] },
      { name: 'email', label: 'Email', type: 'email' },
      { name: 'phone', label: 'Phone' },
      { name: 'vat_number', label: 'VAT number' },
      { name: 'address', label: 'Address', type: 'textarea', rows: 3 },
    ],
    onSubmit: (v) => api('/finance/contacts', { method: 'POST', body: v }),
  });
  if (saved) resetLookups();
  return saved;
}

/** Financial-year presets for report date pickers (SA default FY starts 1 March). */
export function periodPresets(fyStartMonth = 3) {
  const now = new Date();
  const iso = (d) => d.toISOString().slice(0, 10);
  const y = now.getFullYear();
  const m = now.getMonth();
  const fyStart = new Date(m + 1 >= fyStartMonth ? y : y - 1, fyStartMonth - 1, 1, 12);
  const prevFyStart = new Date(fyStart.getFullYear() - 1, fyStartMonth - 1, 1, 12);
  const prevFyEnd = new Date(fyStart.getTime() - 864e5);
  return {
    this_month: [iso(new Date(y, m, 1, 12)), iso(now)],
    last_month: [iso(new Date(y, m - 1, 1, 12)), iso(new Date(y, m, 0, 12))],
    this_quarter: [iso(new Date(y, m - (m % 3), 1, 12)), iso(now)],
    this_fy: [iso(fyStart), iso(now)],
    last_fy: [iso(prevFyStart), iso(prevFyEnd)],
  };
}
