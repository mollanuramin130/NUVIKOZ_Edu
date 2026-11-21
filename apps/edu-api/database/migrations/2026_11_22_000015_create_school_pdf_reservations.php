<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_pdf_reservations', function (Blueprint $table): void {
            $table->foreignUlid('school_id')->constrained()->restrictOnDelete();
            $table->uuid('command_id');
            $table->foreignUlid('actor_id')->constrained('users')->restrictOnDelete();
            $table->ulid('document_id');
            $table->unsignedInteger('bytes');
            $table->unsignedTinyInteger('active_delta');
            $table->date('school_date');
            $table->string('timezone', 64);
            $table->string('state', 12);
            $table->timestamp('expires_at');
            $table->unsignedInteger('settled_bytes')->nullable();
            $table->primary(['school_id', 'command_id']);
            $table->index(['school_id', 'state', 'expires_at']);
        });
    }

    public function down(): void
    {
        if (DB::table('school_pdf_reservations')->exists()) {
            throw new RuntimeException('Preserve quota reservation history before rollback.');
        }
        Schema::drop('school_pdf_reservations');
    }
};
