<?php

namespace App\Models;

use App\Models\Concerns\ImmutableIdentity;
use App\Services\LastAdminGuard;
use App\Services\SecurityRevocation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasUlids, ImmutableIdentity;

    protected $fillable = ['name', 'password', 'status', 'must_change_password', 'activation_required'];

    protected $hidden = ['password', 'remember_token', 'is_platform_admin'];

    protected static function booted(): void
    {
        static::updating(function (self $user): void {
            if ($user->isDirty(['password', 'status', 'is_platform_admin', 'must_change_password', 'activation_required'])) {
                app(SecurityRevocation::class)->user($user->id);
                foreach (['account_activations', 'account_recoveries'] as $table) {
                    DB::table($table)->whereNull('consumed_at')->whereNull('invalidated_at')->where(function ($query) use ($user): void {
                        $query->where('user_id', $user->id)->orWhereIn('issuer_membership_id', DB::table('memberships')->select('id')->where('user_id', $user->id));
                    })->update(['invalidated_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }

    // Serialize credential/membership mutation with issuance; include observer revocation
    // and the actual SQL update in the same transaction, even for direct model callers.
    public function save(array $options = [])
    {
        if (! $this->exists || ! $this->isDirty(['password', 'status', 'is_platform_admin', 'must_change_password', 'activation_required'])) {
            return parent::save($options);
        }

        return DB::transaction(function () use ($options) {
            // Eligibility-losing identity changes must not bypass the membership
            // guard. Ordinary credential rotation does not acquire extra schools.
            $losesEligibility = ($this->isDirty('status') && $this->status !== 'active')
                || ($this->isDirty('is_platform_admin') && $this->is_platform_admin)
                || ($this->isDirty('activation_required') && $this->activation_required)
                || ($this->isDirty('must_change_password') && $this->must_change_password);
            if ($losesEligibility) {
                $schools = DB::table('memberships')->where('user_id', $this->id)->where('role', 'school_admin')->where('status', 'active')->pluck('school_id');
                DB::table('schools')->whereIn('id', $schools)->orderBy('id')->lockForUpdate()->get();
            }
            DB::table('users')->where('id', $this->id)->lockForUpdate()->first();

            if ($losesEligibility) {
                foreach (DB::table('memberships')->where('user_id', $this->id)->where('role', 'school_admin')->where('status', 'active')->orderBy('school_id')->lockForUpdate()->get() as $member) {
                    app(LastAdminGuard::class)->removing($member, 'school_admin', 'inactive');
                }
            }

            return parent::save($options);
        }, 3);
    }

    protected function casts(): array
    {
        return ['password' => 'hashed', 'is_platform_admin' => 'boolean', 'must_change_password' => 'boolean', 'activation_required' => 'boolean'];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }
}
