<?php

namespace App\Http\Requests;

class MfaRequest extends StrictRequest
{
    public function rules(): array
    {
        $rules = ['password' => ['required', 'string', 'max:72']];
        if (in_array($this->route()?->getActionMethod(), ['confirm', 'challenge', 'recovery'], true)) {
            $rules['code'] = ['required', 'string', 'max:32'];
        }

        return $rules;
    }
}
