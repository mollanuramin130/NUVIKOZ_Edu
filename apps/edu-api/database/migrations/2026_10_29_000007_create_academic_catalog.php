<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_sessions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('school_id')->constrained()->restrictOnDelete();
            $t->string('name', 60);
            $t->date('starts_on');
            $t->date('ends_on');
            $t->enum('state', ['future', 'active', 'closed', 'archived'])->default('future');
            // Global versioned defaults are not implemented; never invent an inherited version.
            $t->unsignedInteger('rule_version')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['school_id', 'id']);
            $t->unique(['school_id', 'name']);
            $t->index(['school_id', 'state', 'starts_on']);
        });
        Schema::create('classes', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('school_id')->constrained()->restrictOnDelete();
            $t->string('name', 80);
            $t->string('grade_label', 32)->nullable();
            $t->unsignedInteger('sort_order');
            $t->timestamp('archived_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['school_id', 'id']);
            $t->unique(['school_id', 'name']);
        });
        Schema::create('academic_command_receipts', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('school_id')->constrained()->restrictOnDelete();
            $t->ulid('membership_id');
            $t->string('operation', 40);
            $t->string('key', 160);
            $t->string('body_hash', 64);
            $t->ulid('resource_id');
            $t->timestamps();
            $t->unique(['school_id', 'id']);
            $t->unique(['school_id', 'membership_id', 'operation', 'key'], 'academic_command_scope_unique');
            $t->foreign(['school_id', 'membership_id'])->references(['school_id', 'id'])->on('memberships')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_command_receipts');
        Schema::dropIfExists('classes');
        Schema::dropIfExists('academic_sessions');
    }
};
