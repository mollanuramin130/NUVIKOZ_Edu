<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registration_requests', function (Blueprint $table): void {
            $table->index(['state', 'id']);
        });

        Schema::create('registration_reviews', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('registration_id')->constrained('registration_requests')->restrictOnDelete();
            $table->foreignUlid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('from_state', 16);
            $table->string('to_state', 16);
            $table->string('note', 1000);
            $table->uuid('command_id');
            $table->string('request_id', 100);
            $table->timestamp('created_at');
            $table->unique(['registration_id', 'command_id']);
            $table->index(['registration_id', 'id']);
        });

        foreach (['UPDATE', 'DELETE'] as $operation) {
            $name = 'registration_review_no_'.strtolower($operation);
            if (DB::getDriverName() === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON registration_reviews BEGIN SELECT RAISE(ABORT, 'Registration review is append-only'); END");
            } elseif (DB::getDriverName() === 'mysql') {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON registration_reviews FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Registration review is append-only'");
            } else {
                throw new RuntimeException('Unsupported audit database.');
            }
        }
    }

    public function down(): void
    {
        if (DB::table('registration_reviews')->exists()) {
            throw new RuntimeException('Registration review history requires an explicit preservation plan before rollback.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS registration_review_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS registration_review_no_delete');
        Schema::dropIfExists('registration_reviews');
        Schema::table('registration_requests', function (Blueprint $table): void {
            $table->dropIndex(['state', 'id']);
        });
    }
};
