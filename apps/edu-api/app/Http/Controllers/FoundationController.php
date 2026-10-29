<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\PasswordRequest;
use App\Http\Requests\ProvisionRequest;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Services\ProvisionStaff;
use App\Support\Api;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class FoundationController extends Controller
{
    public function login(LoginRequest $request)
    {
        $data = $request->validated();
        $school = School::where('code', $data['school_code'])->first();
        $membership = $school ? Membership::where('school_id', $school->id)->where('login_identifier', $data['identifier'])->where('status', 'active')->with('user')->first() : null;
        if (! $membership || in_array($school->access_state, ['suspended', 'archived'], true) || $membership->user->is_platform_admin || $membership->user->status !== 'active' || $membership->user->activation_required || ! Hash::check($data['password'], $membership->user->password)) {
            return Api::error('INVALID_CREDENTIALS', 'Credentials could not be verified.', 401);
        }

        return DB::transaction(function () use ($membership, $data) {
            $school = School::whereKey($membership->school_id)->lockForUpdate()->firstOrFail();
            $user = User::whereKey($membership->user_id)->lockForUpdate()->firstOrFail();
            $current = Membership::whereKey($membership->id)->where('status', 'active')->lockForUpdate()->first();
            if (! $current || $user->is_platform_admin || $user->activation_required || in_array($school->access_state, ['suspended', 'archived'], true) || $user->status !== 'active' || ! Hash::check($data['password'], $user->password)) {
                return Api::error('INVALID_CREDENTIALS', 'Credentials could not be verified.', 401);
            }
            $expiry = $current->role === Role::SchoolAdmin ? now()->addHours(12) : now()->addDays(7);
            $token = $user->createToken($data['device_name'] ?? 'device', ['school:'.$current->school_id], $expiry);

            return Api::ok(['token' => $token->plainTextToken, 'expires_at' => $expiry->toIso8601String(), 'must_change_password' => $user->must_change_password]);
        });
    }

    public function me(Request $request)
    {
        return Api::ok(['id' => $request->user()->id, 'must_change_password' => $request->user()->must_change_password, 'memberships' => $request->user()->memberships()->where('status', 'active')->get(['id', 'school_id', 'role'])]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return Api::ok(['revoked' => true]);
    }

    public function password(PasswordRequest $request)
    {
        abort_if($request->user()->activation_required, 403);
        if (! Hash::check($request->validated('current_password'), $request->user()->password)) {
            return Api::error('INVALID_CREDENTIALS', 'Credentials could not be verified.', 401);
        }
        DB::transaction(function () use ($request): void {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_if($user->activation_required, 403);
            abort_unless(Hash::check($request->validated('current_password'), $user->password), 401);
            $user->password = $request->validated('password');
            $user->must_change_password = false;
            $user->save();
            $user->tokens()->delete();
            foreach ($user->memberships()->get() as $membership) {
                AuditEvent::create(['school_id' => $membership->school_id, 'actor_user_id' => $user->id, 'action' => 'credential.changed', 'resource_id' => $user->id, 'request_id' => $request->attributes->get('request_id')]);
            }
        });

        return Api::ok(['reauthentication_required' => true]);
    }

    public function school(Request $request, string $school)
    {
        $membership = $request->attributes->get('membership');
        // Query scope is explicit; a guessed route ID cannot change the selected tenant.
        $record = School::where('id', $membership->school_id)->whereKey($school)->firstOrFail();

        return Api::ok(['id' => $record->id, 'code' => $record->code, 'name' => $record->name, 'data_stage' => $record->data_stage]);
    }

    public function provision(ProvisionRequest $request, ProvisionStaff $service)
    {
        abort(403); // Privileged device provisioning is closed until MFA-aware device assurance exists.

        return Api::ok($service->execute($request->attributes->get('membership'), $request->validated(), $request->attributes->get('request_id'), $request->header('Idempotency-Key')), 201);
    }

    public function fileDenied(Request $request)
    {
        if ($request->attributes->get('membership')->school->data_stage === 'demo') {
            return Api::error('DEMO_UPLOAD_FORBIDDEN', 'File uploads are unavailable in Demo.', 403);
        }

        return Api::error('FEATURE_DISABLED', 'File handling is not enabled in this increment.', 403);
    }
}
