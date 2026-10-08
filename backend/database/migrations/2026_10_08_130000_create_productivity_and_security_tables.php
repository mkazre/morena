<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Infrastructure for the productivity, communication, compliance and security features. */
return new class extends Migration
{
    public function up(): void
    {
        // In-app notification centre (bell).
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);   // leave_request, upload_submitted, task_due, mention, ffc_expiry …
            $table->string('title');
            $table->string('body')->nullable();
            $table->string('url')->nullable();
            $table->string('dedupe_key')->nullable(); // stops the daily job notifying the same thing twice
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read_at']);
            $table->unique(['user_id', 'dedupe_key']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('notes')->nullable();
            $table->foreignId('assigned_to')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->nullableMorphs('related'); // Lead, Deal, Listing, Contact…
            $table->dateTime('due_at')->nullable()->index();
            $table->enum('priority', ['low', 'normal', 'high'])->default('normal');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['viewing', 'valuation', 'meeting', 'signing', 'inspection', 'other'])->default('viewing');
            $table->string('title');
            $table->dateTime('starts_at')->index();
            $table->dateTime('ends_at');
            $table->string('location')->nullable();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // owner (agent)
            $table->foreignId('listing_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('deal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('attendee_name')->nullable();
            $table->string('attendee_phone')->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['scheduled', 'done', 'cancelled', 'no_show'])->default('scheduled');
            $table->timestamps();
        });

        Schema::create('deal_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->json('mentions')->nullable(); // user ids
            $table->timestamps();
        });

        // Who changed what (system-wide).
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 20); // created, updated, deleted, login, settings…
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('label')->nullable(); // human description of the record
            $table->json('changes')->nullable();  // {field: [old, new]}
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->index();
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('login_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email');
            $table->boolean('success');
            $table->string('reason', 40)->nullable(); // bad_password, locked, 2fa_failed…
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->index();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();          // encrypted
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();  // encrypted JSON
            $table->unsignedSmallInteger('failed_logins')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->string('calendar_token', 64)->nullable()->unique(); // private iCal feed
        });

        Schema::create('agent_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->string('period', 7); // YYYY-MM
            $table->unsignedSmallInteger('deals_target')->default(0);
            $table->decimal('value_target', 14, 2)->default(0);
            $table->unsignedSmallInteger('listings_target')->default(0);
            $table->timestamps();
            $table->unique(['staff_id', 'period']);
        });

        // POPIA: lawful basis for processing + marketing consent.
        Schema::table('leads', function (Blueprint $table) {
            $table->unsignedTinyInteger('score')->nullable()->index();
            $table->boolean('marketing_consent')->default(false);
            $table->timestamp('consent_recorded_at')->nullable();
            $table->string('consent_source', 60)->nullable(); // web form, verbal, WhatsApp…
            $table->timestamp('last_contacted_at')->nullable();
        });
        Schema::table('contacts', function (Blueprint $table) {
            $table->boolean('marketing_consent')->default(false);
            $table->timestamp('consent_recorded_at')->nullable();
        });

        Schema::create('recurring_documents', function (Blueprint $table) {
            $table->id();
            $table->enum('kind', ['invoice', 'bill']);
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('reference')->nullable();
            $table->json('lines'); // same shape as an invoice/bill save payload
            $table->enum('frequency', ['monthly', 'quarterly', 'yearly'])->default('monthly');
            $table->unsignedTinyInteger('day_of_month')->default(1);
            $table->unsignedSmallInteger('due_days')->default(30);
            $table->boolean('auto_approve')->default(true);
            $table->date('next_run_on')->index();
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7); // YYYY-MM
            $table->decimal('amount', 14, 2);
            $table->timestamps();
            $table->unique(['account_id', 'period']);
        });

        Schema::create('email_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('to');
            $table->string('subject');
            $table->text('body');
            $table->nullableMorphs('related');
            $table->enum('status', ['sent', 'failed'])->default('sent');
            $table->string('error')->nullable();
            $table->timestamp('created_at');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('receipt_document_id')->nullable()->constrained('documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $t) => $t->dropConstrainedForeignId('receipt_document_id'));
        foreach (['email_log', 'budgets', 'recurring_documents', 'agent_targets', 'login_events', 'audit_logs', 'deal_comments', 'appointments', 'tasks', 'app_notifications'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('contacts', fn (Blueprint $t) => $t->dropColumn(['marketing_consent', 'consent_recorded_at']));
        Schema::table('leads', fn (Blueprint $t) => $t->dropColumn(['score', 'marketing_consent', 'consent_recorded_at', 'consent_source', 'last_contacted_at']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['two_factor_secret', 'two_factor_confirmed_at', 'two_factor_recovery_codes', 'failed_logins', 'locked_until', 'calendar_token']));
    }
};
