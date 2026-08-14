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
        Schema::table('runs', function (Blueprint $table) {
            // Nullable + nullOnDelete (unlike environments/discovery_runs' cascade
            // FKs): a Run is a historical record of what actually happened and
            // should survive its environment being deleted later, not vanish with it.
            $table->foreignId('environment_id')->nullable()->after('workflow_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('environment_id');
        });
    }
};
