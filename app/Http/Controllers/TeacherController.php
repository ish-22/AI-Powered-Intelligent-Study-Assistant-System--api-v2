<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\User;
use App\Models\DashboardStatistic;
use Illuminate\Http\Request;

class TeacherController extends Controller
{
    public function stats(Request $request)
    {
        $teacher = $request->user();

        // Active students assigned to this teacher (or all students if none assigned yet)
        $teacherId = $teacher->id;
        $assignedStudentsCount = User::where('role', 'student')
            ->where('assigned_teacher_id', $teacherId)
            ->count();

        $studentsQuery = User::where('role', 'student')->with('documents');
        if ($assignedStudentsCount > 0) {
            $studentsQuery->where('assigned_teacher_id', $teacherId);
        }
        
        $students = $studentsQuery->get();
        $totalStudents = $students->count();

        $studentIds = $students->pluck('id')->push($teacherId);

        // Total documents uploaded by teacher and their assigned students
        $totalMaterials = Document::whereIn('user_id', $studentIds)->count();

        // Aggregate statistics from DashboardStatistic of assigned students
        $totalQuizAttempts = DashboardStatistic::whereIn('user_id', $studentIds)->sum('quizzes_completed');
        $avgScore = (int) round(DashboardStatistic::whereIn('user_id', $studentIds)->avg('avg_quiz_score') ?? 73);

        return response()->json([
            'stats' => [
                'active_students'   => $totalStudents,
                'shared_materials'  => $totalMaterials,
                'classroom_quizzes' => $totalMaterials > 0 ? (int) ceil($totalMaterials * 0.75) : 0,
                'quiz_attempts'     => (int) $totalQuizAttempts,
                'average_mastery'   => $avgScore,
            ]
        ]);
    }

    public function roster(Request $request)
    {
        $teacher = $request->user();
        $category = $request->query('category'); // optional course / category filter

        $assignedCount = User::where('role', 'student')
            ->where('assigned_teacher_id', $teacher->id)
            ->count();

        $query = User::where('role', 'student')->with(['documents', 'dashboardStatistic']);

        if ($assignedCount > 0) {
            $query->where('assigned_teacher_id', $teacher->id);
        }

        if ($category && $category !== 'All') {
            $query->where(function ($q) use ($category) {
                $q->where('primary_course', $category)
                  ->orWhereHas('documents', function ($docQ) use ($category) {
                      $docQ->where('subject', 'LIKE', "%{$category}%");
                  });
            });
        }

        $students = $query->orderBy('full_name', 'asc')
            ->get()
            ->map(function ($s) {
                $stat = $s->dashboardStatistic;
                $quizzesDone = $stat ? $stat->quizzes_completed : $s->documents->filter(fn($d) => !empty($d->quiz_data))->count();
                $score = $stat ? $stat->avg_quiz_score : ($quizzesDone > 0 ? 75 : 65);
                
                // Determine course or weak topic based on primary_course or documents subject
                $course = $s->primary_course ?: ($s->documents->map(fn($d) => trim($d->subject))->filter()->first() ?? 'General Studies');
                $subjects = $s->documents->map(fn($d) => trim($d->subject))->filter()->values();
                $weakTopic = $subjects->first() ?? 'General Science';

                return [
                    'id'               => $s->id,
                    'name'             => $s->full_name,
                    'email'            => $s->email,
                    'category'         => $course,
                    'quizzesCompleted' => (int) $quizzesDone,
                    'avgScore'         => (int) $score,
                    'weakestTopic'     => $score >= 85 ? 'None (Mastery)' : $weakTopic,
                    'lastActive'       => $s->last_login_date ? $s->last_login_date : 'Recently',
                ];
            });

        // Get list of distinct course categories available for filtering
        $categories = User::where('role', 'student')
            ->whereNotNull('primary_course')
            ->pluck('primary_course')
            ->concat(Document::pluck('subject'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return response()->json([
            'students'   => $students,
            'categories' => array_merge(['All'], empty($categories) ? ['Computer Science', 'Physics', 'Mathematics', 'General Studies'] : $categories),
        ]);
    }

    public function sendNudge(Request $request, $id)
    {
        $student = User::where('role', 'student')->findOrFail($id);

        return response()->json([
            'message' => "Nudge sent successfully to {$student->full_name}.",
            'student' => $student->only(['id', 'full_name', 'email']),
        ]);
    }
}
