<?php

namespace App\Http\Requests;

class ActivationRequest extends StrictRequest
{
    public function rules(): array
    {
        return ['school_code' => ['required', 'string', 'max:32'], 'proof_id' => ['required', 'ulid'], 'secret' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]+\z/'], 'password' => ['required', 'string', 'min:12', 'max:72', 'confirmed', function ($attribute, $value, $fail): void {
            if (strlen($value) > 72) {
                $fail('Password must not exceed 72 bytes.');
            }
        }], 'password_confirmation' => ['required', 'string', 'max:72']];
    }
}
