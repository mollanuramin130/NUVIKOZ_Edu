<?php

namespace App\Http\Requests;

use App\Services\SchoolAccess;
use Illuminate\Validation\Validator;

class ProvisionRequest extends StrictRequest
{
    public function authorize(): bool
    {
        app(SchoolAccess::class)->requireAdmin($this->attributes->get('membership'));

        return true;
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);
        $validator->after(function ($validator): void {
            if (! preg_match('/\A[A-Za-z0-9_-]{16,160}\z/', $this->header('Idempotency-Key', ''))) {
                $validator->errors()->add('idempotency_key', 'A random Idempotency-Key header is required.');
            }
        });
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:160'], 'login_identifier' => ['required', 'string', 'max:80', 'regex:/\A[A-Za-z0-9_-]+\z/'], 'role' => ['required', 'in:principal,teacher']];
    }
}
