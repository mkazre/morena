<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('category_id')->nullable()->constrained('document_categories')->nullOnDelete();
            $table->nullableMorphs('linked_model');
            $table->unsignedInteger('version')->default(1);
            $table->string('file_path');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('retention_expires_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('client_upload_links', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->foreignId('agent_id')->constrained('staff')->cascadeOnDelete();
            $table->string('client_name');
            $table->string('client_phone')->nullable();
            $table->string('client_email')->nullable();
            $table->foreignId('deal_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['awaiting_client', 'uploaded', 'filed', 'expired'])->default('awaiting_client');
            $table->timestamp('expires_at');
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('client_upload_link_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('link_id')->constrained('client_upload_links')->cascadeOnDelete();
            $table->string('document_label');
            $table->string('hint')->nullable();
            $table->boolean('is_required')->default(true);
            $table->foreignId('uploaded_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->enum('status', ['required', 'uploaded', 'filed'])->default('required');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_upload_link_items');
        Schema::dropIfExists('client_upload_links');
        Schema::dropIfExists('documents');
    }
};
