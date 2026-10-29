<?php

namespace App\Http\Requests;

class MfaReplacementRequest extends StrictRequest
{
    public function rules(): array
    {
        $rules = ['password' => ['required', 'string', 'max:72'], 'code' => ['required', 'string', 'max:32']];
        if ($this->route()?->getActionMethod() === 'start') {
            $rules['method'] = ['required', 'in:totp,recovery'];
        } else {
            $rules['replacement_id'] = ['required', 'ulid'];
        }

        return $rules;
    }
}
