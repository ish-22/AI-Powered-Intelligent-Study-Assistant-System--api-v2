<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add full teacher/student flow fields to quizzes table
        Schema::table('quizzes', function (Blueprint $table) {
            $table->text('description')->nullable()->after('title');
            $table->string('status', 30)->default('DRAFT')->after('time_limit_mins'); // DRAFT | PUBLISHED | CLOSED | GRADING | GRADED
            $table->timestamp('start_at')->nullable()->after('status');
            $table->timestamp('deadline_at')->nullable()->after('start_at');
            $table->integer('total_marks')->default(100)->after('deadline_at');
            $table->boolean('show_results_immediately')->default(true)->after('total_marks');
            $table->json('material_ids')->nullable()->after('document_id'); // Support multiple materials
        });

        // Add marks to quiz_questions table
        Schema::table('quiz_questions', function (Blueprint $table) {
            $table->integer('marks')->default(10)->after('type');
        });

        // Add status and grade details to quiz_attempts table
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->string('status', 30)->default('IN_PROGRESS')->after('user_id'); // IN_PROGRESS | SUBMITTED | UNDER_REVIEW | GRADED
            $table->integer('total_marks_obtained')->default(0)->after('score_percentage');
            $table->string('grade', 10)->nullable()->after('total_marks_obtained');
            $table->text('teacher_feedback')->nullable()->after('grade');
            $table->timestamp('started_at')->nullable()->after('teacher_feedback');
        });

        // Add teacher feedback & manual marks to quiz_attempt_answers table
        Schema::table('quiz_attempt_answers', function (Blueprint $table) {
            $table->text('teacher_feedback')->nullable()->after('ai_feedback');
            $table->boolean('is_manually_graded')->default(false)->after('teacher_feedback');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_attempt_answers', function (Blueprint $table) {
            $table->dropColumn(['teacher_feedback', 'is_manually_graded']);
        });

        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->dropColumn(['status', 'total_marks_obtained', 'grade', 'teacher_feedback', 'started_at']);
        });

        Schema::table('quiz_questions', function (Blueprint $table) {
            $table->dropColumn(['marks']);
        });

        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropColumn([
                'description',
                'status',
                'start_at',
                'deadline_at',
                'total_marks',
                'show_results_immediately',
                'material_ids',
            ]);
        });
    }
};
