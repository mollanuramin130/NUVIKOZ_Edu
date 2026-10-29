<?php

namespace App\Http\Controllers;

use App\Http\Requests\MfaRequest;
use App\Services\PrivilegedMfa;
use App\Support\Api;
use Illuminate\Http\Request;

class MfaController extends Controller
{
    public function status(Request $r, PrivilegedMfa $mfa)
    {
        return Api::ok($mfa->status($r->attributes->get('membership'), $r));
    }

    public function enroll(MfaRequest $r, PrivilegedMfa $mfa)
    {
        return Api::ok($mfa->enroll($r->attributes->get('membership'), $r, $r->validated('password')));
    }

    public function confirm(MfaRequest $r, PrivilegedMfa $mfa)
    {
        return Api::ok($mfa->verify($r->attributes->get('membership'), $r, $r->validated('password'), $r->validated('code'), 'confirm'));
    }

    public function challenge(MfaRequest $r, PrivilegedMfa $mfa)
    {
        return Api::ok($mfa->verify($r->attributes->get('membership'), $r, $r->validated('password'), $r->validated('code'), 'totp'));
    }

    public function recovery(MfaRequest $r, PrivilegedMfa $mfa)
    {
        return Api::ok($mfa->verify($r->attributes->get('membership'), $r, $r->validated('password'), $r->validated('code'), 'recovery'));
    }

    public function regenerate(MfaRequest $r, PrivilegedMfa $mfa)
    {
        return Api::ok($mfa->regenerate($r->attributes->get('membership'), $r, $r->validated('password')));
    }
}
