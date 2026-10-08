<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Records hub: folder tree with role permissions, document versions, metadata tags and an access log. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('document_folders')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('position')->default(0);
            // Roles allowed to open the folder (null = inherit from parent; top level null = every staff role).
            $table->json('roles')->nullable();
            // Agents see every document here (policies, templates, training) instead of only their own.
            $table->boolean('shared_with_agents')->default(false);
            $table->foreignId('default_category_id')->nullable()->constrained('document_categories')->nullOnDelete();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('folder_id')->nullable()->after('category_id')->constrained('document_folders')->nullOnDelete();
            $table->string('description')->nullable()->after('name');
            $table->json('tags')->nullable()->after('description'); // {property, client, agent_id, year, keywords[]}
            $table->unsignedBigInteger('size_bytes')->nullable()->after('file_path');
            $table->string('mime_type', 100)->nullable()->after('size_bytes');
        });

        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('file_path');
            $table->string('original_name')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->unique(['document_id', 'version']);
        });

        Schema::create('document_access_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('action', ['uploaded', 'new_version', 'downloaded', 'renamed', 'moved', 'tagged', 'restored']);
            $table->string('detail')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->index();
        });

        // Every existing document becomes version history entry #n.
        DB::table('documents')->orderBy('id')->each(function ($d) {
            DB::table('document_versions')->insert([
                'document_id' => $d->id, 'version' => $d->version, 'file_path' => $d->file_path, 'original_name' => $d->name,
                'uploaded_by' => $d->uploaded_by, 'created_at' => $d->created_at ?? now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_access_log');
        Schema::dropIfExists('document_versions');
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('folder_id');
            $table->dropColumn(['description', 'tags', 'size_bytes', 'mime_type']);
        });
        Schema::dropIfExists('document_folders');
    }
};
