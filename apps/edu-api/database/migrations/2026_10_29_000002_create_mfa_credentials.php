<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mfa_credentials', function (Blueprint $t) {
            $t->foreignUlid('user_id')->primary()->constrained()->restrictOnDelete();
            $t->text('secret_encrypted');
            $t->ulid('version');
            $t->timestamp('pending_expires_at')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->unsignedBigInteger('last_counter')->nullable();
            $t->timestamps();
        });
        Schema::create('mfa_recovery_codes', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $t->string('code_hash', 64)->unique();
            $t->timestamp('consumed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_recovery_codes');
        Schema::dropIfExists('mfa_credentials');
    }
};
