<?php

use App\Http\Controllers\Api\V1;
use Illuminate\Support\Facades\Route;

/*
| Morena CRM API — handover §4. All routes are prefixed /api/v1.
| Role groups map to Spatie roles: admin, branch_manager, hr, agent, landlord, tenant.
*/

$sales = 'role:admin|branch_manager|agent';
$staffRoles = 'role:admin|branch_manager|hr|agent';
$hr = 'role:admin|hr';

Route::prefix('v1')->group(function () use ($sales, $staffRoles, $hr) {

    // ---- Public ---------------------------------------------------------------
    Route::post('auth/login', [V1\AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('auth/forgot-password', [V1\AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('auth/reset-password', [V1\AuthController::class, 'resetPassword'])->middleware('throttle:10,1');

    // Client upload links (no auth — token + expiry validated server-side, §7)
    Route::get('upload-links/{token}', [V1\PublicUploadController::class, 'show'])
        ->where('token', '[a-z0-9]{6,64}')->middleware('throttle:60,1');
    Route::post('upload-links/{token}/submit', [V1\PublicUploadController::class, 'submit'])
        ->where('token', '[a-z0-9]{6,64}')->middleware('throttle:20,1');

    // ---- Authenticated --------------------------------------------------------
    Route::middleware('auth:sanctum')->group(function () use ($sales, $staffRoles, $hr) {
        Route::post('auth/logout', [V1\AuthController::class, 'logout']);
        Route::get('auth/me', [V1\AuthController::class, 'me']);
        Route::put('auth/password', [V1\AuthController::class, 'updatePassword']);

        // Dashboards
        Route::get('dashboard/admin', [V1\DashboardController::class, 'admin'])->middleware('role:admin');
        Route::get('dashboard/agent', [V1\DashboardController::class, 'agent'])->middleware('role:agent');
        Route::get('dashboard/manager', [V1\DashboardController::class, 'manager'])->middleware('role:admin|branch_manager');
        Route::get('dashboard/landlord', [V1\DashboardController::class, 'landlord'])->middleware('role:landlord');
        Route::get('dashboard/tenant', [V1\DashboardController::class, 'tenant'])->middleware('role:tenant');
        Route::get('analytics', [V1\AnalyticsController::class, 'index'])->middleware('role:admin');

        // Pipeline stages
        Route::get('pipeline-stages', [V1\PipelineStageController::class, 'index'])->middleware($staffRoles);
        Route::middleware('role:admin')->group(function () {
            Route::post('pipeline-stages', [V1\PipelineStageController::class, 'store']);
            Route::post('pipeline-stages/reorder', [V1\PipelineStageController::class, 'reorder']);
            Route::post('pipeline-stages/reset', [V1\PipelineStageController::class, 'reset']);
            Route::put('pipeline-stages/{stage}', [V1\PipelineStageController::class, 'update']);
            Route::delete('pipeline-stages/{stage}', [V1\PipelineStageController::class, 'destroy']);
        });

        // Deals / leads / listings (auto-scoped by role)
        Route::middleware($sales)->group(function () {
            Route::get('deals', [V1\DealController::class, 'index']);
            Route::post('deals', [V1\DealController::class, 'store']);
            Route::get('deals/{deal}', [V1\DealController::class, 'show']);
            Route::put('deals/{deal}', [V1\DealController::class, 'update']);
            Route::post('deals/{deal}/advance-stage', [V1\DealController::class, 'advance']);

            Route::get('leads', [V1\LeadController::class, 'index']);
            Route::post('leads', [V1\LeadController::class, 'store']);
            Route::put('leads/{lead}', [V1\LeadController::class, 'update']);
            Route::post('leads/{lead}/convert', [V1\LeadController::class, 'convert']);

            Route::get('listings', [V1\ListingController::class, 'index']);
            Route::post('listings', [V1\ListingController::class, 'store']);
            Route::put('listings/{listing}', [V1\ListingController::class, 'update']);
        });

        // Commissions
        Route::get('commissions', [V1\CommissionController::class, 'index'])->middleware($sales);
        Route::middleware('role:admin')->group(function () {
            Route::post('commissions/{commission}/mark-paid', [V1\CommissionController::class, 'markPaid']);
            Route::get('commission-settings', [V1\CommissionSettingsController::class, 'show']);
            Route::put('commission-settings', [V1\CommissionSettingsController::class, 'update']);
            Route::get('commission-tiers', [V1\CommissionTierController::class, 'index']);
            Route::post('commission-tiers', [V1\CommissionTierController::class, 'store']);
            Route::put('commission-tiers/{tier}', [V1\CommissionTierController::class, 'update']);
            Route::delete('commission-tiers/{tier}', [V1\CommissionTierController::class, 'destroy']);
        });

        // HR
        Route::get('staff', [V1\StaffController::class, 'index'])->middleware('role:admin|hr|branch_manager');
        Route::get('staff/{staff}', [V1\StaffController::class, 'show'])->middleware($staffRoles);
        Route::post('staff', [V1\StaffController::class, 'store'])->middleware($hr);
        Route::put('staff/{staff}', [V1\StaffController::class, 'update'])->middleware($staffRoles);

        Route::middleware($hr)->group(function () {
            Route::get('contracts', [V1\ContractController::class, 'index']);
            Route::post('contracts', [V1\ContractController::class, 'store']);
            Route::put('contracts/{contract}', [V1\ContractController::class, 'update']);
            Route::get('contracts/{contract}/download', [V1\ContractController::class, 'download']);

            Route::get('onboarding', [V1\OnboardingController::class, 'index']);
            Route::post('onboarding', [V1\OnboardingController::class, 'store']);
            Route::put('onboarding-tasks/{task}', [V1\OnboardingController::class, 'updateTask']);
        });

        Route::get('leave-requests', [V1\LeaveController::class, 'index'])->middleware($staffRoles);
        Route::post('leave-requests', [V1\LeaveController::class, 'store'])->middleware($staffRoles);
        Route::post('leave-requests/{leaveRequest}/approve', [V1\LeaveController::class, 'approve'])->middleware('role:admin|hr|branch_manager');
        Route::post('leave-requests/{leaveRequest}/decline', [V1\LeaveController::class, 'decline'])->middleware('role:admin|hr|branch_manager');
        Route::get('leave-balances', [V1\LeaveController::class, 'balances'])->middleware('role:admin|hr|branch_manager');

        // Documents (scoped per role; landlord/tenant see only their own)
        Route::get('documents', [V1\DocumentController::class, 'index']);
        Route::post('documents', [V1\DocumentController::class, 'store'])->middleware($staffRoles);
        Route::get('documents/{document}/download', [V1\DocumentController::class, 'download']);
        Route::get('document-categories', [V1\DocumentController::class, 'categories'])->middleware($staffRoles);

        Route::get('document-templates', [V1\TemplateController::class, 'index'])->middleware($staffRoles);
        Route::get('document-templates/{template}/download', [V1\TemplateController::class, 'download'])->middleware($staffRoles);
        Route::post('document-templates', [V1\TemplateController::class, 'store'])->middleware('role:admin');
        Route::post('document-templates/{template}/new-version', [V1\TemplateController::class, 'newVersion'])->middleware('role:admin');

        Route::middleware($sales)->group(function () {
            Route::get('upload-links', [V1\UploadLinkController::class, 'index']);
            Route::post('upload-links', [V1\UploadLinkController::class, 'store']);
            Route::get('upload-links/{link}/items', [V1\UploadLinkController::class, 'items'])->whereNumber('link');
            Route::post('upload-links/{link}/file', [V1\UploadLinkController::class, 'file'])->whereNumber('link');
            Route::post('upload-links/{link}/resend', [V1\UploadLinkController::class, 'resend'])->whereNumber('link');
        });

        Route::get('retention/summary', [V1\RetentionController::class, 'summary'])->middleware('role:admin');
        Route::get('retention/expiring', [V1\RetentionController::class, 'expiring'])->middleware('role:admin');

        // Training
        Route::get('courses', [V1\CourseController::class, 'index'])->middleware($staffRoles);
        Route::get('courses/{course}', [V1\CourseController::class, 'show'])->middleware($staffRoles);
        Route::post('courses', [V1\CourseController::class, 'store'])->middleware($hr);
        Route::put('courses/{course}', [V1\CourseController::class, 'update'])->middleware($hr);
        Route::put('lessons/{lesson}/complete', [V1\CourseController::class, 'completeLesson'])->middleware($staffRoles);
        Route::get('certifications', [V1\CertificationController::class, 'index'])->middleware('role:admin|hr');

        // Finance / accounting (agency books — trust accounts stay in PayProp)
        Route::prefix('finance')->middleware('role:admin|finance')->group(function () {
            Route::get('dashboard', [V1\Finance\FinanceController::class, 'dashboard']);
            Route::get('settings', [V1\Finance\FinanceController::class, 'settings']);
            Route::put('settings', [V1\Finance\FinanceController::class, 'updateSettings']);

            Route::get('accounts', [V1\Finance\AccountController::class, 'index']);
            Route::post('accounts', [V1\Finance\AccountController::class, 'store']);
            Route::put('accounts/{account}', [V1\Finance\AccountController::class, 'update']);
            Route::get('tax-rates', [V1\Finance\AccountController::class, 'taxRates']);
            Route::get('contacts', [V1\Finance\AccountController::class, 'contacts']);
            Route::post('contacts', [V1\Finance\AccountController::class, 'storeContact']);
            Route::put('contacts/{contact}', [V1\Finance\AccountController::class, 'updateContact']);

            Route::get('journals', [V1\Finance\JournalController::class, 'index']);
            Route::post('journals', [V1\Finance\JournalController::class, 'store']);
            Route::get('journals/{journal}', [V1\Finance\JournalController::class, 'show']);
            Route::post('journals/{journal}/void', [V1\Finance\JournalController::class, 'void']);
            Route::post('journals/{journal}/reverse', [V1\Finance\JournalController::class, 'reverse']);

            Route::prefix('{kind}')->where(['kind' => 'invoices|bills'])->group(function () {
                Route::get('/', [V1\Finance\DocumentController::class, 'index']);
                Route::post('/', [V1\Finance\DocumentController::class, 'store']);
                Route::get('{id}', [V1\Finance\DocumentController::class, 'show'])->whereNumber('id');
                Route::put('{id}', [V1\Finance\DocumentController::class, 'update'])->whereNumber('id');
                Route::post('{id}/approve', [V1\Finance\DocumentController::class, 'approve'])->whereNumber('id');
                Route::post('{id}/void', [V1\Finance\DocumentController::class, 'void'])->whereNumber('id');
            });

            Route::get('payments', [V1\Finance\PaymentController::class, 'index']);
            Route::post('payments', [V1\Finance\PaymentController::class, 'store']);
            Route::post('payments/{payment}/void', [V1\Finance\PaymentController::class, 'void']);

            Route::get('bank-accounts', [V1\Finance\BankController::class, 'accounts']);
            Route::get('bank-transactions', [V1\Finance\BankController::class, 'transactions']);
            Route::post('bank-transactions/import', [V1\Finance\BankController::class, 'import']);
            Route::get('bank-transactions/{transaction}/suggestions', [V1\Finance\BankController::class, 'suggestions']);
            Route::post('bank-transactions/{transaction}/match', [V1\Finance\BankController::class, 'match']);
            Route::post('bank-transactions/{transaction}/create', [V1\Finance\BankController::class, 'create']);
            Route::post('bank-transactions/{transaction}/unmatch', [V1\Finance\BankController::class, 'unmatch']);
            Route::post('bank-transactions/{transaction}/ignore', [V1\Finance\BankController::class, 'ignore']);
            Route::get('reconciliations', [V1\Finance\BankController::class, 'reconciliations']);
            Route::post('reconciliations', [V1\Finance\BankController::class, 'reconcile']);

            Route::get('reports/{report}', [V1\Finance\ReportController::class, 'show']);

            Route::get('payroll/employees', [V1\Finance\PayrollController::class, 'employees']);
            Route::put('payroll/employees/{staff}', [V1\Finance\PayrollController::class, 'saveProfile']);
            Route::get('payroll/tax-tables', [V1\Finance\PayrollController::class, 'taxTables']);
            Route::post('payroll/tax-tables', [V1\Finance\PayrollController::class, 'saveTaxTable']);
            Route::put('payroll/tax-tables/{table}', [V1\Finance\PayrollController::class, 'saveTaxTable']);
            Route::get('payroll/runs', [V1\Finance\PayrollController::class, 'runs']);
            Route::post('payroll/runs', [V1\Finance\PayrollController::class, 'calculate']);
            Route::get('payroll/runs/{run}', [V1\Finance\PayrollController::class, 'showRun']);
            Route::post('payroll/runs/{run}/finalise', [V1\Finance\PayrollController::class, 'finalise']);
            Route::get('payroll/payslips/{payslip}', [V1\Finance\PayrollController::class, 'payslip']);
        });

        // System
        Route::middleware('role:admin')->group(function () {
            Route::get('users', [V1\UserController::class, 'index']);
            Route::post('users/invite', [V1\UserController::class, 'invite']);
            Route::put('users/{user}/role', [V1\UserController::class, 'updateRole']);
            Route::put('settings', [V1\SettingsController::class, 'update']);
        });
        Route::get('settings', [V1\SettingsController::class, 'show']);
        Route::get('branches', [V1\SettingsController::class, 'branches']);
    });
});
