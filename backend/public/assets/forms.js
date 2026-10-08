// Create/edit dialogs behind the "+ New …" / "Edit" buttons. Each returns the saved record, or null if
// cancelled. They are shared where the same action appears on several pages (admin + agent portal).
import { api, form, formData, me, options, toast, LEAD_SOURCES } from './app.js';

const YES_NO = [{ value: '1', label: 'Yes' }, { value: '0', label: 'No' }];
const isOrgWide = (role) => role === 'admin' || role === 'hr';

export async function dealForm(deal) {
  const { user } = await me();
  const canAssign = user.role !== 'agent';
  const [stages, agents] = await Promise.all([options('stages'), canAssign ? options('agents') : []]);
  const none = [{ value: '', label: '—' }];
  const fields = [
    { name: 'property_title', label: 'Property', required: true, value: deal?.property },
    { name: 'sale_value', label: 'Sale value (R)', type: 'number', required: true, value: deal?.value },
    { name: 'pipeline_stage_id', label: 'Stage', type: 'select', options: stages, value: deal?.stage_id },
    ...(canAssign ? [
      { name: 'selling_agent_id', label: 'Selling agent', type: 'select', options: [...none, ...agents], value: deal?.selling_agent_id ?? deal?.agent_id },
      { name: 'listing_agent_id', label: 'Listing agent', type: 'select', options: [...none, ...agents], value: deal?.listing_agent_id ?? deal?.agent_id, hint: 'Different from the selling agent = co-listed; the commission split applies.' },
      { name: 'referral_agent_id', label: 'Referring agent', type: 'select', options: [...none, ...agents], value: deal?.referral_agent_id },
    ] : []),
    { name: 'buyer_name', label: 'Buyer', value: deal?.buyer_name },
    { name: 'seller_name', label: 'Seller', value: deal?.seller_name },
    { name: 'mandate_type', label: 'Mandate type', type: 'select', options: [{ value: 'sole', label: 'Sole mandate' }, { value: 'open', label: 'Open mandate' }], value: deal?.mandate_type || 'sole' },
    { name: 'bond_required', label: 'Bond required', type: 'select', options: YES_NO, value: deal ? (deal.bond_required ? '1' : '0') : '1' },
    { name: 'bond_ltv_percent', label: 'Bond LTV %', type: 'number', value: deal?.bond_ltv_percent },
    { name: 'expected_close_date', label: 'Expected close', type: 'date', value: deal?.expected_close_date },
  ];
  if (deal) {
    fields.push(
      { name: 'status', label: 'Status', type: 'select', options: [{ value: 'open', label: 'Open' }, { value: 'lost', label: 'Lost' }], value: deal.status === 'lost' ? 'lost' : 'open' },
      { name: 'lost_reason', label: 'Lost reason (if lost)', type: 'select', value: deal.lost_reason, options: [
        { value: '', label: '—' }, { value: 'bond_declined', label: 'Bond declined' }, { value: 'price_disagreement', label: 'Price disagreement' },
        { value: 'buyer_withdrew', label: 'Buyer withdrew' }, { value: 'chose_another_property', label: 'Chose another property' },
        { value: 'seller_withdrew_mandate', label: 'Seller withdrew mandate' }] },
    );
  }
  return form({
    title: deal ? 'Edit deal' : 'New deal',
    submitLabel: deal ? 'Save deal' : 'Create deal',
    fields,
    onSubmit: (v) => {
      const body = { ...v, bond_required: v.bond_required === '1' };
      for (const k of ['selling_agent_id', 'listing_agent_id', 'referral_agent_id', 'lost_reason']) if (k in body && !body[k]) body[k] = null;
      if (deal && body.status === 'closed') delete body.status;
      return deal ? api('/deals/' + deal.id, { method: 'PUT', body }) : api('/deals', { method: 'POST', body });
    },
  });
}

