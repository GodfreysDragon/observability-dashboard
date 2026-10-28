<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\RangeRequest;
use App\Observability\Reports\ExceptionReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExceptionController extends Controller
{
    public function index(RangeRequest $request): JsonResponse
    {
        return response()->json(['data' => ExceptionReport::groups($request->range())]);
    }

    public function show(RangeRequest $request, int $group): JsonResponse
    {
        return response()->json(ExceptionReport::detail($request->range(), $group) ?? abort(404));
    }

    /**
     * Mark a group resolved, or reopen it. A resolved group reopens by itself if it recurs.
     */
    public function resolve(Request $request, int $group): JsonResponse
    {
        $data = $request->validate(['resolved' => ['required', 'boolean']]);

        $updated = DB::table('obs_exception_groups')->where('id', $group)->update([
            'resolved_at' => $data['resolved'] ? now() : null,
        ]);

        abort_if($updated === 0 && ! DB::table('obs_exception_groups')->where('id', $group)->exists(), 404);

        return response()->json(['resolved_at' => DB::table('obs_exception_groups')->where('id', $group)->value('resolved_at')]);
    }
}
