// Single source of truth for sidebar nav per role. Each page's logic class
// does: import('./shell/nav-config.js') (relative path adjusted per folder depth)
// and exposes { navSections, roleLabel } to the template.
import { iconMask } from '../assets/icons.js';

// Sidebar icons (Lucide line icons, tinted with the brand colours) by nav key.
export const NAV_ICONS = {
  dashboard: 'dashboard', analytics: 'analytics', pipeline: 'pipeline', deals: 'deals', leads: 'leads', listings: 'listings',
  commissions: 'commissions', 'pipeline-settings': 'settings', 'commission-settings': 'sliders', targets: 'target',
  staff: 'staff', contracts: 'contracts', leave: 'leave', onboarding: 'onboarding', records: 'records', 'doc-library': 'library',
  'doc-templates': 'templates', 'upload-links': 'link', retention: 'shield', courses: 'courses', certifications: 'certifications',
  users: 'user-cog', audit: 'audit', settings: 'settings', tasks: 'tasks', calendar: 'calendar', tools: 'calculator', security: 'lock',
  'fin-dashboard': 'finance', 'fin-invoices': 'invoice-out', 'fin-bills': 'invoice-in', 'fin-payments': 'payments', 'fin-banking': 'bank',
  'fin-journals': 'journal', 'fin-accounts': 'accounts', 'fin-contacts': 'contacts', 'fin-payroll': 'payroll', 'fin-reports': 'reports',
  'fin-planning': 'recurring', 'fin-settings': 'sliders', commission: 'wallet', documents: 'documents', training: 'courses',
  profile: 'profile', team: 'team', 'leave-approvals': 'calendar-check',
};

// Accounting module — shared by admins and the "finance" role.
const FINANCE_SECTION = { title: 'Finance', items: [
  { key: 'fin-dashboard', label: 'Finance Overview', href: '../finance/Dashboard.html' },
  { key: 'fin-invoices', label: 'Invoices', href: '../finance/Invoices.html' },
  { key: 'fin-bills', label: 'Bills', href: '../finance/Bills.html' },
  { key: 'fin-payments', label: 'Payments & Expenses', href: '../finance/Payments.html' },
  { key: 'fin-banking', label: 'Banking', href: '../finance/Banking.html' },
  { key: 'fin-journals', label: 'Journals', href: '../finance/Journals.html' },
  { key: 'fin-accounts', label: 'Chart of Accounts', href: '../finance/Accounts.html' },
  { key: 'fin-contacts', label: 'Customers & Suppliers', href: '../finance/Contacts.html' },
  { key: 'fin-payroll', label: 'Payroll', href: '../finance/Payroll.html' },
  { key: 'fin-reports', label: 'Financial Reports', href: '../finance/Reports.html' },
  { key: 'fin-planning', label: 'Budgets & Recurring', href: '../finance/Planning.html' },
  { key: 'fin-settings', label: 'Finance Settings', href: '../finance/Settings.html' },
]};

// Every staff role gets the same personal workspace.
const WORK_SECTION = { title: 'Workspace', items: [
  { key: 'tasks', label: 'Tasks', href: '../work/Tasks.html' },
  { key: 'calendar', label: 'Calendar', href: '../work/Calendar.html' },
  { key: 'tools', label: 'Calculators & CMA', href: '../work/Tools.html' },
  { key: 'security', label: 'Account Security', href: '../work/Security.html' },
]};

