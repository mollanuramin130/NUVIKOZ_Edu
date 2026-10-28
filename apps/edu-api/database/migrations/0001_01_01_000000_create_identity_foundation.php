<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('name', 160);
            $t->string('password');
            $t->string('status', 20)->default('active');
            $t->boolean('is_platform_admin')->default(false);
            $t->boolean('must_change_password')->default(false);
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::create('schools', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('code', 32)->unique();
            $t->string('name', 160);
            $t->enum('data_stage', ['demo', 'live'])->default('demo');
            $t->enum('access_state', ['provisional', 'live_enabled', 'suspended', 'archived'])->default('provisional');
            $t->timestamp('demo_ends_at')->nullable();
            $t->timestamp('entitlement_ends_at')->nullable();
            $t->json('settings')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('memberships', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('school_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $t->enum('role', ['school_admin', 'principal', 'teacher', 'student', 'parent']);
            $t->string('login_identifier', 80);
            $t->string('status', 20)->default('active');
            $t->timestamps();
            $t->unique(['school_id', 'id']);
            $t->unique(['school_id', 'user_id']);
            $t->unique(['school_id', 'login_identifier']);
            $t->index(['user_id', 'status']);
        });
        Schema::create('membership_permissions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('school_id')->constrained()->restrictOnDelete();
            $t->ulid('membership_id');
            $t->string('capability', 100);
            $t->boolean('allowed');
            $t->timestamps();
            $t->unique(['school_id', 'membership_id', 'capability']);
            $t->foreign(['school_id', 'membership_id'])->references(['school_id', 'id'])->on('memberships')->restrictOnDelete();
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('school_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->string('action', 100);
            $t->ulid('resource_id');
            $t->string('request_id', 64);
            $t->timestamp('created_at');
            $t->index(['school_id', 'created_at']);
        });
        Schema::create('idempotency_records', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('school_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('operation', 80);
            $t->string('key', 160);
            $t->string('body_hash', 64);
            $t->ulid('membership_id');
            $t->timestamps();
            $t->unique(['school_id', 'actor_id', 'operation', 'key'], 'idempotency_scope_unique');
            $t->foreign(['school_id', 'membership_id'])->references(['school_id', 'id'])->on('memberships')->restrictOnDelete();
        });
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id();
            $t->ulidMorphs('tokenable');
            $t->text('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable()->index();
            $t->timestamps();
        });
        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->foreignUlid('user_id')->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        foreach (['sessions', 'idempotency_records', 'personal_access_tokens', 'audit_events', 'membership_permissions', 'memberships', 'schools', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