export async function leadForm(lead) {
  const { user } = await me();
  const agents = user.role === 'agent' ? [] : await options('agents');
  return form({
    title: lead ? 'Edit lead' : 'New lead',
    submitLabel: lead ? 'Save lead' : 'Add lead',
    fields: [
      { name: 'name', label: 'Name', required: true, value: lead?.name },
      { name: 'phone', label: 'Phone', value: lead?.phone },
      { name: 'email', label: 'Email', type: 'email', value: lead?.email },
      { name: 'interest_description', label: 'Interested in', value: lead?.interest, hint: 'e.g. 3-bed, Sandton' },
      { name: 'source', label: 'Source', type: 'select', options: LEAD_SOURCES, value: lead?.source || 'website' },
      ...(agents.length ? [{ name: 'assigned_agent_id', label: 'Agent', type: 'select', options: [{ value: '', label: 'Unassigned' }, ...agents], value: lead?.agent_id }] : []),
      ...(lead ? [{ name: 'status', label: 'Status', type: 'select', value: lead.status, options: [
        { value: 'new', label: 'New' }, { value: 'contacted', label: 'Contacted' }, { value: 'qualified', label: 'Qualified' }, { value: 'lost', label: 'Lost' }] }] : []),
      { name: 'marketing_consent', label: 'Client agreed to receive marketing (new listings, newsletters)', type: 'checkbox', value: lead?.marketing_consent,
        hint: lead?.consent_recorded_at ? 'Consent recorded ' + lead.consent_recorded_at.slice(0, 10) + (lead.consent_source ? ' via ' + lead.consent_source : '') : 'POPIA: only tick this if the client said yes. Service messages about their enquiry don’t need it.' },
    ],
    onSubmit: async (v) => {
      if ('assigned_agent_id' in v && !v.assigned_agent_id) v.assigned_agent_id = null;
      if (v.marketing_consent && !lead?.marketing_consent) v.consent_source = 'Recorded by agent';
      if (lead) return api('/leads/' + lead.id, { method: 'PUT', body: v });
      try {
        return await api('/leads', { method: 'POST', body: v });
      } catch (e) {
        if (e.status !== 409 || !e.data?.duplicates) throw e;
        const list = e.data.duplicates.map((d) => '• ' + d.name + (d.phone ? ' · ' + d.phone : '') + (d.email ? ' · ' + d.email : '') + ' — ' + (d.agent || 'unassigned') + ', ' + d.status + ', added ' + d.created_at).join('\n');
        if (!confirm('This client may already be in the CRM:\n\n' + list + '\n\nAdd as a new lead anyway?')) throw new Error('Not added — the client already exists. Search for them instead.');
        return api('/leads', { method: 'POST', body: { ...v, confirm_duplicate: true } });
      }
    },
  });
}

export async function convertLeadForm(lead) {
  return form({
    title: 'Convert ' + lead.name + ' to a deal',
    intro: 'Creates a deal at the first pipeline stage with this lead as the buyer.',
    submitLabel: 'Create deal',
    fields: [
      { name: 'property_title', label: 'Property', required: true },
      { name: 'sale_value', label: 'Offer / sale value (R)', type: 'number', required: true },
      { name: 'expected_close_date', label: 'Expected close', type: 'date' },
    ],
    onSubmit: (v) => api('/leads/' + lead.id + '/convert', { method: 'POST', body: v }),
  });
}

export async function listingForm(listing) {
  const { user } = await me();
  const agents = user.role === 'agent' ? [] : await options('agents');
  const types = [['house', 'Freestanding house'], ['sectional_title', 'Sectional title / apartment'], ['townhouse', 'Townhouse'], ['vacant_land', 'Vacant land'], ['commercial', 'Commercial']];
  return form({
    title: listing ? 'Edit listing' : 'New listing',
    submitLabel: listing ? 'Save listing' : 'Add listing',
    fields: [
      { name: 'title', label: 'Title / address', required: true, value: listing?.title },
      { name: 'suburb', label: 'Suburb', required: true, value: listing?.suburb },
      { name: 'price', label: 'Asking price (R)', type: 'number', required: true, value: listing?.price },
      { name: 'property_type', label: 'Property type', type: 'select', options: types.map(([value, label]) => ({ value, label })), value: listing?.property_type },
      { name: 'beds', label: 'Bedrooms', type: 'number', value: listing?.beds ?? 3 },
      { name: 'baths', label: 'Bathrooms', type: 'number', value: listing?.baths ?? 2 },
      ...(listing ? [{ name: 'status', label: 'Status', type: 'select', value: listing.status, options: [{ value: 'active', label: 'Active' }, { value: 'under_offer', label: 'Under offer' }, { value: 'sold', label: 'Sold' }] }] : []),
      ...(agents.length ? [{ name: 'agent_id', label: 'Agent', type: 'select', options: agents, value: listing?.agent_id }] : []),
      { name: 'mandate_expiry', label: 'Mandate expiry', type: 'date', value: listing?.mandate_expiry },
      { name: 'photo', label: 'Photo', type: 'file', accept: 'image/*' },
    ],
    onSubmit: (v) => {
      const fd = formData(v);
      if (listing) fd.append('_method', 'PUT');
      return api(listing ? '/listings/' + listing.id : '/listings', { method: 'POST', form: fd });
    },
  });
}