export function getNavForRole(role) {
  const sections = {
    admin: [
      { title: 'Overview', items: [
        { key: 'dashboard', label: 'Dashboard', href: '../admin/Dashboard.html' },
        { key: 'analytics', label: 'Analytics', href: '../admin/Analytics.html' },
      ]},
      WORK_SECTION,
      { title: 'Sales', items: [
        { key: 'pipeline', label: 'Pipeline Board', href: '../sales/Pipeline.html' },
        { key: 'deals', label: 'All Deals', href: '../sales/Deals.html' },
        { key: 'leads', label: 'Leads', href: '../sales/Leads.html' },
        { key: 'listings', label: 'Listings', href: '../sales/Listings.html' },
        { key: 'commissions', label: 'Commissions', href: '../sales/Commissions.html' },
        { key: 'targets', label: 'Targets', href: '../sales/Targets.html' },
        { key: 'pipeline-settings', label: 'Pipeline Settings', href: '../sales/PipelineSettings.html' },
        { key: 'commission-settings', label: 'Commission Settings', href: '../sales/CommissionSettings.html' },
      ]},
      { title: 'People', items: [
        { key: 'staff', label: 'Staff Directory', href: '../hr/StaffDirectory.html' },
        { key: 'contracts', label: 'Contracts', href: '../hr/Contracts.html' },
        { key: 'leave', label: 'Leave Management', href: '../hr/Leave.html' },
        { key: 'onboarding', label: 'Onboarding', href: '../hr/Onboarding.html' },
      ]},
      { title: 'Documents', items: [
        { key: 'records', label: 'Records Hub', href: '../docs/Records.html' },
        { key: 'doc-library', label: 'By Category', href: '../docs/Library.html' },
        { key: 'doc-templates', label: 'Templates', href: '../docs/Templates.html' },
        { key: 'upload-links', label: 'Client Upload Links', href: '../docs/UploadLinks.html' },
        { key: 'retention', label: 'Retention & Compliance', href: '../docs/Retention.html' },
      ]},
      { title: 'Training', items: [
        { key: 'courses', label: 'Courses', href: '../training/Courses.html' },
        { key: 'certifications', label: 'Certifications', href: '../training/Certifications.html' },
      ]},
      FINANCE_SECTION,
      { title: 'System', items: [
        { key: 'users', label: 'Users & Roles', href: '../admin/Users.html' },
        { key: 'audit', label: 'Audit Log', href: '../admin/Audit.html' },
        { key: 'settings', label: 'Settings', href: '../admin/Settings.html' },
      ]},
    ],
    finance: [FINANCE_SECTION, { title: 'Records', items: [{ key: 'records', label: 'Records Hub', href: '../docs/Records.html' }] }, WORK_SECTION],
    agent: [
      { title: 'My Workspace', items: [
        { key: 'dashboard', label: 'My Dashboard', href: '../portal/Dashboard.html' },
        { key: 'deals', label: 'My Deals', href: '../portal/MyDeals.html' },
        { key: 'leads', label: 'My Leads', href: '../portal/MyLeads.html' },
        { key: 'commission', label: 'My Commission', href: '../portal/MyCommission.html' },
        { key: 'documents', label: 'My Documents', href: '../portal/MyDocuments.html' },
        { key: 'records', label: 'Records Hub', href: '../docs/Records.html' },
        { key: 'upload-links', label: 'Client Upload Links', href: '../portal/MyUploadLinks.html' },
        { key: 'training', label: 'My Training', href: '../portal/MyTraining.html' },
        { key: 'targets', label: 'My Targets', href: '../sales/Targets.html' },
        { key: 'profile', label: 'My Profile', href: '../portal/MyProfile.html' },
      ]},
      WORK_SECTION,
    ],
    manager: [
      { title: 'Branch', items: [
        { key: 'dashboard', label: 'Branch Dashboard', href: '../manager/Dashboard.html' },
        { key: 'team', label: 'Team Performance', href: '../manager/Team.html' },
        { key: 'pipeline', label: 'Pipeline Board', href: '../sales/Pipeline.html' },
        { key: 'leave-approvals', label: 'Leave Approvals', href: '../manager/LeaveApprovals.html' },
        { key: 'targets', label: 'Targets', href: '../sales/Targets.html' },
        { key: 'records', label: 'Records Hub', href: '../docs/Records.html' },
      ]},
      WORK_SECTION,
    ],
    hr: [
      { title: 'HR', items: [
        { key: 'staff', label: 'Staff Directory', href: '../hr/StaffDirectory.html' },
        { key: 'contracts', label: 'Contracts', href: '../hr/Contracts.html' },
        { key: 'leave', label: 'Leave Management', href: '../hr/Leave.html' },
        { key: 'onboarding', label: 'Onboarding', href: '../hr/Onboarding.html' },
        { key: 'records', label: 'Records Hub', href: '../docs/Records.html' },
        { key: 'training', label: 'Training Records', href: '../training/Courses.html' },
      ]},
      WORK_SECTION,
    ],
    landlord: [
      { title: 'My Properties', items: [
        { key: 'dashboard', label: 'Overview', href: '../landlord/Dashboard.html' },
        { key: 'documents', label: 'Documents', href: '../landlord/Documents.html' },
        { key: 'security', label: 'Account Security', href: '../work/Security.html' },
      ]},
    ],
    tenant: [
      { title: 'My Tenancy', items: [
        { key: 'dashboard', label: 'Overview', href: '../tenant/Dashboard.html' },
        { key: 'documents', label: 'Documents', href: '../tenant/Documents.html' },
        { key: 'security', label: 'Account Security', href: '../work/Security.html' },
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
      const mask = iconMask(NAV_ICONS[item.key] || 'square') + ' center / contain no-repeat';
      return {
        ...item,
        // drawn by iconStyle's mask, so no emoji/glyph text
        rowStyle: {
          display: 'flex', alignItems: 'center', gap: 10, padding: '9px 10px',
          borderRadius: 8, textDecoration: 'none', fontSize: 14, fontWeight: 500,
          marginBottom: 2,
          background: active ? 'rgba(255,255,255,0.1)' : 'transparent',
          color: active ? '#ffffff' : 'rgba(255,255,255,0.72)',
        },
        iconStyle: { width: 18, height: 18, flexShrink: 0, display: 'inline-block', backgroundColor: active ? '#e2607e' : '#c23552',
          WebkitMask: mask, mask },
      };
    }),
  }));
}

export const roleLabels = {
  admin: 'Administrator', agent: 'Sales Agent', manager: 'Branch Manager',
  hr: 'HR Staff', finance: 'Finance', landlord: 'Landlord', tenant: 'Tenant',
};
