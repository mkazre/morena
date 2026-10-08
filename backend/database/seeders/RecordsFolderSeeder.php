<?php

namespace Database\Seeders;

use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentFolder;
use Illuminate\Database\Seeder;

/**
 * Records hub folder tree (Morena's SharePoint structure). Idempotent: creates missing folders only, never
 * renames or deletes, then files any unfiled documents by category. Permissions per the brief:
 * Finance for directors (admin + finance), HR for management (admin + hr + branch managers).
 */
class RecordsFolderSeeder extends Seeder
{
    private const SALES = ['admin', 'branch_manager', 'agent', 'finance'];

    private const MANAGEMENT = ['admin', 'hr', 'branch_manager'];

    private const ALL_STAFF = ['admin', 'branch_manager', 'hr', 'finance', 'agent'];

    // name => [roles, shared with agents, default category, subfolders (same shape or plain names)]
    public const TREE = [
        'Sales' => [self::SALES, false, 'Offers to Purchase', [
            'Mandates' => [null, false, 'Mandates'], 'Offers to Purchase' => [null, false, 'Offers to Purchase'],
            'Transfers & Deeds' => [null, false, 'Offers to Purchase'], 'Commission Statements' => [null, false, 'Commission Invoices'],
            'Client Files' => [null, false, 'FICA / Compliance'],
        ]],
        'Rentals' => [self::SALES, false, 'Lease Agreements', [
            'Lease Agreements' => [null, false, 'Lease Agreements'], 'Tenant Applications' => [null, false, 'Lease Agreements'],
            'Inspection Reports' => [null, false, 'Lease Agreements'], 'Deposit Records' => [null, false, 'Lease Agreements'],
            'Rental Statements' => [null, false, 'Lease Agreements'],
        ]],
        'HR' => [self::MANAGEMENT, false, 'Staff / HR', [
            'Employee Contracts' => [null, false, 'Staff / HR'], 'Graduate Recruitment' => [null, false, 'Staff / HR'],
            'Performance Reviews' => [null, false, 'Staff / HR'], 'Leave Records' => [null, false, 'Staff / HR'],
            'Training & Development' => [null, false, 'Staff / HR'],
        ]],
        'Compliance' => [self::ALL_STAFF, false, 'FICA / Compliance', [
            'PPRA Certificates' => [null, false, 'FICA / Compliance'], 'CSOS Documentation' => [null, false, 'FICA / Compliance'],
            'FICA Records' => [null, false, 'FICA / Compliance'], 'POPIA Policies' => [null, true, 'FICA / Compliance'],
            'Trust Account Audits' => [['admin', 'finance'], false, 'FICA / Compliance'],
        ]],
        'Finance' => [['admin', 'finance'], false, 'Commission Invoices', [
            'Invoices & Receipts' => [null, false, 'Commission Invoices'], 'Payroll Records' => [null, false, 'Staff / HR'],
            'Tax Returns' => [null, false, 'Commission Invoices'], 'Bank Reconciliations' => [null, false, 'Commission Invoices'],
            'Management Reports' => [null, false, 'Commission Invoices'],
        ]],
        'Corporate' => [self::ALL_STAFF, true, null, [
            'Morena Group Branding' => [null, true, null], 'Policies & Procedures' => [null, true, null],
            'Board Resolutions' => [['admin'], false, null], 'Strategic Plans' => [['admin'], false, null],
        ]],
        'Training & Resources' => [self::ALL_STAFF, true, null, [
            'Agent Guides' => [null, true, null], 'Video Tutorials' => [null, true, null],
            'Forms & Templates' => [null, true, null], 'Best Practice Manuals' => [null, true, null],
        ]],
    ];

    /** Where documents of each category are filed when no folder was chosen. */
    public const CATEGORY_HOME = [
        'Offers to Purchase' => 'Sales/Offers to Purchase', 'Mandates' => 'Sales/Mandates',
        'Commission Invoices' => 'Sales/Commission Statements', 'Lease Agreements' => 'Rentals/Lease Agreements',
        'FICA / Compliance' => 'Compliance/FICA Records', 'Staff / HR' => 'HR/Employee Contracts',
    ];

    public function run(): void
    {
        $cats = DocumentCategory::pluck('id', 'name');
        $pos = 0;
        foreach (self::TREE as $name => [$roles, $shared, $cat, $children]) {
            $top = DocumentFolder::firstOrCreate(['parent_id' => null, 'name' => $name], [
                'roles' => $roles, 'shared_with_agents' => $shared, 'default_category_id' => $cats[$cat] ?? null, 'position' => $pos++, 'is_system' => true,
            ]);
            $i = 0;
            foreach ($children as $child => [$cRoles, $cShared, $cCat]) {
                DocumentFolder::firstOrCreate(['parent_id' => $top->id, 'name' => $child], [
                    'roles' => $cRoles, 'shared_with_agents' => $cShared, 'default_category_id' => $cats[$cCat] ?? null, 'position' => $i++, 'is_system' => true,
                ]);
            }
        }

        foreach (self::CATEGORY_HOME as $category => $path) {
            $folder = self::byPath($path);
            if ($folder && isset($cats[$category])) {
                Document::whereNull('folder_id')->where('category_id', $cats[$category])->update(['folder_id' => $folder->id]);
            }
        }
    }

    public static function byPath(string $path): ?DocumentFolder
    {
        $folder = null;
        foreach (explode('/', $path) as $part) {
            $folder = DocumentFolder::where('parent_id', $folder?->id)->where('name', $part)->first();
            if (! $folder) {
                return null;
            }
        }

        return $folder;
    }

    public static function homeFor(?int $categoryId): ?DocumentFolder
    {
        $name = $categoryId ? DocumentCategory::whereKey($categoryId)->value('name') : null;

        return $name && isset(self::CATEGORY_HOME[$name]) ? self::byPath(self::CATEGORY_HOME[$name]) : null;
    }
}