export async function uploadLinkForm(deals = []) {
  const { user } = await me();
  const agents = isOrgWide(user.role) ? await options('agents') : [];
  return form({
    title: 'Generate client upload link',
    intro: 'The client gets a secure link to upload into this checklist. Leave the list empty for the standard FICA pack.',
    submitLabel: 'Generate & send',
    fields: [
      { name: 'client_name', label: 'Client name', required: true },
      { name: 'client_phone', label: 'Client WhatsApp / phone' },
      { name: 'client_email', label: 'Client email', type: 'email' },
      ...(deals.length ? [{ name: 'deal_id', label: 'Deal', type: 'select', options: [{ value: '', label: 'Not linked to a deal' }, ...deals] }] : []),
      ...(agents.length ? [{ name: 'agent_id', label: 'Agent', type: 'select', options: agents, required: true }] : []),
      { name: 'items', label: 'Documents to request (one per line)', type: 'lines', rows: 5, hint: 'Default: ID document, proof of address, proof of funds / bond pre-approval' },
    ],
    onSubmit: (v) => api('/upload-links', { method: 'POST', body: {
      ...v, deal_id: v.deal_id || null, items: (v.items || []).map((label) => ({ label })),
    } }),
  });
}

/** Minimal modal: body is built by `build(container)`; actions are [{ label, primary, run }] (run's result closes it). */
function dialog(title, build, actions) {
  return new Promise((resolve) => {
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;z-index:10000;background:rgba(28,26,25,0.45);display:flex;align-items:flex-start;justify-content:center;padding:8vh 16px;font-family:"Plus Jakarta Sans",sans-serif';
    const box = document.createElement('div');
    box.style.cssText = 'width:100%;max-width:500px;background:#fff;border-radius:14px;padding:24px;box-shadow:0 20px 50px rgba(28,26,25,0.25);color:#1c1a19';
    const h = document.createElement('div');
    h.style.cssText = 'font-size:17px;font-weight:700;margin-bottom:14px';
    h.textContent = title;
    box.appendChild(h);
    const body = document.createElement('div');
    body.style.cssText = 'font-size:13.5px;margin-bottom:18px';
    build(body);
    box.appendChild(body);
    const row = document.createElement('div');
    row.style.cssText = 'display:flex;gap:10px;justify-content:flex-end';
    const close = (v) => { overlay.remove(); resolve(v); };
    for (const a of [{ label: 'Close', run: () => null }, ...actions]) {
      const b = document.createElement('button');
      b.type = 'button';
      b.textContent = a.label;
      b.style.cssText = a.primary
        ? 'padding:10px 18px;border:none;border-radius:10px;background:#7a1528;color:#fff;font-weight:700;font-size:13.5px;font-family:inherit;cursor:pointer'
        : 'padding:10px 18px;border:1px solid #e8e3e0;border-radius:10px;background:#fff;color:#1c1a19;font-weight:600;font-size:13.5px;font-family:inherit;cursor:pointer';
      b.onclick = async () => {
        b.disabled = true;
        try { close(await a.run()); } catch (e) { b.disabled = false; toast(e.message, 'danger'); }
      };
      row.appendChild(b);
    }
    box.appendChild(row);
    overlay.appendChild(box);
    overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) close(null); });
    document.body.appendChild(overlay);
  });
}

/** Shows a freshly generated / re-issued upload link with copy-to-clipboard. */
export function shareLink(link) {
  return dialog('Upload link ready', (body) => {
    const p = document.createElement('div');
    p.style.cssText = 'color:#6b6660;margin-bottom:10px';
    p.textContent = 'Share this secure link with ' + link.client + '. It expires on ' + new Date(link.expires_at).toLocaleDateString('en-GB') + '.';
    const code = document.createElement('div');
    code.style.cssText = 'font-family:monospace;font-size:13px;background:#faf8f6;border:1px solid #e8e3e0;border-radius:8px;padding:10px 12px;word-break:break-all';
    code.textContent = link.url;
    body.append(p, code);
  }, [{ label: 'Copy link', primary: true, run: async () => { await navigator.clipboard.writeText(link.url); toast('Link copied'); return true; } }]);
}

