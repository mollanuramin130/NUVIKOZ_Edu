<?php

namespace App\Models;

use App\Enums\Role;
use App\Models\Concerns\ImmutableIdentity;
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
                foreach (['account_activations', 'account_recoveries'] as $table) {
                    DB::table($table)->where('school_id', $member->school_id)->whereNull('consumed_at')->whereNull('invalidated_at')->where(fn ($query) => $query->where('membership_id', $member->id)->orWhere('issuer_membership_id', $member->id))->update(['invalidated_at' => now(), 'updated_at' => now()]);
                }
            }
        });
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
