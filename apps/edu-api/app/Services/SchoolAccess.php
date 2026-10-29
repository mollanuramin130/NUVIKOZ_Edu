<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Membership;
use Illuminate\Auth\Access\AuthorizationException;

class SchoolAccess
{
    public function requireAdmin(Membership $membership): void
    {
        $this->requireAdminIdentity($membership);
        app(PrivilegedMfa::class)->require($membership->fresh());
    }

    // Proof redemption revalidates issuer identity, not the issuer's unrelated live session.
    public function requireAdminIdentity(Membership $membership): void
    {
        $current = Membership::whereKey($membership->id)->where('school_id', $membership->school_id)->where('user_id', $membership->user_id)->with(['school', 'user'])->first();
        if (! $current || $current->status !== 'active' || $current->user->status !== 'active' || $current->user->must_change_password || $current->user->activation_required || $current->user->is_platform_admin || in_array($current->school->access_state, ['suspended', 'archived'], true) || $current->role !== Role::SchoolAdmin) {
            throw new AuthorizationException('School Admin required.');
        }
    }

    public function canWrite(Membership $membership): bool
    {
        $school = $membership->school;
        if ($school->data_stage === 'demo') {
            return $school->demo_ends_at?->isFuture() ?? false;
        }

        return $school->access_state === 'live_enabled' && ($school->entitlement_ends_at?->isFuture() ?? false);
    }
}
