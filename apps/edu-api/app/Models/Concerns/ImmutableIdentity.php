<?php

namespace App\Models\Concerns;

use LogicException;

trait ImmutableIdentity
{
    protected static function bootImmutableIdentity(): void
    {
        static::updating(function ($model): void {
            foreach (['id', 'school_id', 'user_id'] as $key) {
                if ($model->isDirty($key)) {
                    throw new LogicException('Internal identities cannot be reassigned.');
                }
            }
        });
    }
}
