<?php

namespace App\Services;

interface VerifiedSchoolAdminDelivery
{
    /** Return an opaque recipient reference only for current independently verified ownership. */
    public function recipient(string $registrationId, string $submittedContact): ?string;

    /**
     * Confirm private delivery to the still-verified recipient. Implementations must deduplicate
     * by proof ID and honor expiry. Never log or include the payload in a URL.
     */
    public function deliver(string $recipient, #[\SensitiveParameter] array $proof): bool;
}
