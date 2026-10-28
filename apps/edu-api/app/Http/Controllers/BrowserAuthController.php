<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Support\Api;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class BrowserAuthController extends Controller
{
    public function bootstrap(Request $request)
    {
        return Api::ok(['csrf_token' => $request->session()->token()]);
    }

    public function login(LoginRequest $request)
    {
        $data = $request->validated();
        $school = School::where('code', $data['school_code'])->first();
        $membership = $school ? Membership::where('school_id', $school->id)->where('login_identifier', $data['identifier'])->first() : null;
        $valid = $membership && $membership->status === 'active' && ! in_array($school->access_state, ['suspended', 'archived'], true);
        $user = $valid ? $membership->user : null;
        if (! $user || $user->status !== 'active' || $user->is_platform_admin || ! Hash::check($data['password'], $user->password)) {
            return Api::error('INVALID_CREDENTIALS', 'Credentials could not be verified.', 401);
        }
        if ($user->must_change_password) {
            return Api::error('PASSWORD_CHANGE_REQUIRED', 'Complete account activation first.', 403);
        }

        // Serialize credential checks with password changes, then recheck membership/access.
        return DB::transaction(function () use ($request, $data, $membership) {
            $user = User::whereKey($membership->user_id)->lockForUpdate()->firstOrFail();
            $current = $membership->fresh(['school']);
            if (! $current || $current->status !== 'active' || in_array($current->school->access_state, ['suspended', 'archived'], true) || $user->status !== 'active' || $user->is_platform_admin || $user->must_change_password || ! Hash::check($data['password'], $user->password)) {
                return Api::error('INVALID_CREDENTIALS', 'Credentials could not be verified.', 401);
            }
            Auth::guard('web')->login($user);
            $request->session()->regenerate(true);
            $request->session()->regenerateToken();
            $request->session()->put(['school_id' => $current->school_id, 'issued_at' => time(), 'seen_at' => time(), 'credential_hash' => hash('sha256', $user->password)]);

            return Api::ok(['user_id' => $user->id, 'school_id' => $current->school_id, 'role' => $current->role->value, 'csrf_token' => $request->session()->token()]);
        });
    }

    public function me(Request $request)
    {
        $membership = $request->attributes->get('membership');

        return Api::ok(['user_id' => $request->user()->id, 'school_id' => $membership->school_id, 'role' => $membership->role->value]);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Api::ok(['revoked' => true]);
    }
}
