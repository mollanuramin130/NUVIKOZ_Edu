<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_provisionings', function (Blueprint $table): void {
            $table->foreignUlid('registration_id')->primary()->constrained('registration_requests')->restrictOnDelete();
            $table->foreignUlid('school_id')->unique()->constrained('schools')->restrictOnDelete();
            $table->foreignUlid('membership_id')->unique()->constrained('memberships')->restrictOnDelete();
            $table->foreignUlid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('command_id');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        if (DB::table('school_provisionings')->exists()) {
            throw new RuntimeException('Provisioned identities require an explicit preservation plan before rollback.');
        }

        Schema::drop('school_provisionings');
    }
};
