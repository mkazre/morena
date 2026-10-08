<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('role_title');
            $table->enum('department', ['sales', 'hr', 'admin', 'finance'])->default('sales');
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('employment_type', ['independent_contractor', 'permanent'])->default('independent_contractor');
            $table->date('start_date')->nullable();
            $table->string('ffc_number')->nullable();
            $table->date('ffc_expiry')->nullable();
            $table->enum('status', ['active', 'on_leave', 'terminated'])->default('active');
            $table->timestamps();
        });

        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->string('type');
            $table->date('start_date');
            $table->date('expiry_date')->nullable();
            $table->enum('status', ['active', 'renewal_due', 'probation', 'terminated'])->default('active');
            $table->string('file_path')->nullable();
            $table->timestamps();
        });

        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('annual_days', 5, 1)->default(15);
            $table->decimal('sick_days', 5, 1)->default(10);
            $table->decimal('taken_days', 5, 1)->default(0);
            $table->timestamps();
            $table->unique(['staff_id', 'year']);
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->enum('type', ['annual', 'sick', 'other']);
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('days', 5, 1);
            $table->enum('status', ['pending', 'approved', 'declined'])->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('onboarding_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->unique()->constrained('staff')->cascadeOnDelete();
            $table->date('start_date');
            $table->string('role_title');
            $table->timestamps();
        });

        Schema::create('onboarding_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_id')->constrained('onboarding_checklists')->cascadeOnDelete();
            $table->string('label');
            $table->boolean('is_done')->default(false);
            $table->unsignedSmallInteger('order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_tasks');
        Schema::dropIfExists('onboarding_checklists');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('staff');
    }
};
