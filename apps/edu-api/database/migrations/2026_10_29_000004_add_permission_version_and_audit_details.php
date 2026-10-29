<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', fn (Blueprint $t) => $t->unsignedInteger('permission_version')->default(1));
        Schema::table('audit_events', fn (Blueprint $t) => $t->json('details')->nullable());
    }

    public function down(): void
    {
        Schema::table('audit_events', fn (Blueprint $t) => $t->dropColumn('details'));
        Schema::table('memberships', fn (Blueprint $t) => $t->dropColumn('permission_version'));
    }
};
