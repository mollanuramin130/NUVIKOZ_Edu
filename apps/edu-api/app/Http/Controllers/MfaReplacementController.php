<?php

namespace App\Http\Controllers;

use App\Http\Requests\MfaReplacementRequest;
use App\Services\MfaReplacement;
use App\Support\Api;

class MfaReplacementController extends Controller
{
    private function context(MfaReplacementRequest $r): string
    {
        return $r->is('api/v1/browser/platform/*') ? 'platform' : 'school';
    }

    public function start(MfaReplacementRequest $r, MfaReplacement $service)
    {
        return Api::ok($service->start($r, $this->context($r), $r->validated('password'), $r->validated('code'), $r->validated('method')));
    }

    public function confirm(MfaReplacementRequest $r, MfaReplacement $service)
    {
        return Api::ok($service->confirm($r, $this->context($r), $r->validated('password'), $r->validated('replacement_id'), $r->validated('code')));
    }
}
