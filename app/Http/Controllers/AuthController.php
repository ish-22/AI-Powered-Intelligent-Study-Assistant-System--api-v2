<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'full_name'   => 'required|string|min:2|max:255',
            'email'       => 'required|email|unique:users,email',
            'password'    => [
                'required', 'string', 'min:8', 'confirmed',
                'regex:/[A-Z]/', 'regex:/[a-z]/', 'regex:/[0-9]/',
            ],
            'role'        => 'nullable|string|in:student,teacher',
            'is_approved' => 'nullable|boolean',
        ]);

        $role = $data['role'] ?? 'student';
        $isApproved = isset($data['is_approved']) ? (bool)$data['is_approved'] : ($role !== 'teacher');

        $user = User::create([
            'full_name'   => $data['full_name'],
            'email'       => $data['email'],
            'password'    => Hash::make($data['password']),
            'role'        => $role,
            'is_approved' => $isApproved,
        ]);

        // Generate and dispatch real OTP via email
        $otp = $this->dispatchOtp($data['email']);

        $token = $user->createToken('auth_token')->plainTextToken;

        $response = [
            'user'    => $this->formatUser($user),
            'token'   => $token,
            'message' => 'Registration successful. OTP sent to your email.',
        ];

        if (config('app.debug')) {
            $response['debug_otp'] = $otp;
        }

        return response()->json($response, 201);
    }

    public function sendOtp(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
        ]);

        $otp = $this->dispatchOtp($data['email']);

        $response = ['message' => 'Verification OTP sent successfully to your email'];
        if (config('app.debug')) {
            $response['debug_otp'] = $otp;
        }

        return response()->json($response);
    }

    public function verifyOtp(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'otp'   => 'required|string|size:6',
        ]);

        $record = \DB::table('email_otps')
            ->where('email', $data['email'])
            ->where('otp', $data['otp'])
            ->where('expires_at', '>=', now())
            ->first();

        if (! $record) {
            throw ValidationException::withMessages([
                'otp' => ['Invalid or expired verification code.'],
            ]);
        }

        // Delete used OTP
        \DB::table('email_otps')->where('id', $record->id)->delete();

        // Mark user email as verified if exists
        User::where('email', $data['email'])->update(['email_verified_at' => now()]);

        return response()->json(['message' => 'Email verified successfully']);
    }

    private function dispatchOtp(string $email): string
    {
        $otp = sprintf('%06d', random_int(100000, 999999));

        \DB::table('email_otps')->updateOrInsert(
            ['email' => $email],
            [
                'otp'        => $otp,
                'expires_at' => now()->addMinutes(10),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        try {
            \Mail::to($email)->send(new \App\Mail\VerifyOtpMail($otp));
        } catch (\Throwable $e) {
            // Log mail exception if mailer is unconfigured
            \Log::error('Mail dispatch failed: ' . $e->getMessage());
        }

        return $otp;
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $user->update(['last_login_date' => now()]);
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user'  => $this->formatUser($user),
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out successfully']);
    }

    public function me(Request $request)
    {
        return response()->json(['user' => $this->formatUser($request->user())]);
    }

    public function googleLogin(Request $request)
    {
        $data = $request->validate([
            'email'           => 'required|email',
            'full_name'       => 'required|string',
            'google_id'       => 'required|string',
            'profile_picture' => 'nullable|string',
        ]);

        // Find user by google_id or email
        $user = User::where('google_id', $data['google_id'])
            ->orWhere('email', $data['email'])
            ->first();

        if ($user) {
            $updateData = [];
            if (empty($user->google_id)) {
                $updateData['google_id'] = $data['google_id'];
            }
            if (empty($user->profile_picture) && !empty($data['profile_picture'])) {
                $updateData['profile_picture'] = $data['profile_picture'];
            }
            if (!empty($updateData)) {
                $user->update($updateData);
            }
        } else {
            $user = User::create([
                'email'           => $data['email'],
                'full_name'       => $data['full_name'],
                'google_id'       => $data['google_id'],
                'profile_picture' => $data['profile_picture'] ?? null,
            ]);
        }

        $user->update(['last_login_date' => now()]);
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user'  => $this->formatUser($user),
            'token' => $token,
        ]);
    }

    private function formatUser(User $user): array
    {
        $teacher = $user->assignedTeacher;

        return [
            'id'                  => $user->id,
            'full_name'           => $user->full_name,
            'email'               => $user->email,
            'role'                => $user->role ?? 'student',
            'is_approved'         => (bool) ($user->is_approved ?? true),
            'profile_picture'     => $user->profile_picture,
            'about_me'            => $user->about_me,
            'primary_course'      => $user->primary_course,
            'language'            => $user->language,
            'preferences'         => $user->preferences ?: [],
            'created_at'          => $user->created_at,
            'last_login_date'     => $user->last_login_date,
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
