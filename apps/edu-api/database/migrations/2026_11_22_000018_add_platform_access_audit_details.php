<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_security_audits', function (Blueprint $table): void {
            $table->json('details')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('platform_security_audits')->whereNotNull('details')->exists()) {
            throw new RuntimeException('Preserve Platform access audit history before rollback.');
        }

        Schema::table('platform_security_audits', function (Blueprint $table): void {
            $table->dropColumn('details');
        });
    }
};
