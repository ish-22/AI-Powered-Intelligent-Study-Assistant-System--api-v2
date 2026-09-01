<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Document;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Chat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FullApiEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_endpoints_work_correctly(): void
    {
        Storage::fake('public');

        // 1. Auth: Register
        $regData = [
            'full_name' => 'Test Student',
            'email' => 'student@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'student',
        ];
        $regRes = $this->postJson('/api/auth/register', $regData);
        $regRes->assertStatus(201);
        $token = $regRes->json('token');
        $student = User::where('email', 'student@example.com')->first();

        // 2. Auth: Login
        $loginRes = $this->postJson('/api/auth/login', [
            'email' => 'student@example.com',
            'password' => 'Password123!',
        ]);
        $loginRes->assertStatus(200);

        // 3. Auth: Me
        $meRes = $this->withToken($token)->getJson('/api/auth/me');
        $meRes->assertStatus(200)->assertJsonPath('user.email', 'student@example.com');

        // 4. Profile: Show & Update
        $profShow = $this->withToken($token)->getJson('/api/profile');
        $profShow->assertStatus(200);

        $profUp = $this->withToken($token)->patchJson('/api/profile', [
            'about_me' => 'Learning enthusiast',
            'primary_course' => 'Computer Science',
        ]);
        $profUp->assertStatus(200);

        // 5. Dashboard Stats
        $dashRes = $this->withToken($token)->getJson('/api/dashboard/stats');
        $dashRes->assertStatus(200);

        $dashPatch = $this->withToken($token)->patchJson('/api/dashboard/stats', [
            'quizzes_completed' => 5,
        ]);
        $dashPatch->assertStatus(200);

        // 6. Document Upload, List, Summary, Quiz, Delete
        $file = UploadedFile::fake()->createWithContent('lecture.txt', "This is a lecture on computer science, algorithms, data structures, and systemic architecture.");
        $docUp = $this->withToken($token)->postJson('/api/documents', [
            'file' => $file,
            'subject' => 'Computer Science',
        ]);
        $docUp->assertStatus(201);
        $docId = $docUp->json('document.id');

        $docList = $this->withToken($token)->getJson('/api/documents');
        $docList->assertStatus(200);

        $summaryRes = $this->withToken($token)->postJson("/api/documents/{$docId}/summary");
        $summaryRes->assertStatus(200);

        $docQuizRes = $this->withToken($token)->postJson("/api/documents/{$docId}/quiz", [
            'count' => 3,
            'difficulty' => 'medium',
        ]);
        $docQuizRes->assertStatus(200);

        // 7. Recommendations
        $recRes = $this->withToken($token)->postJson('/api/recommendations/generate');
        $recRes->assertStatus(200);

        // 8. Chat Endpoints
        $chatStore = $this->withToken($token)->postJson('/api/chats', [
            'document_id' => $docId,
        ]);
        $chatStore->assertStatus(201);
        $chatId = $chatStore->json('chat.id');

        $chatsList = $this->withToken($token)->getJson('/api/chats');
        $chatsList->assertStatus(200);

        $msgsList = $this->withToken($token)->getJson("/api/chats/{$chatId}/messages");
        $msgsList->assertStatus(200);

        $sendMsg = $this->withToken($token)->postJson("/api/chats/{$chatId}/message", [
            'message' => 'Explain the key terms in this document.',
        ]);
        $sendMsg->assertStatus(200);

        // Cleanup
        $this->withToken($token)->deleteJson("/api/chats/{$chatId}")->assertStatus(200);
        $this->withToken($token)->deleteJson("/api/documents/{$docId}")->assertStatus(200);
        $this->withToken($token)->postJson('/api/auth/logout')->assertStatus(200);
    }

    public function test_teacher_and_quiz_management_endpoints_work_correctly(): void
    {
        Storage::fake('public');

        // Create student & teacher
        $student = User::factory()->create(['role' => 'student']);
        $studentToken = $student->createToken('auth')->plainTextToken;

        $teacher = User::factory()->create(['role' => 'teacher', 'is_approved' => true]);
        $teacherToken = $teacher->createToken('auth')->plainTextToken;

        // Teacher Stats & Roster
        $this->withToken($teacherToken)->getJson('/api/teacher/stats')->assertStatus(200);
        $this->withToken($teacherToken)->getJson('/api/teacher/roster')->assertStatus(200);
        $this->withToken($teacherToken)->postJson("/api/teacher/nudge/{$student->id}")->assertStatus(200);

        // Teacher Uploads Document
        $tFile = UploadedFile::fake()->createWithContent('teacher_lecture.txt', "Advanced computer science algorithms, memory allocation, and systemic optimization.");
        $tDocUp = $this->withToken($teacherToken)->postJson('/api/documents', [
            'file' => $tFile,
            'subject' => 'Computer Science',
        ]);
        $tDocUp->assertStatus(201);
        $tDocId = $tDocUp->json('document.id');

        // Teacher Generates Quiz
        $quizGen = $this->withToken($teacherToken)->postJson('/api/quizzes/generate', [
            'document_ids' => [$tDocId],
            'count' => 3,
            'question_type' => 'mcq',
            'difficulty' => 'medium',
        ]);
        $quizGen->assertStatus(201);
        $quizId = $quizGen->json('quiz.id');

        // Student Views Quiz List
        $this->withToken($studentToken)->getJson('/api/quizzes')->assertStatus(200);

        // Teacher publishes quiz
        $this->withToken($teacherToken)->putJson("/api/quizzes/{$quizId}", [
            'status' => 'PUBLISHED',
        ])->assertStatus(200);

        // Student starts & submits quiz
        $startRes = $this->withToken($studentToken)->postJson("/api/quizzes/{$quizId}/start");
        $startRes->assertStatus(200);
        $attemptId = $startRes->json('attempt_id');
        $qId = $startRes->json('questions.0.id');

        $submitRes = $this->withToken($studentToken)->postJson("/api/quizzes/attempts/{$attemptId}/submit", [
            'answers' => [
                ['quiz_question_id' => $qId, 'answer' => 'A'],
            ],
        ]);
        $submitRes->assertStatus(200);

        $resultRes = $this->withToken($studentToken)->getJson("/api/quizzes/attempts/{$attemptId}/result");
        $resultRes->assertStatus(200);

        // Teacher views submissions & grades attempt
        $subsRes = $this->withToken($teacherToken)->getJson("/api/quizzes/{$quizId}/submissions");
        $subsRes->assertStatus(200);

        $attemptAnsId = \App\Models\QuizAttemptAnswer::where('quiz_attempt_id', $attemptId)->first()->id;

        $gradeRes = $this->withToken($teacherToken)->postJson("/api/quizzes/attempts/{$attemptId}/grade", [
            'answers' => [
                [
                    'id' => $attemptAnsId,
                    'score' => 10,
                    'is_correct' => true,
                    'teacher_feedback' => 'Great choice!',
                ],
            ],
            'finalize' => true,
        ]);
        $gradeRes->assertStatus(200);

        // Cleanup Quiz
        $this->withToken($teacherToken)->deleteJson("/api/quizzes/{$quizId}")->assertStatus(200);
    }
}
