<?php

namespace App\Services;

/** Ephemeral synthetic handover only: no mail, disk, logging or reusable passwords. */
class LocalActivationDelivery
{
    public function handover(array $proof): array
    {
        abort_unless(app()->environment(['local', 'testing']), 503, 'Activation delivery is not configured.');

        return $proof;
    }
}
