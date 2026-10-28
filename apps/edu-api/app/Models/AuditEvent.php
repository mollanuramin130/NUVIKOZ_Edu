<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class AuditEvent extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = ['school_id', 'actor_user_id', 'action', 'resource_id', 'request_id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit events are append-only.'));
        static::deleting(fn () => throw new LogicException('Audit events are append-only.'));
    }
}
