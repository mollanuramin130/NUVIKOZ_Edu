<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class StaffProfile extends Model
{
    use HasUlids;

    protected $fillable = ['school_id', 'membership_id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Staff profile links are immutable.'));
        static::deleting(fn () => throw new LogicException('Staff profiles are retained.'));
    }
}
