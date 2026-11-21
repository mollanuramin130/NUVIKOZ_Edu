<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_security_audits', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->string('action', 80);
            $t->string('request_id', 100);
            $t->timestamp('created_at');
        });
        foreach (['UPDATE', 'DELETE'] as $operation) {
            $name = 'platform_audit_no_'.strtolower($operation);
            if (DB::getDriverName() === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON platform_security_audits BEGIN SELECT RAISE(ABORT, 'Platform audit is append-only'); END");
            } elseif (DB::getDriverName() === 'mysql') {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON platform_security_audits FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Platform audit is append-only'");
            } else {
                throw new RuntimeException('Unsupported audit database.');
            }
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS platform_audit_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS platform_audit_no_delete');
        Schema::dropIfExists('platform_security_audits');
    }
};
