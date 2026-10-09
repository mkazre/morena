<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** System name (footer, emails, browser titles) and the sign-in page's right-hand panel design. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('system_name', 80)->default('Morena CRM')->after('company_name');
            $table->string('login_bg_image')->nullable()->after('sidebar_logo_width');
            // {type: linear|radial, angle: 0-360, stops: [{color: "#1c1a19", opacity: 0-100, position: 0-100}]}
            $table->json('login_gradient')->nullable()->after('login_bg_image');
            $table->string('login_headline', 200)->nullable()->after('login_gradient');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['system_name', 'login_bg_image', 'login_gradient', 'login_headline']);
        });
    }
};
