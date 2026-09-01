<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations to speed up queries.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->index(['user_id', 'created_at'], 'idx_docs_user_created');
        });

        Schema::table('chats', function (Blueprint $table) {
            $table->index(['user_id', 'updated_at'], 'idx_chats_user_updated');
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->index(['chat_id', 'created_at'], 'idx_messages_chat_created');
        });

        if (Schema::hasTable('quizzes')) {
            Schema::table('quizzes', function (Blueprint $table) {
                $table->index(['user_id', 'status'], 'idx_quizzes_user_status');
            });
        }

        if (Schema::hasTable('quiz_attempts')) {
            Schema::table('quiz_attempts', function (Blueprint $table) {
                $table->index(['user_id', 'quiz_id'], 'idx_attempts_user_quiz');
                $table->index(['quiz_id', 'status'], 'idx_attempts_quiz_status');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex('idx_docs_user_created');
        });

        Schema::table('chats', function (Blueprint $table) {
            $table->dropIndex('idx_chats_user_updated');
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropIndex('idx_messages_chat_created');
        });

        if (Schema::hasTable('quizzes')) {
            Schema::table('quizzes', function (Blueprint $table) {
                $table->dropIndex('idx_quizzes_user_status');
            });
        }

        if (Schema::hasTable('quiz_attempts')) {
            Schema::table('quiz_attempts', function (Blueprint $table) {
                $table->dropIndex('idx_attempts_user_quiz');
                $table->dropIndex('idx_attempts_quiz_status');
            });
        }
    }
};
