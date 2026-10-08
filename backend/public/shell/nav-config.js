// Single source of truth for sidebar nav per role. Each page's logic class
// does: import('./shell/nav-config.js') (relative path adjusted per folder depth)
// and exposes { navSections, roleLabel } to the template.
// Accounting module — shared by admins and the "finance" role.
const FINANCE_SECTION = { title: 'Finance', items: [
  { key: 'fin-dashboard', label: 'Finance Overview', icon: 'R', href: '../finance/Dashboard.html' },
  { key: 'fin-invoices', label: 'Invoices', icon: '↗', href: '../finance/Invoices.html' },
  { key: 'fin-bills', label: 'Bills', icon: '↙', href: '../finance/Bills.html' },
  { key: 'fin-payments', label: 'Payments & Expenses', icon: '⇄', href: '../finance/Payments.html' },
  { key: 'fin-banking', label: 'Banking', icon: '▭', href: '../finance/Banking.html' },
  { key: 'fin-journals', label: 'Journals', icon: '≡', href: '../finance/Journals.html' },
  { key: 'fin-accounts', label: 'Chart of Accounts', icon: '#', href: '../finance/Accounts.html' },
  { key: 'fin-contacts', label: 'Customers & Suppliers', icon: '◎', href: '../finance/Contacts.html' },
  { key: 'fin-payroll', label: 'Payroll', icon: '◔', href: '../finance/Payroll.html' },
  { key: 'fin-reports', label: 'Financial Reports', icon: '▤', href: '../finance/Reports.html' },
  { key: 'fin-settings', label: 'Finance Settings', icon: '⚙', href: '../finance/Settings.html' },
]};

