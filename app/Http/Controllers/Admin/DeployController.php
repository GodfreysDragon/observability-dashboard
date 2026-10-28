<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Observability\Reports\DeployReport;
use Illuminate\Http\JsonResponse;

class DeployController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => DeployReport::list()]);
    }
}
