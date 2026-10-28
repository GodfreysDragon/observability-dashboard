<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\RangeRequest;
use App\Observability\Reports\DeployReport;
use App\Observability\Reports\RequestReport;
use Illuminate\Http\JsonResponse;

class RouteController extends Controller
{
    public function index(RangeRequest $request): JsonResponse
    {
        return response()->json(['data' => RequestReport::routes($request->range())]);
    }

    /**
     * The route is passed as a query parameter because route patterns contain slashes and braces.
     */
    public function show(RangeRequest $request): JsonResponse
    {
        $request->validate(['route' => ['required', 'string', 'max:191']]);

        $range = $request->range();
        $route = $request->string('route')->toString();

        return response()->json([
            'route' => $route,
            'current' => RequestReport::totals($range, $route),
            'previous' => RequestReport::totals($range->previous(), $route),
            'series' => RequestReport::series($range, $route),
            'distribution' => RequestReport::distribution($range, $route),
            'deploys' => DeployReport::markers($range),
            'slowest' => RequestReport::slowestTraces($range, $route),
            'queries' => RequestReport::topQueries($range, $route),
        ]);
    }
}
