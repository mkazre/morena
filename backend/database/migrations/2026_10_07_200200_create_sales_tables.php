<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_stages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedSmallInteger('position');
            $table->string('color_hex', 7)->default('#c23552');
            $table->boolean('is_default_seed')->default(false);
            $table->timestamps();
        });

        Schema::create('pipeline_stage_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipeline_stage_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_template_id')->constrained()->cascadeOnDelete();
            // Explicit name: the auto-generated one is 70 chars, over MySQL's 64-char identifier limit.
            $table->unique(['pipeline_stage_id', 'document_template_id'], 'stage_documents_unique');
        });

        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('suburb');
            $table->decimal('price', 14, 2);
            $table->enum('status', ['active', 'under_offer', 'sold'])->default('active');
            $table->enum('property_type', ['house', 'sectional_title', 'townhouse', 'vacant_land', 'commercial'])->default('house');
            $table->unsignedTinyInteger('beds')->default(0);
            $table->unsignedTinyInteger('baths')->default(0);
            $table->foreignId('agent_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('photo_path')->nullable();
            $table->date('listed_at')->nullable();
            $table->date('mandate_expiry')->nullable();
            $table->timestamps();
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('interest_description')->nullable();
            $table->enum('source', ['referral', 'website', 'whatsapp', 'walk_in', 'portal']);
            $table->foreignId('assigned_agent_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->enum('status', ['new', 'contacted', 'qualified', 'converted', 'lost'])->default('new');
            $table->timestamps();
        });

        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('property_title');
            $table->decimal('sale_value', 14, 2);
            $table->foreignId('pipeline_stage_id')->constrained()->restrictOnDelete();
            $table->foreignId('listing_agent_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('selling_agent_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('referral_agent_id')->nullable()->constrained('staff')->nullOnDelete();
            // Per-deal override of commission_settings.co_listing_split_default_percent (listing agent's portion).
            $table->decimal('co_listing_split_percent', 5, 2)->nullable();
            $table->string('buyer_name')->nullable();
            $table->string('seller_name')->nullable();
            // Seller's landlord-portal login, so landlord/Dashboard can show "Your property sale".
            $table->foreignId('seller_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('mandate_type', ['sole', 'open'])->default('sole');
            $table->boolean('bond_required')->default(false);
            $table->unsignedTinyInteger('bond_ltv_percent')->nullable();
            $table->date('expected_close_date')->nullable();
            $table->enum('status', ['open', 'closed', 'lost'])->default('open');
            $table->enum('lost_reason', ['bond_declined', 'price_disagreement', 'buyer_withdrew', 'chose_another_property', 'seller_withdrew_mandate'])->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('deal_stage_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pipeline_stage_id')->constrained()->cascadeOnDelete();
            $table->timestamp('entered_at');
            $table->timestamp('exited_at')->nullable();
        });

        Schema::create('deal_activity_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('text');
            $table->timestamp('created_at');
        });

        Schema::create('commission_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('base_rate_percent', 5, 2)->default(6.0);
            $table->boolean('vat_registered')->default(true);
            $table->decimal('vat_rate', 5, 2)->default(15.0);
            $table->decimal('co_listing_split_default_percent', 5, 2)->default(50);
            $table->decimal('referral_fee_percent', 5, 2)->default(10);
            $table->timestamps();
        });

        Schema::create('commission_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('min_deals')->nullable();
            $table->decimal('min_volume', 14, 2)->nullable();
            $table->decimal('agent_split_percent', 5, 2);
            $table->decimal('agency_split_percent', 5, 2);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->decimal('sale_value', 14, 2);
            $table->decimal('commission_amount', 14, 2);
            $table->decimal('vat_amount', 14, 2)->default(0);
            // agent_share is the payout to agent_id after any co-listing split and referral deduction.
            $table->decimal('agent_share', 14, 2);
            $table->foreignId('co_agent_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->decimal('co_agent_share', 14, 2)->default(0);
            $table->foreignId('referral_agent_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->decimal('referral_fee_amount', 14, 2)->default(0);
            $table->decimal('agency_share', 14, 2);
            $table->foreignId('commission_tier_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['pending', 'paid'])->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions');
        Schema::dropIfExists('commission_tiers');
        Schema::dropIfExists('commission_settings');
        Schema::dropIfExists('deal_activity_log');
        Schema::dropIfExists('deal_stage_history');
        Schema::dropIfExists('deals');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('listings');
        Schema::dropIfExists('pipeline_stage_documents');
        Schema::dropIfExists('pipeline_stages');
    }
};
