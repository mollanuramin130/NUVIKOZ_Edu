<?php

namespace App\Models;

use App\Enums\Role;
use App\Models\Concerns\ImmutableIdentity;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Membership extends Model
{
    use HasUlids, ImmutableIdentity;

    protected $fillable = ['school_id', 'user_id', 'role', 'login_identifier', 'status'];

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