/** Row action on upload-link lists: review & file, view files, resend, or re-issue an expired link. */
export async function linkAction(link) {
  if (link.status === 'awaiting_client' || link.status === 'expired') {
    const fresh = await api('/upload-links/' + link.id + '/resend', { method: 'POST' });
    toast(link.status === 'expired' ? 'New link generated' : 'Link re-sent to ' + link.client);
    await shareLink(fresh.data);
    return true;
  }
  const items = (await api('/upload-links/' + link.id + '/items')).data;
  return dialog((link.status === 'uploaded' ? 'Review documents from ' : 'Documents from ') + link.client, (body) => {
    for (const i of items) {
      const r = document.createElement('div');
      r.style.cssText = 'display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-top:1px solid #f1ede9';
      const name = document.createElement('span');
      name.textContent = i.label;
      r.appendChild(name);
      if (i.download_url) {
        const a = document.createElement('a');
        a.href = i.download_url;
        a.textContent = 'Open';
        a.style.cssText = 'font-weight:600;font-size:12.5px;color:#7a1528;text-decoration:none';
        r.appendChild(a);
      } else {
        const m = document.createElement('span');
        m.textContent = 'Not uploaded';
        m.style.cssText = 'font-size:12px;color:#c07c1e;font-weight:600';
        r.appendChild(m);
      }
      body.appendChild(r);
    }
  }, link.status === 'uploaded' ? [{
    label: 'File into Document Library', primary: true,
    run: async () => { await api('/upload-links/' + link.id + '/file', { method: 'POST' }); toast('Documents filed'); return true; },
  }] : []);
}

export async function leaveForm({ forOthers = false } = {}) {
  const staff = forOthers ? await options('staff') : [];
  return form({
    title: forOthers ? 'Capture leave' : 'Request leave',
    submitLabel: forOthers ? 'Capture leave' : 'Submit request',
    fields: [
      ...(forOthers ? [{ name: 'staff_id', label: 'Staff member', type: 'select', options: staff, required: true }] : []),
      { name: 'type', label: 'Leave type', type: 'select', options: [{ value: 'annual', label: 'Annual leave' }, { value: 'sick', label: 'Sick leave' }, { value: 'other', label: 'Other' }] },
      { name: 'start_date', label: 'From', type: 'date', required: true },
      { name: 'end_date', label: 'To', type: 'date', required: true },
      { name: 'days', label: 'Working days', type: 'number', step: '0.5', hint: 'Leave blank to count weekdays automatically' },
      ...(forOthers ? [{ name: 'approved', label: 'Already approved (deduct from balance now)', type: 'checkbox', value: true }] : []),
    ],
    onSubmit: (v) => api('/leave-requests', { method: 'POST', body: v }),
  });
}

export async function staffForm(staff) {
  const branches = await options('branches');
  const depts = [['sales', 'Sales'], ['hr', 'HR'], ['admin', 'Admin'], ['finance', 'Finance']].map(([value, label]) => ({ value, label }));
  const emp = [{ value: 'independent_contractor', label: 'Independent contractor' }, { value: 'permanent', label: 'Permanent employee' }];
  return form({
    title: staff ? 'Edit ' + staff.name : 'Add staff member',
    intro: staff ? null : 'Creates their login (they receive a set-password link) and a staff record.',
    submitLabel: staff ? 'Save profile' : 'Add staff member',
    fields: [
      { name: 'name', label: 'Full name', required: true, value: staff?.name },
      { name: 'email', label: 'Work email', type: 'email', required: true, value: staff?.email },
      { name: 'phone', label: 'Phone', value: staff?.phone },
      { name: 'role_title', label: 'Job title', required: true, value: staff?.role_title },
      { name: 'department', label: 'Department', type: 'select', options: depts, value: staff?.department || 'sales' },
      { name: 'branch_id', label: 'Branch', type: 'select', options: branches, value: staff?.branch_id },
      { name: 'employment_type', label: 'Employment', type: 'select', options: emp, value: staff?.employment_type || 'independent_contractor' },
      { name: 'start_date', label: 'Start date', type: 'date', value: staff?.start_date },
      { name: 'ffc_number', label: 'FFC number', value: staff?.ffc_number },
      { name: 'ffc_expiry', label: 'FFC expiry', type: 'date', value: staff?.ffc_expiry },
      ...(staff ? [{ name: 'status', label: 'Status', type: 'select', value: staff.status, options: [{ value: 'active', label: 'Active' }, { value: 'on_leave', label: 'On leave' }, { value: 'terminated', label: 'Terminated' }] }] : []),
    ],
    onSubmit: (v) => (staff ? api('/staff/' + staff.id, { method: 'PUT', body: v }) : api('/staff', { method: 'POST', body: v })),
  });
}

