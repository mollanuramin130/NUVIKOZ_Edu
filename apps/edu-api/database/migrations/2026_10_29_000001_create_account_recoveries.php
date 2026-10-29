<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_recoveries', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('school_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $t->ulid('membership_id');
            $t->ulid('issuer_membership_id');
            $t->foreign(['school_id', 'membership_id'])->references(['school_id', 'id'])->on('memberships')->restrictOnDelete();
            $t->foreign(['school_id', 'issuer_membership_id'])->references(['school_id', 'id'])->on('memberships')->restrictOnDelete();
            $t->string('purpose', 32);
            $t->string('secret_hash', 64);
            $t->string('authority_hash', 64);
            $t->string('subject_hash', 64);
            $t->string('verification_method', 48);
            $t->string('verification_reference_hash', 64);
            $t->timestamp('verified_at');
            $t->timestamp('expires_at');
            $t->timestamp('consumed_at')->nullable();
            $t->timestamp('invalidated_at')->nullable();
            $t->timestamps();
            $t->index(['school_id', 'membership_id', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_recoveries');
    }
};
