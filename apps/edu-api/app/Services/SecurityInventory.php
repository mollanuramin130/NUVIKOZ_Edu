<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class SecurityInventory
{
    public function opaque(string $kind, string $id, string $user): string
    {
        return hash_hmac('sha256', $kind.'|'.$user.'|'.$id, config('app.key'));
    }

    private function authorize(Membership $m, Request $r, ?string $password = null): Membership
    {
        $current = Membership::whereKey($m->id)->where('school_id', $m->school_id)->where('user_id', $m->user_id)->with(['school', 'user'])->firstOrFail();
        abort_unless($r->user()?->id === $current->user_id && $current->status === 'active' && $current->user->status === 'active' && ! $current->user->activation_required && ! $current->user->must_change_password && ! $current->user->is_platform_admin && ! in_array($current->school->access_state, ['suspended', 'archived'], true), 403);
        $token = $r->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            abort_unless($current->user->tokens()->whereKey($token->id)->whereJsonContains('abilities', 'school:'.$current->school_id)->where('expires_at', '>', now())->exists(), 403);
        } else {
            abort_unless($r->hasSession() && $r->session()->get('school_id') === $current->school_id && time() - $r->session()->get('issued_at', 0) < 43200 && time() - $r->session()->get('seen_at', 0) < (in_array($current->role->value, ['student', 'parent'], true) ? 28800 : 1800) && hash_equals((string) $r->session()->get('credential_hash', ''), hash('sha256', $current->user->password)) && ! DB::table('session_revocations')->where('session_hash', hash('sha256', $r->session()->getId()))->exists(), 403);
        }
        if ($current->role->value === 'school_admin') {
            app(SchoolAccess::class)->requireAdmin($current);
        }
        if ($password !== null && ! Hash::check($password, $current->user->password)) {
            throw ValidationException::withMessages(['password' => 'Credentials could not be verified.']);
        }

        return $current;
    }

    public function sessions(Membership $m, Request $r): array
    {
        $m = $this->authorize($m, $r);
        abort_unless($r->hasSession(), 403);

        return DB::table('sessions')->where('user_id', $m->user_id)->orderByDesc('last_activity')->limit(100)->get()->filter(fn ($row) => ! DB::table('session_revocations')->where('session_hash', hash('sha256', $row->id))->exists())->map(fn ($row) => ['id' => $this->opaque('session', $row->id, $m->user_id), 'current' => hash_equals($row->id, $r->session()->getId()), 'last_activity' => $row->last_activity, 'started_at' => null, 'expires_at' => null])->values()->all();
    }

    public function devices(Membership $m, Request $r): array
    {
        $m = $this->authorize($m, $r);
        $accessToken = $r->user()->currentAccessToken();
        $current = $accessToken instanceof PersonalAccessToken ? $accessToken->getKey() : null;

        return $m->user->tokens()->orderByDesc('id')->get()->filter(fn ($token) => in_array('school:'.$m->school_id, $token->abilities, true))->map(fn ($token) => ['id' => $this->opaque('device', (string) $token->id, $m->user_id), 'name' => mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', '', $token->name), 0, 80), 'current' => (string) $token->id === (string) $current, 'issued_at' => $token->created_at?->toIso8601String(), 'last_used_at' => $token->last_used_at?->toIso8601String(), 'expires_at' => $token->expires_at?->toIso8601String()])->values()->all();
    }

    public function revoke(Membership $m, Request $r, string $password, string $kind, string $id): array
    {
        return DB::transaction(function () use ($m, $r, $password, $kind, $id) {
            School::whereKey($m->school_id)->lockForUpdate()->firstOrFail();
            User::whereKey($m->user_id)->lockForUpdate()->firstOrFail();
            Membership::whereKey($m->id)->lockForUpdate()->firstOrFail();
            $m = $this->authorize($m, $r, $password);
            $count = 0;
            if ($kind === 'session') {
                abort_unless($r->hasSession(), 403);
                $rows = DB::table('sessions')->where('user_id', $m->user_id)->lockForUpdate()->get();
                foreach ($rows as $row) {
                    $opaque = $this->opaque('session', $row->id, $m->user_id);
                    if (($id === 'others' && $row->id !== $r->session()->getId()) || hash_equals($opaque, $id)) {
                        DB::table('session_revocations')->insertOrIgnore(['session_hash' => hash('sha256', $row->id), 'user_id' => $m->user_id, 'created_at' => now()]);
                        DB::table('sessions')->where('id', $row->id)->delete();
                        $count++;
                    }
                }
            } elseif ($kind === 'device') {
                $accessToken = $r->user()->currentAccessToken();
                $current = $accessToken instanceof PersonalAccessToken ? $accessToken->getKey() : null;
                foreach ($m->user->tokens()->lockForUpdate()->get() as $token) {
                    if (! in_array('school:'.$m->school_id, $token->abilities, true)) {
                        continue;
                    }
                    if (($id === 'others' && (string) $token->id !== (string) $current) || hash_equals($this->opaque('device', (string) $token->id, $m->user_id), $id)) {
                        $token->delete();
                        $count++;
                    }
                }
            } else {
                abort(422);
            }
            abort_unless($id === 'others' || $count > 0, 404);
            AuditEvent::create(['school_id' => $m->school_id, 'actor_user_id' => $m->user_id, 'action' => 'security.'.$kind.'_revoked', 'resource_id' => $m->id, 'request_id' => $r->attributes->get('request_id', 'security-command')]);

            return ['revoked' => $count];
        }, 3);
    }
}
