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
        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('criticality')->default('P1');
            $table->string('origin')->default('authored');
            $table->string('status')->default('candidate');
            $table->unsignedTinyInteger('score')->nullable();
            $table->boolean('verified')->default(false);
            $table->boolean('canary')->default(false);
            $table->string('latest_verdict')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->string('ignored_reason')->nullable();
            $table->json('steps')->nullable();
            $table->json('spark_data')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflows');
    }
};
