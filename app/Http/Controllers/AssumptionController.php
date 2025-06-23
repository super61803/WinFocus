<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\Assumption;

class AssumptionController extends Controller
{
    public function get(Request $request): JsonResponse
    {
        $assumption_id = $request->input('assumptionId');
        $project_id = $request->input('projId');

        $assumption = Assumption::where([
            'assumption_id' => $assumption_id,
            'project_id' => $project_id
        ])->first();

        return response()->json($assumption, 200);
    }

    public function get_all(Request $request): JsonResponse
    {
        $project_id = $request->input('projId');

        $assumptions = Assumption::select([
            'assumption_id',
            'assumption_title as title',
            'creation_date as created_at',
            'created_by',
            'last_update_date as updated_at',
            'last_updated_by as updated_by'
        ])
            ->where('project_id', $project_id)
            ->get();

        return response()->json($assumptions, 200);
    }

    public function create(Request $request): JsonResponse
    {
        $project_id = $request->input('projId');
        $title = $request->input('title');
        $content = $request->input('content');
        $author = $request->user->full_name;

        if ($title ===null || trim($title) == '') {
            return response()->json([
                'message' => 'Title cannot be empty'
            ], 500);
        }

        $last_assumption = Assumption::select('assumption_id')
            ->orderByDesc('assumption_id')
            ->first();
        $last_assumption_id = $last_assumption === null ? "ASS000" : $last_assumption->assumption_id;
        $last_assumption_id_number_str = substr($last_assumption_id, -3);
        $last_assumption_id_number = intval($last_assumption_id_number_str);
        $assumption_number = $last_assumption_id_number + 1;
        $assumption_number_str = str_pad($assumption_number, 3, "0", STR_PAD_LEFT);
        $assumption_id = "ASS" . $assumption_number_str;

        $assumption = new Assumption();
        $assumption->assumption_id = $assumption_id;
        $assumption->project_id = $project_id;
        $assumption->assumption_title = $title;
        $assumption->content = $content;
        $assumption->creation_date = date('Y-m-d H:i');
        $assumption->created_by = $author;
        $assumption->save();

        return response()->json($assumption, 200);
    }

    public function update(Request $request): JsonResponse
    {
        $assumption_id = $request->input('assumptionId');
        $project_id = $request->input('projId');
        $title = $request->input('title');
        $content = $request->input('content');
        $author = $request->user->full_name;

        $assumption = Assumption::where([
            'assumption_id' => $assumption_id,
            'project_id' => $project_id
        ])->first();

        if ($assumption === null) {
            return response()->json(['message' => 'Assumption not found'], 500);
        }

        Assumption::where([
            'assumption_id' => $assumption_id,
            'project_id' => $project_id
        ])->update([
            'assumption_title' => $title,
            'content' => $content,
            'last_updated_by' => $author,
            'last_update_date' => date('Y-m-d H:i')
        ]);

        return response()->json($assumption, 200);
    }

    public function delete(Request $request): JsonResponse
    {
        $assumption_id = $request->input('assumptionId');

        Assumption::where('assumption_id', $assumption_id)->delete();

        return response()->json(['success' => true], 200);
    }
}
