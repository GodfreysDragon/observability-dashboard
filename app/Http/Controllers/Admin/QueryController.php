<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\RangeRequest;
use App\Observability\Reports\QueryReport;
use Illuminate\Http\JsonResponse;

class QueryController extends Controller
{
    public function index(RangeRequest $request): JsonResponse
    {
        $request->validate(['sort' => ['nullable', 'in:'.implode(',', QueryReport::SORTS)]]);

        return response()->json([
            'data' => QueryReport::fingerprints($request->range(), $request->input('sort') ?: 'total_ms'),
        ]);
    }

    public function show(RangeRequest $request, string $fingerprint): JsonResponse
    {
        return response()->json(QueryReport::detail($request->range(), $fingerprint) ?? abort(404));
    }
}
