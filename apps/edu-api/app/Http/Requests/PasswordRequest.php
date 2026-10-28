<?php

namespace App\Http\Requests;

class PasswordRequest extends StrictRequest
{
    public function rules(): array
    {
        return ['current_password' => ['required', 'string', 'max:72'], 'password' => ['required', 'string', 'min:12', 'max:72', 'confirmed'], 'password_confirmation' => ['required', 'string', 'max:72']];
    }
}
