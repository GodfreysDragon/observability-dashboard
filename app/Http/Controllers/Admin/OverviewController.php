<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\RangeRequest;
use App\Observability\Reports\DeployReport;
use App\Observability\Reports\ExceptionReport;
use App\Observability\Reports\QueueReport;
use App\Observability\Reports\RequestReport;
use App\Observability\Reports\SloReport;
use Illuminate\Http\JsonResponse;

class OverviewController extends Controller
{
    public function __invoke(RangeRequest $request): JsonResponse
    {
        $range = $request->range();

        return response()->json([
            'range' => ['key' => $range->key, 'from' => $range->from, 'to' => $range->to, 'step' => $range->step, 'now' => $range->now],
            'current' => RequestReport::totals($range),
            'previous' => RequestReport::totals($range->previous()),
            'series' => RequestReport::series($range),
            'deploys' => DeployReport::markers($range),
            'routes' => array_slice(RequestReport::routes($range), 0, 8),
            'queues' => QueueReport::queues($range),
            'exceptions' => array_slice(ExceptionReport::groups($range, includeResolved: false), 0, 5),
            'slo' => SloReport::build(),
        ]);
    }
}
