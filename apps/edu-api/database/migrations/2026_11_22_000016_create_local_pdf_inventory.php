<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('local_pdf_capacity', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
        });
        DB::table('local_pdf_capacity')->insert(['id' => 1]);
        Schema::create('local_pdf_objects', function (Blueprint $table): void {
            $table->char('id', 48)->primary();
            $table->foreignUlid('school_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('actor_id')->constrained('users')->restrictOnDelete();
            $table->ulid('document_id');
            $table->uuid('command_id');
            $table->unsignedInteger('bytes');
            $table->string('state', 12);
            $table->char('sha256', 64)->nullable();
            $table->timestamp('expires_at');
            $table->unique(['school_id', 'command_id']);
            $table->index(['state', 'expires_at']);
        });
    }

    public function down(): void
    {
        if (DB::table('local_pdf_objects')->exists()) {
            throw new RuntimeException('Preserve local PDF inventory before rollback.');
        }
        Schema::drop('local_pdf_objects');
        Schema::drop('local_pdf_capacity');
    }
};
