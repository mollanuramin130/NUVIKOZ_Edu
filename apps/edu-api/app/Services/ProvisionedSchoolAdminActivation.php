<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProvisionedSchoolAdminActivation
{
    private const TABLE = 'school_admin_activation_proofs';

    public function status(string $registration): array
    {
        $rows = DB::table(self::TABLE)->where('registration_id', $registration)->latest('sequence')
            ->limit(25)->get(['sequence', 'state', 'operation', 'expires_at', 'created_at', 'actor_id']);
        foreach ($rows as $row) {
            if (in_array($row->state, ['pending_delivery', 'delivered'], true) && Carbon::parse($row->expires_at)->lessThanOrEqualTo(now())) {
                $row->state = 'expired';
            }
        }

        return [
            'state' => $rows->first()->state ?? 'awaiting_verified_delivery',
            'delivery_configured' => app()->bound(VerifiedSchoolAdminDelivery::class),
            'history' => $rows,
        ];
    }

    private function audit(string $school, string $actor, string $event, string $resource, string $request): void
    {
        AuditEvent::create(['school_id' => $school, 'actor_user_id' => $actor, 'action' => 'school_admin.activation.'.$event, 'resource_id' => $resource, 'request_id' => $request]);
    }

    // Caller holds the school lock for every lifecycle command.
    public function command(object $link, string $actor, string $operation, string $command, string $request): array
    {
        $previous = DB::table(self::TABLE)->where('registration_id', $link->registration_id)->where('command_id', $command)->lockForUpdate()->first();
        if ($previous) {
            abort_unless($previous->actor_id === $actor && $previous->operation === $operation, 409);

            return ['activation' => $this->status($link->registration_id), 'replayed' => true];
        }
        $latest = DB::table(self::TABLE)->where('registration_id', $link->registration_id)->latest('sequence')->lockForUpdate()->first();
        abort_if($latest?->state === 'consumed', 409);
        abort_unless($operation === 'issue' ? ! $latest : $latest !== null, 409);
        abort_if($operation === 'revoke' && ! in_array($latest->state, ['pending_delivery', 'delivered'], true), 409);

        $id = (string) Str::ulid();
        $proof = [
            'id' => $id, 'registration_id' => $link->registration_id, 'actor_id' => $actor,
            'command_id' => $command, 'sequence' => ($latest->sequence ?? 0) + 1, 'operation' => $operation, 'state' => 'revoked', 'created_at' => now(),
        ];
        if ($operation !== 'revoke') {
            abort_unless(app()->bound(VerifiedSchoolAdminDelivery::class), 503);
            $contact = DB::table('registration_requests')->where('id', $link->registration_id)->where('state', 'approved')->value('contact');
            $recipient = $contact ? app(VerifiedSchoolAdminDelivery::class)->recipient($link->registration_id, $contact) : null;
            abort_unless(is_string($recipient) && $recipient !== '', 409);
            $secret = bin2hex(random_bytes(32));
            $proof['state'] = 'pending_delivery';
            $proof['secret_hash'] = hash('sha256', $secret);
            $proof['recipient_hash'] = hash('sha256', $recipient);
            $proof['expires_at'] = now()->addMinutes(30);
            $proof['delivery_envelope'] = Crypt::encryptString(json_encode(['recipient' => $recipient, 'secret' => $secret], JSON_THROW_ON_ERROR));
        }
        if ($latest && in_array($latest->state, ['pending_delivery', 'delivered'], true)) {
            DB::table(self::TABLE)->where('id', $latest->id)->update([
                'state' => $operation === 'revoke' ? 'revoked' : 'superseded', 'delivery_envelope' => null, 'secret_hash' => null,
            ]);
        }
        DB::table(self::TABLE)->insert($proof);
        $this->audit($link->school_id, $actor, $operation, $id, $request);

        return ['activation' => $this->status($link->registration_id), 'replayed' => false];
    }

    public function expire(): void
    {
        DB::table(self::TABLE)->whereIn('state', ['pending_delivery', 'delivered'])->where('expires_at', '<=', now())
            ->select(['id', 'registration_id'])->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    DB::transaction(function () use ($row): void {
                        $schoolId = DB::table('school_provisionings')->where('registration_id', $row->registration_id)->value('school_id');
                        School::whereKey($schoolId)->lockForUpdate()->firstOrFail();
                        $proof = DB::table(self::TABLE)->where('id', $row->id)->lockForUpdate()->first();
                        if (in_array($proof->state, ['pending_delivery', 'delivered'], true) && Carbon::parse($proof->expires_at)->lessThanOrEqualTo(now())) {
                            $this->markExpired($schoolId, $proof);
                        }
                    }, 3);
                }
            });
    }

    private function markExpired(string $schoolId, object $proof): void
    {
        DB::table(self::TABLE)->where('id', $proof->id)->update(['state' => 'expired', 'delivery_envelope' => null, 'secret_hash' => null]);
        $this->audit($schoolId, $proof->actor_id, 'expired', $proof->id, (string) Str::ulid());
    }

    /** Internal worker boundary. Never call from a browser route. */
    public function deliver(string $registration): bool
    {
        if (! app()->bound(VerifiedSchoolAdminDelivery::class)) {
            return false;
        }

        return DB::transaction(function () use ($registration): bool {
            $link = DB::table('school_provisionings')->where('registration_id', $registration)->first();
            $school = $link ? School::whereKey($link->school_id)->lockForUpdate()->first() : null;
            $proof = $school ? DB::table(self::TABLE)->where('registration_id', $registration)->latest('sequence')->lockForUpdate()->first() : null;
            if (! $proof || $proof->state !== 'pending_delivery' || $school->access_state !== 'suspended') {
                return false;
            }
            if (Carbon::parse($proof->expires_at)->lessThanOrEqualTo(now())) {
                $this->markExpired($school->id, $proof);

                return false;
            }
            $payload = json_decode(Crypt::decryptString($proof->delivery_envelope), true, flags: JSON_THROW_ON_ERROR);
            $adapter = app(VerifiedSchoolAdminDelivery::class);
            $contact = DB::table('registration_requests')->where('id', $registration)->where('state', 'approved')->value('contact');
            if (! $contact || $adapter->recipient($registration, $contact) !== $payload['recipient']
                || ! $adapter->deliver($payload['recipient'], ['school_code' => $school->code, 'proof_id' => $proof->id, 'secret' => $payload['secret'], 'expires_at' => $proof->expires_at])) {
                return false;
            }
            DB::table(self::TABLE)->where('id', $proof->id)->update(['state' => 'delivered', 'delivery_envelope' => null]);
            $this->audit($school->id, $proof->actor_id, 'delivered', $proof->id, (string) Str::ulid());

            return true;
        });
    }

    // AccountActivation owns the browser entry point, validation and school lock.
    public function consume(School $school, #[\SensitiveParameter] array $data, string $request, ?string $signedInUser): array
    {
        $deny = fn () => throw ValidationException::withMessages(['activation' => 'Activation proof is invalid, expired or unavailable.']);
        $link = DB::table('school_provisionings')->where('school_id', $school->id)->first();
        $proof = $link ? DB::table(self::TABLE)->where('registration_id', $link->registration_id)->where('id', $data['proof_id'])->lockForUpdate()->first() : null;
        if (! $proof || $school->access_state !== 'suspended' || $proof->state !== 'delivered'
            || Carbon::parse($proof->expires_at)->lessThanOrEqualTo(now())
            || ! hash_equals($proof->secret_hash, hash('sha256', $data['secret']))
            || ! app()->bound(VerifiedSchoolAdminDelivery::class)) {
            $deny();
        }
        $contact = DB::table('registration_requests')->where('id', $link->registration_id)->where('state', 'approved')->value('contact');
        $recipient = $contact ? app(VerifiedSchoolAdminDelivery::class)->recipient($link->registration_id, $contact) : null;
        if (! $recipient || ! hash_equals($proof->recipient_hash, hash('sha256', $recipient))) {
            $deny();
        }
        $member = Membership::where('school_id', $school->id)->whereKey($link->membership_id)->first();
        $user = $member ? User::whereKey($member->user_id)->lockForUpdate()->first() : null;
        $member = $member ? Membership::whereKey($member->id)->lockForUpdate()->first() : null;
        if (! $user || ! $member || $member->user_id !== $user->id || $member->role !== Role::SchoolAdmin || $member->status !== 'inactive'
            || $user->status !== 'inactive' || $user->is_platform_admin || ! $user->activation_required
            || ($signedInUser && $signedInUser !== $user->id)) {
            $deny();
        }
        $user->update(['password' => $data['password'], 'activation_required' => false, 'must_change_password' => false, 'status' => 'active']);
        $member->update(['status' => 'active']);
        DB::table(self::TABLE)->where('id', $proof->id)->update(['state' => 'consumed', 'secret_hash' => null]);
        $this->audit($school->id, $user->id, 'consumed', $proof->id, $request);

        return ['activated' => true, 'login_required' => true];
    }
}
