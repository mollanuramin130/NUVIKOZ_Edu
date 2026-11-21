<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_admin_activation_proofs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('registration_id')->constrained('school_provisionings', 'registration_id')->restrictOnDelete();
            $table->foreignUlid('actor_id')->constrained('users')->restrictOnDelete();
            $table->uuid('command_id');
            $table->unsignedInteger('sequence');
            $table->string('operation', 10);
            $table->string('state', 20);
            $table->char('secret_hash', 64)->nullable();
            $table->char('recipient_hash', 64)->nullable();
            $table->text('delivery_envelope')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at');
            $table->unique(['registration_id', 'command_id']);
            $table->unique(['registration_id', 'sequence']);
            $table->index(['state', 'expires_at']);
        });
    }

    public function down(): void
    {
        if (DB::table('school_admin_activation_proofs')->exists()) {
            throw new RuntimeException('Activation records require preservation before rollback.');
        }

        Schema::drop('school_admin_activation_proofs');
    }
};
