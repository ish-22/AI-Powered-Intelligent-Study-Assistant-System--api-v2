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
        Schema::create('dashboard_overviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->json('weekly_progress')->nullable();
            $table->json('quiz_trend')->nullable();
            $table->json('subject_performance')->nullable();
            $table->json('weak_topics')->nullable();
            $table->json('strong_topics')->nullable();
            $table->json('study_goals')->nullable();
            $table->json('recent_activities')->nullable();
            $table->json('recommendations')->nullable();
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
        Schema::dropIfExists('dashboard_overviews');
    }
};
