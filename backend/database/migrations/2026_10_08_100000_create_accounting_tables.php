<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agency (business) accounting — double-entry general ledger, AR/AP, banking and VAT.
 * Trust money stays in PayProp; these are Morena's own books.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->enum('type', ['asset', 'liability', 'equity', 'income', 'expense']);
            // Drives report grouping and cash-flow classification.
            $table->enum('subtype', [
                'bank', 'receivable', 'current_asset', 'fixed_asset', 'vat_input',
                'payable', 'current_liability', 'vat_output', 'long_term_liability',
                'equity', 'retained_earnings',
                'revenue', 'other_income', 'cost_of_sales', 'operating_expense', 'other_expense',
            ]);
            $table->string('system_key', 40)->nullable()->unique(); // e.g. "ar", "ap", "vat_output" — accounts postings depend on
            $table->string('description')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 20)->unique(); // STD, ZERO, EXEMPT, NONE
            $table->decimal('rate', 5, 2);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['customer', 'supplier', 'both'])->default('customer');
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('vat_number', 30)->nullable();
            $table->text('address')->nullable();
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete(); // agents paid as suppliers
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->nullable()->unique();
            $table->date('date')->index();
            $table->string('reference')->nullable();
            $table->string('memo')->nullable();
            $table->nullableMorphs('source'); // Invoice, Bill, Payment, Commission… or null for manual journals
            $table->enum('status', ['posted', 'void'])->default('posted');
            $table->foreignId('reverses_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tax_rate_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description')->nullable();
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            // For VAT lines: the net amount the VAT was charged on (VAT201 needs both).
            $table->decimal('tax_base', 14, 2)->nullable();
            $table->index(['account_id', 'journal_entry_id']);
        });

        foreach (['invoices' => 'contact', 'bills' => 'contact'] as $name => $_) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->id();
                $table->string('number', 30)->nullable()->unique();
                $table->foreignId('contact_id')->constrained()->restrictOnDelete();
                $table->date('date');
                $table->date('due_date');
                $table->string('reference')->nullable(); // customer order no. / supplier invoice no.
                $table->enum('status', ['draft', 'approved', 'partial', 'paid', 'void'])->default('draft');
                $table->decimal('subtotal', 14, 2)->default(0);
                $table->decimal('vat_total', 14, 2)->default(0);
                $table->decimal('total', 14, 2)->default(0);
                $table->decimal('amount_paid', 14, 2)->default(0);
                $table->text('notes')->nullable();
                if ($name === 'invoices') {
                    $table->foreignId('deal_id')->nullable()->constrained()->nullOnDelete();
                }
                $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });

            Schema::create(substr($name, 0, -1).'_lines', function (Blueprint $table) use ($name) {
                $table->id();
                $table->foreignId(substr($name, 0, -1).'_id')->constrained()->cascadeOnDelete();
                $table->foreignId('account_id')->constrained()->restrictOnDelete();
                $table->foreignId('tax_rate_id')->nullable()->constrained()->nullOnDelete();
                $table->string('description');
                $table->decimal('quantity', 12, 2)->default(1);
                $table->decimal('unit_price', 14, 2);
                $table->decimal('line_total', 14, 2); // excl. VAT
                $table->decimal('vat_amount', 14, 2)->default(0);
                $table->unsignedSmallInteger('position')->default(0);
            });
        }

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->nullable()->unique();
            $table->enum('direction', ['received', 'made']);
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bank_account_id')->constrained('accounts')->restrictOnDelete();
            $table->date('date');
            $table->decimal('amount', 14, 2);
            $table->string('method', 30)->default('eft');
            $table->string('reference')->nullable();
            $table->enum('status', ['posted', 'void'])->default('posted');
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->morphs('document'); // Invoice or Bill
            $table->decimal('amount', 14, 2);
        });

        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained('accounts')->cascadeOnDelete();
            $table->date('date')->index();
            $table->string('description');
            $table->string('reference')->nullable();
            $table->decimal('amount', 14, 2); // + money in, − money out (as on the statement)
            $table->enum('status', ['unmatched', 'matched', 'reconciled', 'ignored'])->default('unmatched');
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reconciliation_id')->nullable();
            $table->string('import_hash', 64)->nullable()->unique(); // de-duplicates re-imported statements
            $table->timestamps();
        });

        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained('accounts')->cascadeOnDelete();
            $table->date('statement_date');
            $table->decimal('statement_balance', 14, 2);
            $table->decimal('ledger_balance', 14, 2);
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Singleton (id = 1).
        Schema::create('accounting_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('financial_year_start_month')->default(3); // SA norm: March–February
            $table->date('lock_date')->nullable(); // nothing on/before this date can be posted, edited or voided
            $table->string('invoice_prefix', 10)->default('INV-');
            $table->unsignedSmallInteger('invoice_due_days')->default(30);
            $table->string('invoice_footer')->nullable(); // banking details etc. shown on invoices
            $table->boolean('auto_invoice_on_deal_close')->default(true);
            $table->foreignId('default_bank_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['accounting_settings', 'bank_reconciliations', 'bank_transactions', 'payment_allocations', 'payments',
            'bill_lines', 'bills', 'invoice_lines', 'invoices', 'journal_lines', 'journal_entries', 'contacts', 'tax_rates', 'accounts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
