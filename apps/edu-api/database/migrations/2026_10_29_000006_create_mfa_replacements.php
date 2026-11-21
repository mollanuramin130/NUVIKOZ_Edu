<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mfa_replacements', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('user_id')->unique()->constrained()->restrictOnDelete();
            $t->enum('context', ['school', 'platform']);
            $t->string('binding', 64);
            $t->string('session_hash', 64);
            $t->ulid('old_version');
            $t->text('secret_encrypted');
            $t->timestamp('expires_at')->index();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_replacements');
    }
};
