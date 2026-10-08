<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('category');
            $table->unsignedInteger('duration_minutes')->default(0);
            $table->timestamps();
        });

        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->unsignedInteger('duration_minutes')->default(0);
            $table->unsignedSmallInteger('order')->default(0);
            $table->timestamps();
        });

        Schema::create('course_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->enum('status', ['not_started', 'in_progress', 'complete'])->default('not_started');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['course_id', 'staff_id']);
        });

        Schema::create('lesson_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->timestamp('completed_at');
            $table->unique(['lesson_id', 'staff_id']);
        });

        Schema::create('certifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->unique()->constrained('staff')->cascadeOnDelete();
            $table->string('ffc_number')->nullable();
            $table->date('ffc_expiry')->nullable();
            $table->unsignedSmallInteger('cpd_points')->default(0);
            $table->unsignedSmallInteger('cpd_target')->default(10);
            $table->enum('status', ['compliant', 'behind', 'pending'])->default('pending');
            $table->timestamps();
        });

        // Singleton row (id = 1) — organisation-wide settings for admin/Settings.
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('company_name')->default('Morena Properties');
            $table->string('vat_number')->nullable();
            $table->unsignedSmallInteger('sidebar_logo_width')->default(170);
            $table->boolean('payprop_connected')->default(false);
            $table->boolean('whatsapp_connected')->default(false);
            $table->string('support_whatsapp')->nullable();
            $table->timestamps();
        });

        // Minimal tenancy record so tenant/* portal pages have something to show.
        // Full rentals stay in PayProp (handover §9).
        Schema::create('tenancies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('property_title');
            $table->date('lease_start')->nullable();
            $table->date('lease_end')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenancies');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('certifications');
        Schema::dropIfExists('lesson_completions');
        Schema::dropIfExists('course_completions');
        Schema::dropIfExists('lessons');
        Schema::dropIfExists('courses');
    }
};
