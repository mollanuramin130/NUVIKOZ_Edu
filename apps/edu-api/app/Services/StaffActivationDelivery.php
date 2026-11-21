<?php

namespace App\Services;

use App\Models\School;
use SodiumException;

class StaffActivationDelivery
{
    public function handover(School $school, array $proof): array
    {
        if (app()->environment(['local', 'testing'])) {
            return ['local_handover' => app(LocalActivationDelivery::class)->handover($proof)];
        }

        $publicKey = config('staging-bootstrap.staff_activation_public_key');
        abort_unless(
            app()->environment('staging')
            && str_starts_with($school->code, 'EDU-STAGE-')
            && $school->code === config('staging-bootstrap.staff_activation_school')
            && $school->data_stage === 'demo'
            && $school->access_state === 'provisional'
            && is_string($publicKey)
            && preg_match('/\A[a-f0-9]{64}\z/', $publicKey)
            && function_exists('sodium_crypto_box_seal'),
            503,
            'Staff activation delivery is unavailable.',
        );

        try {
            $sealed = sodium_crypto_box_seal(
                json_encode(['school_code' => $school->code] + $proof, JSON_THROW_ON_ERROR),
                hex2bin($publicKey),
            );
        } catch (SodiumException) {
            abort(503, 'Staff activation delivery is unavailable.');
        }

        return ['sealed_handover' => base64_encode($sealed)];
    }
}
