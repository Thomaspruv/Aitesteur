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
            $table->unsignedInteger('popups_found')->nullable()->after('forms_found');
            $table->unsignedInteger('modals_found')->nullable()->after('popups_found');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('discovery_runs', function (Blueprint $table) {
            $table->dropColumn(['popups_found', 'modals_found']);
        });
    }
};
