<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LastAdminGuard
{
    // Caller must hold the school row lock through the actual membership UPDATE.
    public function removing(object $old, string $role, string $status): void
    {
        if ($old->role !== 'school_admin' || $old->status !== 'active' || ($role === 'school_admin' && $status === 'active')) {
            return;
        }
        $eligible = DB::table('users')->where('id', $old->user_id)->where('status', 'active')->where('is_platform_admin', false)->where('activation_required', false)->where('must_change_password', false)->exists();
        if (! $eligible) {
            return;
        }
        $remaining = DB::table('memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.school_id', $old->school_id)->where('m.id', '!=', $old->id)->where('m.role', 'school_admin')->where('m.status', 'active')->where('u.status', 'active')->where('u.is_platform_admin', false)->where('u.activation_required', false)->where('u.must_change_password', false)->exists();
        if (! $remaining) {
            throw ValidationException::withMessages(['last_admin' => 'The last eligible active School Admin cannot be removed.']);
        }
    }
}
