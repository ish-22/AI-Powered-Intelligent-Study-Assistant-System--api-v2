<?php

namespace App\Http\Controllers;

use App\Models\DashboardStatistic;
use App\Models\User;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function stats()
    {
        $totalUsers     = User::count();
        $totalDocuments = DashboardStatistic::sum('documents_uploaded');
        $totalSummaries = DashboardStatistic::sum('summaries_generated');
        $totalQuizzes   = DashboardStatistic::sum('quizzes_completed');
        $avgScore       = DashboardStatistic::avg('avg_quiz_score') ?? 0;
        $totalHours     = DashboardStatistic::sum('study_time_hours');

        return response()->json([
            'stats' => [
                'total_users'        => $totalUsers,
                'total_documents'    => (int) $totalDocuments,
                'total_summaries'    => (int) $totalSummaries,
                'total_quizzes'      => (int) $totalQuizzes,
                'avg_score'          => (int) round($avgScore),
                'total_study_hours'  => (int) $totalHours,
            ],
        ]);
    }

    public function listUsers()
    {
        $users = User::orderBy('created_at', 'desc')
            ->get()
            ->map(fn($u) => [
                'id'              => $u->id,
                'full_name'       => $u->full_name,
                'email'           => $u->email,
                'profile_picture' => $u->profile_picture,
                'created_at'      => $u->created_at,
                'last_login_date' => $u->last_login_date,
                'role'            => $u->role ?? 'student',
            ]);

        return response()->json(['users' => $users]);
    }

    public function listDocuments()
    {
        $users = User::with('dashboardStatistic')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn($u) => [
                'id'                  => $u->id,
                'full_name'           => $u->full_name,
                'email'               => $u->email,
                'created_at'          => $u->created_at,
                'documents_uploaded'  => $u->dashboardStatistic?->documents_uploaded ?? 0,
                'summaries_generated' => $u->dashboardStatistic?->summaries_generated ?? 0,
            ]);

        return response()->json(['users' => $users]);
    }
}
