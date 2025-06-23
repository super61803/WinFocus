<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Project;
use App\Models\Task;
use App\Models\Requirement;
use App\Models\Assumption;

class ProjectController extends Controller
{
    public function getProjects($companyid)
    {
        $projects = Project::where('company_id', $companyid)->get();
        return $projects;
    }
    public function create(Request $request): JsonResponse
    {
        $project_name = $request->get('name');
        $project_owner = $request->get('owner');
        $company_id = $request->user->company_id;
        $author = $request->user->full_name;

        if ($project_name === null || trim($project_name) === '') {
            return response()->json([
                'name' => 'Bad Request',
                'target' => 'name',
                'message' => 'Project name is required'
            ], 500);
        }

        $project = new Project;

        $project->project_name = $project_name;
        $project->project_owner = $project_owner;
        $project->company_id = $company_id;
        $project->created_by = $author;
        $project->creation_date = date('Y-m-d H:i');

        $project->save();

        $projects = $this->getProjects($request->user->company_id);

        return response()->json([
            'projects' => $projects,
            'id' => $project->project_id
        ], 201);
    }

    public function update(Request $request): JsonResponse
    {
        $project_id = $request->input('id');
        $from_date = $request->input('fromDate');
        $to_date = $request->input('toDate');
        $owner = $request->input('owner', 0);
        $author = $request->user->full_name;

        $project = Project::find($project_id);

        $project->schedule_start_date = $from_date;
        $project->schedule_end_date = $to_date;
        $project->project_owner = $owner;
        $project->last_updated_by = $author;
        $project->last_update_date = date("Y-m-d H:i");

        $project->save();
        $projects = $this->getProjects($request->user->company_id);

        return response()->json([
            'projects' => $projects,
        ], 200);
    }

    public function close(Request $request): JsonResponse
    {
        $project_id = $request->input('id');

        $project = Project::find($project_id);

        $project->project_status = 'Closed';

        $project->save();

        $projects = $this->getProjects($request->user->company_id);

        return response()->json([
            'projects' => $projects,
        ], 200);
    }

    public function documents(Request $request): JsonResponse
    {
        $project_id = $request->input('id');

        $requirements = Requirement::where('project_id', $project_id)->get();
        $assumptions = Assumption::where('project_id', $project_id)->get();

        return response()->json([
            'requirements' => $requirements,
            'assumptions' => $assumptions,
        ], 200);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $id = $request->user->id;

        $projects = DB::table('cxs_projects')
            ->leftJoin('cxs_user', 'cxs_user.id', '=', 'cxs_projects.project_owner')
            ->select(
                'cxs_projects.project_id',
                'cxs_projects.project_name',
                'cxs_projects.schedule_start_date',
                'cxs_projects.schedule_end_date',
                'cxs_projects.project_owner',
                DB::raw('COALESCE(cxs_user.full_name, "No Owner") as project_owner_name')
            )
            ->get();
        $assigned_projects = [];
        $assigned_tasks = Task::leftJoin('cxs_projects', 'cxs_projects.project_id', '=', 'cxs_projects_tasks.project_id')
            ->select(
                'cxs_projects_tasks.project_id',
                'cxs_projects_tasks.task_id',
                'cxs_projects_tasks.task_name',
                'cxs_projects_tasks.schedule_start_date',
                'cxs_projects_tasks.schedule_end_date',
                'cxs_projects_tasks.resources',
                'cxs_projects_tasks.progress',
                DB::raw('COALESCE(cxs_projects.project_name, "Unnamed") as project_name')
            )
            ->whereRaw("FIND_IN_SET(?, resources)", [$id])->get();

        $projects_cnt = 0;
        $projects_total_cnt = count($projects);

        $tasks_assigned_cnt = count($assigned_tasks);
        $tasks_overdue_cnt = 0;
        $tasks_critical_cnt = 0;
        $tasks_total_cnt = DB::table('cxs_projects_tasks')->count();;

        foreach ($projects as $project) {
            if ($project->project_owner == $id) {
                $project->sum_progress = DB::table('cxs_projects_tasks')->where('project_id', $project->project_id)->sum('progress');
                $project->assigned_tasks_cnt = DB::table('cxs_projects_tasks')->where('project_id', $project->project_id)->count();
                $project->is_assigned = true;
            } else {
                $project->sum_progress = 0;
                $project->assigned_tasks_cnt = 0;
                $project->is_assigned = false;

                foreach ($assigned_tasks as $assigned_task) {
                    if ($assigned_task->project_id == $project->project_id) {
                        $project->assigned_tasks_cnt ++;
                        $project->sum_progress += $assigned_task->progress;
                        $project->is_assigned = true;
                    }
                }
            }

            if ($project->is_assigned) {
                $now = new \DateTime();
                $yesterday = $now->sub(new \DateInterval('P1D'));

                $comments = DB::table('cxs_projects_comments')
                    ->where('project_id', $project->project_id)
                    ->leftJoin('cxs_user', 'cxs_user.id', '=', 'cxs_projects_comments.user_id')
                    ->select(
                        'cxs_projects_comments.id',
                        'cxs_projects_comments.text',
                        'cxs_projects_comments.datetime',
                        DB::raw('COALESCE(cxs_user.full_name, "Someone") as user_name')
                    )
                    ->where('datetime', '>=', $yesterday)
                    ->orderBy('datetime', 'desc')
                    ->get();

                $projects_cnt ++;
                $project->overall_progress = $project->assigned_tasks_cnt > 0 ? $project->sum_progress / $project->assigned_tasks_cnt : 0;
                $project->comments = $comments;
                array_push($assigned_projects, $project);
            }
        }

        foreach ($assigned_tasks as $assigned_task) {
            $datetime1 = \DateTime::createFromFormat('Y-m-d', $assigned_task->schedule_end_date);
            $datetime2 = \DateTime::createFromFormat('Y-m-d', date("Y-m-d"));

            if ($datetime1 < $datetime2 && $assigned_task->progress < 100) {
                $tasks_overdue_cnt ++;
            }
        }

        return response()->json([
            'projects' => $assigned_projects,
            'tasks' => $assigned_tasks,
            'projects_cnt' => $projects_cnt,
            'projects_total_cnt' => $projects_total_cnt,
            'tasks_assigned_cnt' => $tasks_assigned_cnt,
            'tasks_critical_cnt' => $tasks_critical_cnt,
            'tasks_overdue_cnt' => $tasks_overdue_cnt,
            'tasks_total_cnt' => $tasks_total_cnt,
        ], 200);
    }
}
