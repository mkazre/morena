<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** South African payroll: PAYE (annualised), UIF, SDL, payslips and the EMP201 monthly return. */
return new class extends Migration
{
    public function up(): void
    {
        // One row per SARS tax year (1 March – end Feb). Editable: SARS publishes new tables every Budget.
        Schema::create('payroll_tax_tables', function (Blueprint $table) {
            $table->id();
            $table->string('label', 20)->unique(); // "2025/26"
            $table->date('starts_on');
            $table->date('ends_on');
            $table->json('brackets');   // [{from, rate, base}] annual
            $table->decimal('rebate_primary', 10, 2);
            $table->decimal('rebate_secondary', 10, 2); // 65+
            $table->decimal('rebate_tertiary', 10, 2);  // 75+
            $table->decimal('medical_credit_main', 8, 2);       // monthly, main member + first dependant each
            $table->decimal('medical_credit_additional', 8, 2); // monthly, each further dependant
            $table->decimal('uif_rate', 5, 2)->default(1);
            $table->decimal('uif_monthly_ceiling', 10, 2);
            $table->decimal('sdl_rate', 5, 2)->default(1);
            $table->decimal('sdl_annual_threshold', 12, 2)->default(500000);
            $table->decimal('retirement_cap_percent', 5, 2)->default(27.5);
            $table->decimal('retirement_cap_annual', 12, 2)->default(350000);
            $table->string('source_note')->nullable();
            $table->timestamps();
        });

        Schema::create('payroll_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->unique()->constrained('staff')->cascadeOnDelete();
            $table->decimal('basic_salary', 12, 2);              // monthly
            $table->decimal('taxable_allowances', 12, 2)->default(0); // monthly (travel, cellphone…)
            $table->decimal('retirement_contribution', 12, 2)->default(0); // monthly employee pension/RA
            $table->unsignedTinyInteger('medical_aid_members')->default(0); // incl. main member
            $table->decimal('other_deductions', 12, 2)->default(0); // monthly, after tax
            $table->date('date_of_birth')->nullable();
            $table->string('tax_number', 20)->nullable();
            $table->string('id_number', 20)->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->boolean('uif_exempt')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->string('period', 7)->unique(); // YYYY-MM
            $table->date('pay_date');
            $table->enum('status', ['draft', 'finalised'])->default('draft');
            $table->foreignId('tax_table_id')->constrained('payroll_tax_tables')->restrictOnDelete();
            $table->decimal('total_gross', 14, 2)->default(0);
            $table->decimal('total_paye', 14, 2)->default(0);
            $table->decimal('total_uif', 14, 2)->default(0); // employee + employer
            $table->decimal('total_sdl', 14, 2)->default(0);
            $table->decimal('total_net', 14, 2)->default(0);
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('finalised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->restrictOnDelete();
            $table->decimal('basic_salary', 12, 2);
            $table->decimal('allowances', 12, 2)->default(0);
            $table->decimal('gross', 12, 2);
            $table->decimal('retirement', 12, 2)->default(0);
            $table->decimal('paye', 12, 2);
            $table->decimal('uif_employee', 12, 2);
            $table->decimal('uif_employer', 12, 2);
            $table->decimal('sdl', 12, 2);
            $table->decimal('other_deductions', 12, 2)->default(0);
            $table->decimal('net', 12, 2);
            $table->json('workings'); // annualised income, tax before rebates, rebates, credits — shown on the payslip
            $table->unique(['payroll_run_id', 'staff_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('payroll_profiles');
        Schema::dropIfExists('payroll_tax_tables');
    }
};
