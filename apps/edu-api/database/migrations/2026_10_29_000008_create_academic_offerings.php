<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_offerings', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('school_id')->constrained()->restrictOnDelete();
            $t->ulid('session_id');
            $t->ulid('class_id');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['school_id', 'id']);
            $t->unique(['school_id', 'session_id', 'class_id']);
            $t->foreign(['school_id', 'session_id'])->references(['school_id', 'id'])->on('academic_sessions')->restrictOnDelete();
            $t->foreign(['school_id', 'class_id'])->references(['school_id', 'id'])->on('classes')->restrictOnDelete();
        });
        Schema::create('sections', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('school_id')->constrained()->restrictOnDelete();
            $t->ulid('class_offering_id');
            $t->string('name', 40);
            // Assignment remains null until effective-dated staff scope is implemented.
            $t->ulid('class_teacher_membership_id')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['school_id', 'id']);
            $t->unique(['school_id', 'class_offering_id', 'name']);
            $t->foreign(['school_id', 'class_offering_id'])->references(['school_id', 'id'])->on('class_offerings')->restrictOnDelete();
            $t->foreign(['school_id', 'class_teacher_membership_id'])->references(['school_id', 'id'])->on('memberships')->restrictOnDelete();
        });
        Schema::create('subjects', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('school_id')->constrained()->restrictOnDelete();
            $t->string('code', 32);
            $t->string('name', 100);
            $t->timestamp('archived_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['school_id', 'id']);
            $t->unique(['school_id', 'code']);
        });
        Schema::create('subject_offerings', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('school_id')->constrained()->restrictOnDelete();
            $t->ulid('class_offering_id');
            $t->ulid('subject_id');
            $t->boolean('compulsory');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['school_id', 'id']);
            $t->unique(['school_id', 'class_offering_id', 'subject_id']);
            $t->foreign(['school_id', 'class_offering_id'])->references(['school_id', 'id'])->on('class_offerings')->restrictOnDelete();
            $t->foreign(['school_id', 'subject_id'])->references(['school_id', 'id'])->on('subjects')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_offerings');
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('sections');
        Schema::dropIfExists('class_offerings');
    }
};