export function getNavForRole(role) {
  const sections = {
    admin: [
      { title: 'Overview', items: [
        { key: 'dashboard', label: 'Dashboard', icon: '◧', href: '../admin/Dashboard.html' },
        { key: 'analytics', label: 'Analytics', icon: '◫', href: '../admin/Analytics.html' },
      ]},
      { title: 'Sales', items: [
        { key: 'pipeline', label: 'Pipeline Board', icon: '▤', href: '../sales/Pipeline.html' },
        { key: 'deals', label: 'All Deals', icon: '▥', href: '../sales/Deals.html' },
        { key: 'leads', label: 'Leads', icon: '◎', href: '../sales/Leads.html' },
        { key: 'listings', label: 'Listings', icon: '▦', href: '../sales/Listings.html' },
        { key: 'commissions', label: 'Commissions', icon: '◐', href: '../sales/Commissions.html' },
        { key: 'pipeline-settings', label: 'Pipeline Settings', icon: '⚙', href: '../sales/PipelineSettings.html' },
        { key: 'commission-settings', label: 'Commission Settings', icon: '⚙', href: '../sales/CommissionSettings.html' },
      ]},
      { title: 'People', items: [
        { key: 'staff', label: 'Staff Directory', icon: '◉', href: '../hr/StaffDirectory.html' },
        { key: 'contracts', label: 'Contracts', icon: '▧', href: '../hr/Contracts.html' },
        { key: 'leave', label: 'Leave Management', icon: '▨', href: '../hr/Leave.html' },
        { key: 'onboarding', label: 'Onboarding', icon: '◆', href: '../hr/Onboarding.html' },
      ]},
      { title: 'Documents', items: [
        { key: 'records', label: 'Records Hub', icon: '▦', href: '../docs/Records.html' },
        { key: 'doc-library', label: 'By Category', icon: '▣', href: '../docs/Library.html' },
        { key: 'doc-templates', label: 'Templates', icon: '▢', href: '../docs/Templates.html' },
        { key: 'upload-links', label: 'Client Upload Links', icon: '🔗', href: '../docs/UploadLinks.html' },
        { key: 'retention', label: 'Retention & Compliance', icon: '◍', href: '../docs/Retention.html' },
      ]},
      { title: 'Training', items: [
        { key: 'courses', label: 'Courses', icon: '◈', href: '../training/Courses.html' },
        { key: 'certifications', label: 'Certifications', icon: '◇', href: '../training/Certifications.html' },
      ]},
      FINANCE_SECTION,
      { title: 'System', items: [
        { key: 'users', label: 'Users & Roles', icon: '◑', href: '../admin/Users.html' },
        { key: 'settings', label: 'Settings', icon: '⚙', href: '../admin/Settings.html' },
      ]},
    ],
    finance: [FINANCE_SECTION, { title: 'Records', items: [{ key: 'records', label: 'Records Hub', icon: '▦', href: '../docs/Records.html' }] }],
    agent: [
      { title: 'My Workspace', items: [
        { key: 'dashboard', label: 'My Dashboard', icon: '◧', href: '../portal/Dashboard.html' },
        { key: 'deals', label: 'My Deals', icon: '▥', href: '../portal/MyDeals.html' },
        { key: 'leads', label: 'My Leads', icon: '◎', href: '../portal/MyLeads.html' },
        { key: 'commission', label: 'My Commission', icon: '◐', href: '../portal/MyCommission.html' },
        { key: 'documents', label: 'My Documents', icon: '▣', href: '../portal/MyDocuments.html' },
        { key: 'records', label: 'Records Hub', icon: '▦', href: '../docs/Records.html' },
        { key: 'upload-links', label: 'Client Upload Links', icon: '🔗', href: '../portal/MyUploadLinks.html' },
        { key: 'training', label: 'My Training', icon: '◈', href: '../portal/MyTraining.html' },
        { key: 'profile', label: 'My Profile', icon: '◉', href: '../portal/MyProfile.html' },
      ]},
    ],
    manager: [
      { title: 'Branch', items: [
        { key: 'dashboard', label: 'Branch Dashboard', icon: '◧', href: '../manager/Dashboard.html' },
        { key: 'team', label: 'Team Performance', icon: '◫', href: '../manager/Team.html' },
        { key: 'pipeline', label: 'Pipeline Board', icon: '▤', href: '../sales/Pipeline.html' },
        { key: 'leave-approvals', label: 'Leave Approvals', icon: '▨', href: '../manager/LeaveApprovals.html' },
        { key: 'records', label: 'Records Hub', icon: '▦', href: '../docs/Records.html' },
      ]},
    ],
    hr: [
      { title: 'HR', items: [
        { key: 'staff', label: 'Staff Directory', icon: '◉', href: '../hr/StaffDirectory.html' },
        { key: 'contracts', label: 'Contracts', icon: '▧', href: '../hr/Contracts.html' },
        { key: 'leave', label: 'Leave Management', icon: '▨', href: '../hr/Leave.html' },
        { key: 'onboarding', label: 'Onboarding', icon: '◆', href: '../hr/Onboarding.html' },
        { key: 'records', label: 'Records Hub', icon: '▦', href: '../docs/Records.html' },
        { key: 'training', label: 'Training Records', icon: '◈', href: '../training/Courses.html' },
      ]},
    ],
    landlord: [
      { title: 'My Properties', items: [
        { key: 'dashboard', label: 'Overview', icon: '◧', href: '../landlord/Dashboard.html' },
        { key: 'documents', label: 'Documents', icon: '▣', href: '../landlord/Documents.html' },
      ]},
    ],
    tenant: [
      { title: 'My Tenancy', items: [
        { key: 'dashboard', label: 'Overview', icon: '◧', href: '../tenant/Dashboard.html' },
        { key: 'documents', label: 'Documents', icon: '▣', href: '../tenant/Documents.html' },
      ]},
    ],
  };
  return sections[role] || sections.admin;
}

// Returns ready-to-render nav sections with per-item inline style objects baked in
// (active state depends on activeKey, which is only known at page-render time).
export function decorateNav(role, activeKey) {
  const sections = getNavForRole(role);
  return sections.map(section => ({
    title: section.title,
    items: section.items.map(item => {
      const active = item.key === activeKey;
      return {
        ...item,
        rowStyle: {
          display: 'flex', alignItems: 'center', gap: 10, padding: '9px 10px',
          borderRadius: 8, textDecoration: 'none', fontSize: 14, fontWeight: 500,
          marginBottom: 2,
          background: active ? 'rgba(255,255,255,0.1)' : 'transparent',
          color: active ? '#ffffff' : 'rgba(255,255,255,0.72)',
        },
        iconStyle: { width: 18, textAlign: 'center', color: active ? '#e2607e' : '#c23552' },
      };
    }),
  }));
}

export const roleLabels = {
  admin: 'Administrator', agent: 'Sales Agent', manager: 'Branch Manager',
  hr: 'HR Staff', finance: 'Finance', landlord: 'Landlord', tenant: 'Tenant',
};
