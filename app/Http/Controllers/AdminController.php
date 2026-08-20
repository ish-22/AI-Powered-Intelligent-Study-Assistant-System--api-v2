<?php

namespace App\Http\Controllers;

use App\Models\DashboardStatistic;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        // Find admin user by username stored in full_name or a dedicated admin account
        $user = User::where('role', 'admin')
            ->where(function ($query) use ($data) {
                $query->where('email', $data['username'])
                      ->orWhere('full_name', $data['username']);
            })
            ->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'username' => ['Invalid admin credentials.'],
            ]);
        }

        $user->update(['last_login_date' => now()]);
        $token = $user->createToken('admin_token', ['admin'])->plainTextToken;

        return response()->json([
            'user'  => $this->formatAdmin($user),
            'token' => $token,
        ]);
    }

    public function stats()
    {
        $totalUsers     = User::count();
        $totalDocuments = Document::count();
        $totalSummaries = DashboardStatistic::sum('summaries_generated');
        $totalQuizzes   = DashboardStatistic::sum('quizzes_completed');
        $avgScore       = DashboardStatistic::avg('avg_quiz_score') ?? 0;
        $totalHours     = DashboardStatistic::sum('study_time_hours');

        return response()->json([
            'stats' => [
                'total_users'       => $totalUsers,
                'total_documents'   => (int) $totalDocuments,
                'total_summaries'   => (int) $totalSummaries,
                'total_quizzes'     => (int) $totalQuizzes,
                'avg_score'         => (int) round($avgScore),
                'total_study_hours' => (int) $totalHours,
            ],
        ]);
    }

    public function listUsers()
    {
        $teachers = User::where('role', 'teacher')
            ->select('id', 'full_name', 'email')
            ->get();

        $users = User::withCount('documents')
            ->with('assignedTeacher:id,full_name,email')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn($u) => [
                'id'                  => $u->id,
                'full_name'           => $u->full_name,
                'email'               => $u->email,
                'profile_picture'     => $u->profile_picture,
                'created_at'          => $u->created_at,
                'last_login_date'     => $u->last_login_date,
                'role'                => $u->role ?? 'student',
                'documents'           => $u->documents_count,
                'quizzes'             => $u->dashboardStatistic?->quizzes_completed ?? 0,
                'is_approved'         => (bool) ($u->is_approved ?? true),
                'assigned_teacher_id' => $u->assigned_teacher_id,
                'assigned_teacher'    => $u->assignedTeacher ? $u->assignedTeacher->full_name : null,
                'status'              => $u->role === 'teacher' ? ($u->is_approved ? 'approved' : 'pending') : ($u->last_login_date ? 'active' : 'inactive'),
            ]);

        return response()->json([
            'users'    => $users,
            'teachers' => $teachers,
        ]);
    }

    public function assignTeacher(Request $request, $id)
    {
        $data = $request->validate([
            'teacher_id' => 'nullable|exists:users,id',
        ]);

        $student = User::where('role', 'student')->findOrFail($id);
        $student->assigned_teacher_id = $data['teacher_id'] ?? null;
        $student->save();

        return response()->json([
            'message'          => 'Student teacher assignment updated',
            'student'          => $student->load('assignedTeacher'),
            'assigned_teacher' => $student->assignedTeacher ? $student->assignedTeacher->full_name : null,
        ]);
    }

    public function toggleTeacherApproval(Request $request, $id)
    {
        $user = User::findOrFail($id);

        if ($user->role !== 'teacher') {
            return response()->json(['message' => 'User is not a teacher'], 400);
        }

        $user->is_approved = !$user->is_approved;
        $user->save();

        return response()->json([
            'message'     => 'Teacher approval status updated',
            'is_approved' => (bool) $user->is_approved,
            'user'        => $user,
        ]);
    }

    public function listDocuments()
    {
        $users = User::with(['documents', 'dashboardStatistic'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn($u) => [
                'id'                  => $u->id,
                'full_name'           => $u->full_name,
                'email'               => $u->email,
                'created_at'          => $u->created_at,
                'documents_uploaded'  => $u->documents->count(),
                'summaries_generated' => $u->dashboardStatistic?->summaries_generated ?? 0,
            ]);

        return response()->json(['users' => $users]);
    }

    private function formatAdmin(User $user): array
    {
        $teacher = $user->assignedTeacher;

        return [
            'id'                  => $user->id,
            'full_name'           => $user->full_name,
            'email'               => $user->email,
            'role'                => $user->role,
            'assigned_teacher_id' => $user->assigned_teacher_id,
            'assigned_teacher'    => $teacher ? [
                'id'              => $teacher->id,
                'full_name'       => $teacher->full_name,
                'email'           => $teacher->email,
                'primary_course'  => $teacher->primary_course,
                'about_me'        => $teacher->about_me,
                'profile_picture' => $teacher->profile_picture,
            ] : null,
        ];
    }
}
