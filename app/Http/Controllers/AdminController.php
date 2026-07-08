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
        $user = User::where('email', $data['username'])
            ->orWhere('full_name', $data['username'])
            ->where('role', 'admin')
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
        $users = User::withCount('documents')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn($u) => [
                'id'              => $u->id,
                'full_name'       => $u->full_name,
                'email'           => $u->email,
                'profile_picture' => $u->profile_picture,
                'created_at'      => $u->created_at,
                'last_login_date' => $u->last_login_date,
                'role'            => $u->role ?? 'student',
                'documents'       => $u->documents_count,
                'quizzes'         => $u->dashboardStatistic?->quizzes_completed ?? 0,
                'status'          => $u->last_login_date ? 'active' : 'inactive',
            ]);

        return response()->json(['users' => $users]);
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
        return [
            'id'        => $user->id,
            'full_name' => $user->full_name,
            'email'     => $user->email,
            'role'      => $user->role,
        ];
    }
}
