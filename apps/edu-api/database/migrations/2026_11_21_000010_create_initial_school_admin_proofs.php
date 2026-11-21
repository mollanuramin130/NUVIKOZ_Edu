<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('initial_school_admin_proofs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('school_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignUlid('user_id')->unique()->constrained()->restrictOnDelete();
            $table->ulid('membership_id');
            $table->foreign(['school_id', 'membership_id'])->references(['school_id', 'id'])->on('memberships')->restrictOnDelete();
            $table->char('secret_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('initial_school_admin_proofs');
    }
};
