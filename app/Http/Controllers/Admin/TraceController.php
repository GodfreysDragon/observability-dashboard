<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\RangeRequest;
use App\Observability\Reports\TraceReport;
use Illuminate\Http\JsonResponse;

class TraceController extends Controller
{
    public function index(RangeRequest $request): JsonResponse
    {
        $filters = $request->validate([
            'route' => ['nullable', 'string', 'max:191'],
            'status' => ['nullable', 'in:2xx,3xx,4xx,5xx'],
            'reason' => ['nullable', 'in:'.implode(',', TraceReport::REASONS)],
            'min_ms' => ['nullable', 'integer', 'min:0', 'max:600000'],
        ]);

        $page = TraceReport::requests($request->range(), $filters);

        return response()->json([
            'data' => $page->items(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function showRequest(int $id): JsonResponse
    {
        return response()->json(TraceReport::detail('request', $id) ?? abort(404));
    }

    public function showJob(int $id): JsonResponse
    {
        return response()->json(TraceReport::detail('job', $id) ?? abort(404));
    }
}
