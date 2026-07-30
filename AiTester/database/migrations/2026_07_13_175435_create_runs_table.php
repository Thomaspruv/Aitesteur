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
        Schema::create('runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->string('verdict');
            $table->unsignedTinyInteger('escalation_level')->nullable();
            $table->string('triggered_by')->default('deploy');
            $table->boolean('confirmed')->default(false);
            $table->string('expected_label')->nullable();
            $table->string('observed_label')->nullable();
            $table->text('diagnostic_summary')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('runs');
    }
};
