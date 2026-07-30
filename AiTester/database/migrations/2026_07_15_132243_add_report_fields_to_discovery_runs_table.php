<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('discovery_runs', function (Blueprint $table) {
            $table->boolean('login_form_detected')->nullable()->after('authenticated');
            $table->boolean('register_form_detected')->nullable()->after('login_form_detected');
            $table->unsignedInteger('forms_found')->nullable()->after('register_form_detected');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('discovery_runs', function (Blueprint $table) {
            $table->dropColumn(['login_form_detected', 'register_form_detected', 'forms_found']);
        });
    }
};
