<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_change_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('school_id')->constrained()->restrictOnDelete();
            $table->ulid('session_id');
            $table->foreign(['school_id', 'session_id'])->references(['school_id', 'id'])->on('academic_sessions')->restrictOnDelete();
            $table->foreignUlid('requester_id')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('reviewer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->date('proposed_start');
            $table->date('proposed_end');
            $table->unsignedInteger('session_version');
            $table->unsignedInteger('version')->default(1);
            $table->string('state', 12);
            $table->text('reason');
            $table->json('impact');
            $table->string('submit_key', 160);
            $table->char('submit_hash', 64);
            $table->string('review_key', 160)->nullable();
            $table->char('review_hash', 64)->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'session_id', 'requester_id', 'submit_key'], 'session_change_submit_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('session_change_requests')->exists()) {
            throw new RuntimeException('Preserve session change history before rollback.');
        }

        Schema::drop('session_change_requests');
    }
};
