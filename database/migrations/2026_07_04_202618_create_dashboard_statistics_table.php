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
        Schema::create('dashboard_statistics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->integer('documents_uploaded')->default(0);
            $table->integer('summaries_generated')->default(0);
            $table->integer('quizzes_completed')->default(0);
            $table->integer('avg_quiz_score')->default(0);
            $table->integer('study_time_hours')->default(0);
            $table->integer('learning_streak')->default(0);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dashboard_statistics');
    }
};
