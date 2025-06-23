<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Models\Comment;
use App\Models\Task;
use App\Events\MessageEvent;
use App\Jobs\SendMentionedEmail;

class CommentController extends Controller
{
    public function get(Request $request): JsonResponse
    {
        $user_id = $request->user->id;
        $project_id = $request->input('id');

        $project = Project::where('project_id', $project_id)->first();

        if (!$project) {
            return response()->json([
                'message' => 'Project Not Found',
            ], 500);
        }

        $tasks = Task::where('project_id', $project_id)->whereRaw("FIND_IN_SET(?, resources)", [$user_id])->get();

        if (count($tasks) === 0 && $project->project_owner !== $user_id) {
            return response()->json([
                'hasPermission' => false,
                'data' => []
            ], 200);
        }

        $comments = DB::table('cxs_projects_comments')
            ->where('project_id', $project_id)
            ->leftJoin('cxs_user', 'cxs_user.id', '=', 'cxs_projects_comments.user_id')
            ->select(
                'cxs_projects_comments.*',
                'cxs_user.full_name as user_name',
                DB::raw('COALESCE(cxs_user.full_name, "Someone") as user_name')
            )
            ->get();

        return response()->json([
            'hasPermission' => true,
            'data' => $comments
        ], 200);
    }

    public function add(Request $request): JsonResponse
    {
        $user_id = $request->user->id;
        $text = $request->input('text');
        $html_text = $request->input('htmlText');
        $project_id = $request->input('projectId');
        $author = $request->user->full_name;

        $comment = new Comment;

        $comment->user_id = $user_id;
        $comment->text = $text;
        $comment->html_text = $html_text;
        $now = new \DateTime();
        $comment->datetime = $now->format(\DateTime::ATOM);
        $comment->project_id = $project_id;

        $comment->save();

        SendMentionedEmail::dispatch([
            'project_id' => $project_id,
            'text' => $html_text,
            'message' => $text,
            'author' => $author,
            'datetime' => date('m/d/Y H:i')
        ]);

        $comment->user_name = $request->user->full_name;

        event(new MessageEvent(json_encode($comment)));

        return response()->json($comment, 200);
    }
}
