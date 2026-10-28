<?php

namespace App\Http\Requests;

class LoginRequest extends StrictRequest
{
    public function rules(): array
    {
        return ['school_code' => ['required', 'string', 'max:32'], 'identifier' => ['required', 'string', 'max:80'], 'password' => ['required', 'string', 'max:72'], 'device_name' => ['sometimes', 'string', 'max:80']];
    }
}