export async function contractForm(contract) {
  const staff = await options('staff');
  return form({
    title: contract ? 'Update contract' : 'New contract',
    submitLabel: contract ? 'Save contract' : 'Add contract',
    fields: [
      ...(contract ? [] : [{ name: 'staff_id', label: 'Staff member', type: 'select', options: staff, required: true }]),
      { name: 'type', label: 'Contract type', required: true, value: contract?.type, hint: 'e.g. Independent Contractor, Permanent Employee' },
      { name: 'start_date', label: 'Start date', type: 'date', required: true, value: contract?.start_date },
      { name: 'expiry_date', label: 'Renewal / expiry date', type: 'date', value: contract?.expiry_date },
      { name: 'status', label: 'Status', type: 'select', value: contract?.status || 'active', options: [
        { value: 'active', label: 'Active' }, { value: 'renewal_due', label: 'Renewal due' }, { value: 'probation', label: 'Probation' }, { value: 'terminated', label: 'Terminated' }] },
      { name: 'file', label: 'Signed contract (PDF)', type: 'file', accept: '.pdf,.doc,.docx' },
    ],
    onSubmit: (v) => {
      const fd = formData(v);
      if (contract) fd.append('_method', 'PUT');
      return api(contract ? '/contracts/' + contract.id : '/contracts', { method: 'POST', form: fd });
    },
  });
}

export async function onboardingForm() {
  const staff = await options('staff');
  return form({
    title: 'Start onboarding',
    intro: 'Creates the standard checklist: contract, FICA, FFC registration, system access, induction.',
    submitLabel: 'Start onboarding',
    fields: [
      { name: 'staff_id', label: 'New hire', type: 'select', options: staff, required: true, hint: 'Add them in the Staff Directory first if they are not listed.' },
      { name: 'start_date', label: 'Start date', type: 'date' },
    ],
    onSubmit: (v) => api('/onboarding', { method: 'POST', body: v }),
  });
}

export async function documentForm({ linkedType, linkedId } = {}) {
  const categories = await options('categories');
  const link = linkedType ? [] : [
    { name: 'linked_model_type', label: 'Link to', type: 'select', options: [
      { value: '', label: 'Nothing (general)' }, { value: 'deal', label: 'Deal' }, { value: 'lead', label: 'Client / lead' },
      { value: 'listing', label: 'Listing' }, { value: 'staff', label: 'Staff member' }] },
    { name: 'linked_model_id', label: 'Record ID', type: 'number', hint: 'The ID of the deal / lead / listing / staff member' },
  ];
  return form({
    title: 'Upload document',
    submitLabel: 'Upload',
    fields: [
      { name: 'file', label: 'File (PDF, JPG, PNG, DOC — max 10MB)', type: 'file', required: true, accept: '.pdf,.jpg,.jpeg,.png,.doc,.docx' },
      { name: 'name', label: 'Document name', hint: 'Defaults to the file name. Same name on the same record = new version.' },
      { name: 'category_id', label: 'Category', type: 'select', options: categories, required: true },
      ...link,
    ],
    onSubmit: (v) => {
      if (linkedType) { v.linked_model_type = linkedType; v.linked_model_id = linkedId; }
      if (!v.linked_model_type) { v.linked_model_type = null; v.linked_model_id = null; }
      return api('/documents', { method: 'POST', form: formData(v) });
    },
  });
}

