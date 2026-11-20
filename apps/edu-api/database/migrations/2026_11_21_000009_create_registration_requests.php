<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tracking_hash', 64)->unique();
            $table->char('duplicate_key', 64)->unique();
            $table->string('organization_name', 160);
            $table->string('category', 40);
            $table->string('contact_name', 100);
            $table->string('contact', 160);
            $table->string('notice_version', 32);
            $table->string('state', 16)->default('submitted');
            $table->timestamp('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_requests');
    }
};
