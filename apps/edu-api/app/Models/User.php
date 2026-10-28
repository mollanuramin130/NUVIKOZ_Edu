<?php

namespace App\Models;

use App\Models\Concerns\ImmutableIdentity;
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
                DB::table('account_activations')->whereNull('consumed_at')->whereNull('invalidated_at')->where(function ($query) use ($user): void {
                    $query->where('user_id', $user->id)->orWhereIn('issuer_membership_id', DB::table('memberships')->select('id')->where('user_id', $user->id));
                })->update(['invalidated_at' => now(), 'updated_at' => now()]);
            }
        });
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
