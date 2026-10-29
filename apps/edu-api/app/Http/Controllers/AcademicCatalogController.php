<?php

namespace App\Http\Controllers;

use App\Services\AcademicCatalog;
use App\Support\Api;
use Illuminate\Http\Request;

class AcademicCatalogController extends Controller
{
    public function index(Request $request, AcademicCatalog $service)
    {
        return Api::ok($service->listing($request->attributes->get('membership'), $request->route('kind'), $request->query()));
    }

    public function store(Request $request, AcademicCatalog $service)
    {
        return Api::ok($service->create($request->attributes->get('membership'), $request->route('kind'), $request->all(), $request->header('Idempotency-Key'), $request->attributes->get('request_id')));
    }
}
