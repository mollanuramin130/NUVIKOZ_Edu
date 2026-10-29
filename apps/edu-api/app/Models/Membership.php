<?php

namespace App\Models;

use App\Enums\Role;
use App\Models\Concerns\ImmutableIdentity;
use App\Services\LastAdminGuard;
use App\Services\SecurityRevocation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Membership extends Model
{
    use HasUlids, ImmutableIdentity;

    protected $fillable = ['school_id', 'user_id', 'role', 'login_identifier', 'status'];

    protected static function booted(): void
    {
        static::updating(function (self $member): void {
            if ($member->isDirty(['role', 'status', 'login_identifier'])) {
                app(SecurityRevocation::class)->user($member->user_id, $member->school_id);
                foreach (['account_activations', 'account_recoveries'] as $table) {
                    DB::table($table)->where('school_id', $member->school_id)->whereNull('consumed_at')->whereNull('invalidated_at')->where(fn ($query) => $query->where('membership_id', $member->id)->orWhere('issuer_membership_id', $member->id))->update(['invalidated_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }

    // Serialize credential/membership mutation with issuance; include observer revocation
    // and the actual SQL update in the same transaction, even for direct model callers.
    public function save(array $options = [])
    {
        if (! $this->exists || ! $this->isDirty(['role', 'status', 'login_identifier'])) {
            return parent::save($options);
        }

        return DB::transaction(function () use ($options) {
            DB::table('schools')->where('id', $this->school_id)->lockForUpdate()->first();
            DB::table('users')->where('id', $this->user_id)->lockForUpdate()->first();
            $old = DB::table('memberships')->where('id', $this->id)->lockForUpdate()->first();
            app(LastAdminGuard::class)->removing($old, $this->role->value, $this->status);
            $this->permission_version = $old->permission_version + 1;

            return parent::save($options);
        }, 3);
    }

    public function delete()
    {
        throw new AuthorizationException('Membership history cannot be deleted; use the protected deactivation command.');
    }

    protected function casts(): array
    {
        return ['role' => Role::class];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
