<?php

namespace App\Models;

use App\Models\Concerns\ImmutableIdentity;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class School extends Model
{
    use HasUlids, ImmutableIdentity;

    protected $fillable = ['code', 'name', 'data_stage', 'access_state', 'demo_ends_at', 'entitlement_ends_at', 'settings'];

    protected function casts(): array
    {
        return ['demo_ends_at' => 'immutable_datetime', 'entitlement_ends_at' => 'immutable_datetime', 'settings' => 'array'];
    }
}
