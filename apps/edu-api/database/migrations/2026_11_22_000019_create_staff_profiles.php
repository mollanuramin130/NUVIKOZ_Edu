<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_profiles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('school_id')->constrained()->restrictOnDelete();
            $table->ulid('membership_id');
            $table->foreign(['school_id', 'membership_id'])->references(['school_id', 'id'])->on('memberships')->restrictOnDelete();
            $table->unique(['school_id', 'membership_id']);
            $table->unique(['school_id', 'id']);
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
        });
    }

    public function down(): void
    {
        if (DB::table('staff_profiles')->exists()
            || DB::table('idempotency_records')->where('operation', 'staff.profile.register')->exists()
            || DB::table('audit_events')->where('action', 'staff.profile.created')->exists()) {
            throw new RuntimeException('Preserve staff profile, receipt and audit history before rollback.');
        }
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            foreach (Schema::getForeignKeys($table) as $key) {
                if ($key['foreign_table'] === 'staff_profiles') {
                    throw new RuntimeException('A schema dependency still references staff profiles.');
                }
            }
        }
        Schema::drop('staff_profiles');
    }
};
