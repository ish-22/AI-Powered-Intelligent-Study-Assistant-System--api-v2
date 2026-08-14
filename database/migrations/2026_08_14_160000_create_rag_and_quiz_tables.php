<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Update documents table status enum rules & add error_message
        Schema::table('documents', function (Blueprint $table) {
            // First alter status to support various states dynamically as string to prevent enum validation blocks
            $table->string('status', 30)->default('Pending')->change();
            $table->text('error_message')->nullable()->after('status');
        });

        // 2. Create document_chunks table for RAG architecture
        Schema::create('document_chunks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained('documents')->onDelete('cascade');
            $table->text('content');
            $table->integer('page_number')->default(1);
            $table->longText('embedding')->nullable(); // Stores the 1536-dimension float array vector
            $table->timestamps();
        });

        // 3. Create quizzes table
        Schema::create('quizzes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignUuid('document_id')->nullable()->constrained('documents')->onDelete('cascade');
            $table->string('title');
            $table->string('difficulty', 20)->default('medium');
            $table->integer('time_limit_mins')->default(15);
            $table->timestamps();
        });

        // 4. Create quiz_questions table
        Schema::create('quiz_questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('quiz_id')->constrained('quizzes')->onDelete('cascade');
            $table->string('type', 30); // mcq | tf | short | blank
            $table->text('question_text');
            $table->json('options')->nullable(); // Choices for MCQ
            $table->text('correct_answer');
            $table->text('explanation')->nullable();
            $table->timestamps();
        });

        // 5. Create quiz_attempts table
        Schema::create('quiz_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('quiz_id')->constrained('quizzes')->onDelete('cascade');
            $table->foreignUuid('user_id')->constrained('users')->onDelete('cascade');
            $table->integer('score_percentage')->default(0);
            $table->integer('time_spent_seconds')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        // 6. Create quiz_attempt_answers table
        Schema::create('quiz_attempt_answers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('quiz_attempt_id')->constrained('quiz_attempts')->onDelete('cascade');
            $table->foreignUuid('quiz_question_id')->constrained('quiz_questions')->onDelete('cascade');
            $table->text('student_answer');
            $table->boolean('is_correct')->default(false);
            $table->text('ai_feedback')->nullable();
            $table->integer('score_awarded')->nullable();
            $table->timestamps();
        });

        // 7. Create flashcards table for Leitner system
        Schema::create('flashcards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignUuid('document_id')->nullable()->constrained('documents')->onDelete('cascade');
            $table->text('question');
            $table->text('answer');
            $table->integer('leitner_box')->default(1); // Leila Leitner Box tracking (1-5)
            $table->timestamp('next_review_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flashcards');
        Schema::dropIfExists('quiz_attempt_answers');
        Schema::dropIfExists('quiz_attempts');
        Schema::dropIfExists('quiz_questions');
        Schema::dropIfExists('quizzes');
        Schema::dropIfExists('document_chunks');
        
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['error_message']);
            // Revert status to enum
            $table->enum('status', ['Pending', 'Processing', 'Analyzed', 'Error'])->default('Pending')->change();
        });
    }
};
