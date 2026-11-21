<?php

namespace App\Http\Controllers;

use App\Services\AcademicCatalog;
use App\Support\Api;
use Illuminate\Http\Request;

class AcademicCatalogController extends Controller
{
    public function index(Request $request, AcademicCatalog $service)
    {
        return Api::ok($service->listing($request->attributes->get('membership'), $request->route('kind'), $request->query(), $request->routeIs('device.academic.*')));
    }

    public function transitionSession(Request $request, AcademicCatalog $service, string $id)
    {
        abort_unless($request->isJson(), 415);
        abort_if(strlen($request->getContent()) > 1024, 413);
        abort_unless($request->query() === [], 422);

        return Api::ok($service->transitionSession($request->attributes->get('membership'), $id, $request->json()->all(),
            $request->header('Idempotency-Key'), $request->attributes->get('request_id')));
    }

    public function editSession(Request $request, AcademicCatalog $service, string $id)
    {
        abort_unless($request->isJson(), 415);
        abort_if(strlen($request->getContent()) > 1024, 413);
        abort_unless($request->query() === [], 422);

        return Api::ok($service->editSession($request->attributes->get('membership'), $id, $request->json()->all(),
            $request->header('Idempotency-Key'), $request->attributes->get('request_id')));
    }

    public function store(Request $request, AcademicCatalog $service)
    {
        return Api::ok($service->create($request->attributes->get('membership'), $request->route('kind'), $request->all(), $request->header('Idempotency-Key'), $request->attributes->get('request_id'), $request->routeIs('device.academic.*')));
    }
}
