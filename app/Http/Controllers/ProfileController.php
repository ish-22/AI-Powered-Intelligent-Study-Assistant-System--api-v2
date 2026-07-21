<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        return response()->json([
            'user' => [
                'id'              => $user->id,
                'full_name'       => $user->full_name,
                'email'           => $user->email,
                'profile_picture' => $user->profile_picture,
                'about_me'        => $user->about_me,
                'primary_course'  => $user->primary_course,
                'language'        => $user->language,
                'created_at'      => $user->created_at,
                'last_login_date' => $user->last_login_date,
            ],
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'full_name'       => 'sometimes|string|min:2|max:255',
            'email'           => 'sometimes|email|unique:users,email,' . $user->id,
            'profile_picture' => 'sometimes|nullable|string',
            'about_me'        => 'sometimes|nullable|string',
            'primary_course'  => 'sometimes|nullable|string|max:255',
            'language'        => 'sometimes|nullable|string|max:255',
        ]);

        if (!empty($data['profile_picture']) && preg_match('/^data:image\/(\w+);base64,/', $data['profile_picture'], $type)) {
            $imageParts = explode(';base64,', $data['profile_picture']);
            $imageType = preg_replace('/^data:image\//', '', $imageParts[0]);
            $imageBase64 = base64_decode($imageParts[1]);
            
            $fileName = escapeshellcmd(uniqid() . '.' . $imageType);
            Storage::disk('public')->put('profiles/' . $fileName, $imageBase64);
            $data['profile_picture'] = asset('storage/profiles/' . $fileName);
        }

        $user->update($data);

        return response()->json([
            'message' => 'Profile updated successfully',
            'user'    => [
                'id'              => $user->id,
                'full_name'       => $user->full_name,
                'email'           => $user->email,
                'profile_picture' => $user->profile_picture,
                'about_me'        => $user->about_me,
                'primary_course'  => $user->primary_course,
                'language'        => $user->language,
            ],
        ]);
    }

    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|confirmed|regex:/[A-Z]/|regex:/[a-z]/|regex:/[0-9]/',
        ]);

        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Current password is incorrect'], 422);
        }

        $user->update(['password' => Hash::make($request->password)]);

        return response()->json(['message' => 'Password changed successfully']);
    }
}
