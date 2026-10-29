<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_revocations', function (Blueprint $t) {
            $t->string('session_hash', 64)->primary();
            $t->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_revocations');
    }
};
