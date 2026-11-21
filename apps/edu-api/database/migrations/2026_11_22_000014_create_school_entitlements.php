<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_entitlement_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('school_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('actor_id')->constrained('users')->restrictOnDelete();
            $table->uuid('command_id');
            $table->unsignedInteger('sequence');
            $table->string('action', 20);
            $table->string('policy', 24)->nullable();
            $table->unsignedSmallInteger('term_days')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->unique(['school_id', 'command_id']);
            $table->unique(['school_id', 'sequence']);
        });
        Schema::create('school_pdf_admissions', function (Blueprint $table): void {
            $table->foreignUlid('school_id')->constrained()->restrictOnDelete();
            $table->uuid('command_id');
            $table->unsignedInteger('bytes');
            $table->unsignedTinyInteger('active_delta');
            $table->string('document_id', 26);
            $table->date('school_date');
            $table->primary(['school_id', 'command_id']);
            $table->index(['school_id', 'school_date']);
            $table->index(['school_id', 'document_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('school_pdf_admissions')->exists() || DB::table('school_entitlement_events')->exists()) {
            throw new RuntimeException('Preserve existing entitlement and quota history before rollback.');
        }
        Schema::drop('school_pdf_admissions');
        Schema::drop('school_entitlement_events');
    }
};
