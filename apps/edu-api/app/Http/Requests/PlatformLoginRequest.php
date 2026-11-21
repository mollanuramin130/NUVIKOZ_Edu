<?php

namespace App\Http\Requests;

class PlatformLoginRequest extends StrictRequest
{
    public function rules(): array
    {
        return ['identifier' => ['required', 'ulid'], 'password' => ['required', 'string', 'max:72']];
    }
}
