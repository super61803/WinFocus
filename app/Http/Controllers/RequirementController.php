<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\Requirement;

class RequirementController extends Controller
{
    public function get(Request $request): JsonResponse
    {
        $req_id = $request->input('reqId');
        $project_id = $request->input('projId');

        $requirement = Requirement::where([
            'req_id' => $req_id,
            'project_id' => $project_id
        ])->first();

        return response()->json($requirement, 200);
    }

    public function get_all(Request $request): JsonResponse
    {
        $project_id = $request->input('projId');

        $requirements = Requirement::select([
            'req_id',
            'req_title as title',
            'severity',
            'creation_date as created_at',
            'created_by',
            'last_update_date as updated_at',
            'last_updated_by as updated_by'
        ])
            ->where('project_id', $project_id)
            ->get();

        return response()->json($requirements, 200);
    }

    public function create(Request $request): JsonResponse
    {
        $project_id = $request->input('projId');
        $title = $request->input('title');
        $severity = $request->input('severity');
        $content = $request->input('content');
        $author = $request->user->full_name;

        if ($title ===null || trim($title) == '') {
            return response()->json([
                'message' => 'Title cannot be empty'
            ], 500);
        }

        $last_requirement = Requirement::select('req_id')
            ->orderByDesc('req_id')
            ->first();
        $last_req_id = $last_requirement === null ? "REQ000" : $last_requirement->req_id;
        $last_req_id_number_str = substr($last_req_id, -3);
        $last_req_id_number = intval($last_req_id_number_str);
        $req_number = $last_req_id_number + 1;
        $req_number_str = str_pad($req_number, 3, "0", STR_PAD_LEFT);
        $req_id = "REQ" . $req_number_str;

        $requirement = new Requirement;
        $requirement->req_id = $req_id;
        $requirement->project_id = $project_id;
        $requirement->req_title = $title;
        $requirement->severity = $severity;
        $requirement->content = $content;
        $requirement->creation_date = date('Y-m-d H:i');
        $requirement->created_by = $author;
        $requirement->save();

        return response()->json($requirement, 200);
    }

    public function update(Request $request): JsonResponse
    {
        $req_id = $request->input('reqId');
        $project_id = $request->input('projId');
        $title = $request->input('title');
        $severity = $request->input('severity');
        $content = $request->input('content');
        $author = $request->user->full_name;

        $requirement = Requirement::where([
            'req_id' => $req_id,
            'project_id' => $project_id
        ])->first();

        if ($requirement === null) {
            return response()->json(['message' => 'Requirement not found'], 500);
        }

        Requirement::where([
            'req_id' => $req_id,
            'project_id' => $project_id
        ])->update([
            'req_title' => $title,
            'severity' => $severity,
            'content' => $content,
            'last_updated_by' => $author,
            'last_update_date' => date('Y-m-d H:i')
        ]);

        return response()->json($requirement, 200);
    }

    public function delete(Request $request): JsonResponse
    {
        $req_id = $request->input('reqId');

        Requirement::where('req_id', $req_id)->delete();

        return response()->json(['success' => true], 200);
    }
}