export async function templateForm(template) {
  if (template) {
    return form({
      title: 'Replace ' + template.name,
      intro: 'Uploads v' + ((template.version || 0) + 1) + '. Future deals use it; past deals keep the version they used.',
      submitLabel: 'Upload new version',
      fields: [{ name: 'file', label: 'File', type: 'file', required: true, accept: '.pdf,.doc,.docx' }],
      onSubmit: (v) => api('/document-templates/' + template.id + '/new-version', { method: 'POST', form: formData(v) }),
    });
  }
  const categories = await options('categories');
  return form({
    title: 'New template',
    submitLabel: 'Add template',
    fields: [
      { name: 'name', label: 'Template name', required: true },
      { name: 'used_for', label: 'Used for', required: true, hint: 'e.g. Sales deals, New listings, Rentals' },
      { name: 'category_id', label: 'Category', type: 'select', options: [{ value: '', label: '—' }, ...categories] },
      { name: 'file', label: 'File', type: 'file', required: true, accept: '.pdf,.doc,.docx' },
    ],
    onSubmit: (v) => api('/document-templates', { method: 'POST', form: formData(v) }),
  });
}

export async function courseForm(course) {
  return form({
    title: course ? 'Edit course' : 'New course',
    submitLabel: course ? 'Save course' : 'Create course',
    fields: [
      { name: 'title', label: 'Title', required: true, value: course?.title },
      { name: 'category', label: 'Category', required: true, value: course?.category, hint: 'e.g. Compliance, Sales Skills, Onboarding, Systems' },
      { name: 'lessons', label: 'Lessons (one per line, optional "| minutes")', type: 'lines', rows: 7, required: true,
        value: course?.lessons?.map((l) => l.title + ' | ' + parseInt(l.duration, 10)) },
    ],
    onSubmit: (v) => {
      const lessons = v.lessons.map((line) => {
        const [title, mins] = line.split('|').map((s) => s.trim());
        return { title, duration_minutes: parseInt(mins, 10) || 10 };
      });
      const body = { title: v.title, category: v.category, lessons };
      return course ? api('/courses/' + course.id, { method: 'PUT', body }) : api('/courses', { method: 'POST', body });
    },
  });
}

const ROLE_OPTIONS = [
  { value: 'admin', label: 'Administrator' }, { value: 'branch_manager', label: 'Branch Manager' }, { value: 'hr', label: 'HR Staff' }, { value: 'finance', label: 'Finance' },
  { value: 'agent', label: 'Sales Agent' }, { value: 'landlord', label: 'Landlord (portal)' }, { value: 'tenant', label: 'Tenant (portal)' },
];

export async function inviteUserForm() {
  const branches = await options('branches');
  return form({
    title: 'Invite user',
    intro: 'They receive an email link to set their password.',
    submitLabel: 'Send invite',
    fields: [
      { name: 'name', label: 'Name', required: true },
      { name: 'email', label: 'Email', type: 'email', required: true },
      { name: 'role', label: 'Role', type: 'select', options: ROLE_OPTIONS, value: 'agent' },
      { name: 'branch_id', label: 'Branch', type: 'select', options: [{ value: '', label: '—' }, ...branches] },
    ],
    onSubmit: (v) => api('/users/invite', { method: 'POST', body: { ...v, branch_id: v.branch_id || null } }),
  });
}

export async function manageUserForm(u) {
  const branches = await options('branches');
  return form({
    title: 'Manage ' + u.name,
    submitLabel: 'Save',
    fields: [
      { name: 'role', label: 'Role', type: 'select', options: ROLE_OPTIONS, value: u.role },
      { name: 'branch_id', label: 'Branch', type: 'select', options: [{ value: '', label: '—' }, ...branches], value: u.branch_id },
      ...(u.status === 'invited' ? [] : [{ name: 'status', label: 'Access', type: 'select', value: u.status, options: [{ value: 'active', label: 'Active' }, { value: 'suspended', label: 'Suspended' }] }]),
      ...(u.locked ? [{ name: 'unlock', label: 'Unlock account (locked after 5 wrong passwords)', type: 'checkbox' }] : []),
      ...(u.two_factor_enabled ? [{ name: 'reset_two_factor', label: 'Reset two-factor — use if they lost their phone; they re-enrol after signing in', type: 'checkbox' }] : []),
    ],
    onSubmit: (v) => api('/users/' + u.id + '/role', { method: 'PUT', body: { ...v, branch_id: v.branch_id || null } }),
  });
}
