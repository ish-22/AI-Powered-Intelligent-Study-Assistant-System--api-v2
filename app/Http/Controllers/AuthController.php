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
            'full_name' => 'required|string|min:2|max:255',
            'email'     => 'required|email|unique:users,email',
            'password'  => [
                'required', 'string', 'min:8', 'confirmed',
                'regex:/[A-Z]/', 'regex:/[a-z]/', 'regex:/[0-9]/',
            ],
        ]);

        $user = User::create([
            'full_name' => $data['full_name'],
            'email'     => $data['email'],
            'password'  => Hash::make($data['password']),
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user'  => $this->formatUser($user),
            'token' => $token,
        ], 201);
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
        return [
            'id'              => $user->id,
            'full_name'       => $user->full_name,
            'email'           => $user->email,
            'role'            => $user->role ?? 'student',
            'profile_picture' => $user->profile_picture,
            'about_me'        => $user->about_me,
            'primary_course'  => $user->primary_course,
            'language'        => $user->language,
            'preferences'     => $user->preferences ?: [],
            'created_at'      => $user->created_at,
            'last_login_date' => $user->last_login_date,
        ];
    }
}
