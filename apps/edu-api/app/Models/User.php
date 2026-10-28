<?php

namespace App\Models;

use App\Models\Concerns\ImmutableIdentity;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasUlids, ImmutableIdentity;

    protected $fillable = ['name', 'password', 'status', 'must_change_password'];

    protected $hidden = ['password', 'remember_token', 'is_platform_admin'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'is_platform_admin' => 'boolean', 'must_change_password' => 'boolean'];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }
}
