<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\RangeRequest;
use App\Observability\Reports\QueueReport;
use Illuminate\Http\JsonResponse;

class QueueController extends Controller
{
    public function __invoke(RangeRequest $request): JsonResponse
    {
        $range = $request->range();

        return response()->json([
            'queues' => QueueReport::queues($range),
            'series' => QueueReport::series($range),
            'jobs' => QueueReport::jobs($range),
            'failures' => QueueReport::recentFailures($range),
        ]);
    }
}
