<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Jobs\SendProjectUpdatedEmail;
use App\Jobs\SendTaskAssignedEmail;

class TaskController extends Controller
{
    public function getTasks(string $project_id)
    {
        $tasks = Task::select(
            'task_id as TaskID',
            'task_name as TaskName',
            'task_number as TaskNumber',
            'task_index as TaskIndex',
            'schedule_start_date as StartDate',
            'schedule_end_date as EndDate',
            'actual_start_date as ActualStartDate',
            'actual_end_date as ActualEndDate',
            'parent_task_id as ParentTaskId',
            'progress as Progress',
            'task_dependency as Predecessor',
            'resources as Resources',
            'milestone as Milestone',
        )->where('project_id', $project_id)->get();

        return $tasks;
    }

    public function get(Request $request): JsonResponse
    {
        $project_id = $request->input('id');

        $tasks = $this->getTasks($project_id);

        return response()->json([
            'tasks' => $tasks
        ], 200);
    }

    public function update(Request $request): JsonResponse
    {
        $project_id = $request->input('id');
        $updates = $request->input('data');
        $company_id = $request->user->company_id;
        $author = $request->user->full_name;
        $author_role = $request->user->user_role;

        $updated_tasks = [];

        $tasks = Task::where('project_id', $project_id)->get();

        $ids_new = [];
        $ids_orig = [];
        $saved_ids = [];
        $new_parent_ids = [];

        foreach ($updates as $update) {
            $ids_new[] = $update['TaskID'];

            if ($update['TaskName'] === null || trim($update['TaskName']) === '') {
                return response()->json([
                    'name' => 'Bad Request',
                    'target' => 'TaskName',
                    'message' => 'Task name is required'
                ], 500);
            }

            $schedule_start_date = $update['StartDate'] ? new \DateTime($update['StartDate']) : null;
            $schedule_end_date = $update['EndDate'] ? new \DateTime($update['EndDate']) : null;

            if ($schedule_start_date === null) {
                return response()->json([
                    'name' => 'Bad Request',
                    'target' => 'StartDate',
                    'message' => 'Start Date of Task "' . $update['TaskName'] .'" is required'
                ], 500);
            }
            if ($schedule_end_date === null) {
                return response()->json([
                    'name' => 'Bad Request',
                    'target' => 'EndDate',
                    'message' => 'End Date of Task "' . $update['TaskName'] .'" is required'
                ], 500);
            }
        }

        foreach ($tasks as $task) {
            $ids_orig[] = $task->task_id;
        }

        foreach ($tasks as $task) {
            if (in_array($task->task_id, $ids_new)) {
                // Update
                foreach ($updates as $update) {
                    if ($update['TaskID'] == $task->task_id) {
                        if ($task->progress !== $update['Progress']) {
                            $updated_task = [
                                'task_name' => $task->task_name,
                                'old_progress' => $task->progress,
                                'new_progress' => $update['Progress'],
                            ];
                            $updated_tasks[] = $updated_task;
                        }

                        $schedule_start_date = $update['StartDate'] ? new \DateTime($update['StartDate']) : null;
                        $schedule_end_date = $update['EndDate'] ? new \DateTime($update['EndDate']) : null;
                        $actual_start_date = $update['ActualStartDate'] ? new \DateTime($update['ActualStartDate']) : null;
                        $actual_end_date = $update['ActualEndDate'] ? new \DateTime($update['ActualEndDate']) : null;

                        $task->task_name = (string) $update['TaskName'];
                        $task->task_index = (int) ($update['TaskIndex'] ?? 999);
                        $task->schedule_start_date = $schedule_start_date ? $schedule_start_date->format('Y-m-d') : null;
                        $task->schedule_end_date = $schedule_end_date ? $schedule_end_date->format('Y-m-d') : null;
                        $task->actual_start_date = $actual_start_date ? $actual_start_date->format('Y-m-d') : null;
                        $task->actual_end_date = $actual_end_date ? $actual_end_date->format('Y-m-d') : null;
                        $task->parent_task_id = (int) ($update['ParentTaskId'] ?? 0);
                        $task->resources = (string) ($update['Resources'] ?? '');
                        $task->milestone = (string) ($update['Milestone'] ?? 'Planned');
                        $task->task_dependency = (string) ($update['Predecessor'] ?? '');
                        $task->progress = (int) ($update['Progress'] ?? 0);
                        $task->last_updated_by = $author;
                        $task->last_update_date = date('Y-m-d H:i');

                        $task->save();

                        if ($update['NewParent']) {
                            $new_parent_ids[] = $task->task_id;
                        }
                    }
                }
            } else {
                // Delete
                $task->delete();
            }
        }

        foreach ($updates as $update) {
            if (!in_array($update['TaskID'], $ids_orig)) {
                // Create
                $schedule_start_date = $update['StartDate'] ? new \DateTime($update['StartDate']) : null;
                $schedule_end_date = $update['EndDate'] ? new \DateTime($update['EndDate']) : null;
                $actual_start_date = $update['ActualStartDate'] ? new \DateTime($update['ActualStartDate']) : null;
                $actual_end_date = $update['ActualEndDate'] ? new \DateTime($update['ActualEndDate']) : null;

                $new_task = new Task;

                $last_task = Task::select('task_number')
                    ->where('project_id', $project_id)
                    ->orderByDesc('task_number')
                    ->first();

                if ($last_task === null) {
                    $last_task_number = '0';
                } else {
                    $last_task_number = substr($last_task->task_number, 13);
                }

                $task_num = str_pad(((int) $last_task_number) + 1, 4, 0, STR_PAD_LEFT);
                $project_num = str_pad($project_id, 5, 0, STR_PAD_LEFT);

                $task_number = "CXS".$project_num.".INI.".$task_num;

                $new_task->project_id = $project_id;
                $new_task->task_name = (string) $update['TaskName'];
                $new_task->task_index = (int) ($update['TaskIndex'] ?? 999);
                $new_task->task_number = $task_number;
                $new_task->schedule_start_date = $schedule_start_date ? $schedule_start_date->format('Y-m-d') : null;
                $new_task->schedule_end_date = $schedule_end_date ? $schedule_end_date->format('Y-m-d') : null;
                $new_task->actual_start_date = $actual_start_date ? $actual_start_date->format('Y-m-d') : null;
                $new_task->actual_end_date = $actual_end_date ? $actual_end_date->format('Y-m-d') : null;
                $new_task->company_id = $company_id;
                $new_task->resources = (string) ($update['Resources'] ?? '');
                $new_task->milestone = (string) ($update['Milestone'] ?? 'Planned');
                $new_task->parent_task_id = (int) ($update['ParentTaskId'] ?? 0);
                $new_task->task_dependency = (string) ($update['Predecessor'] ?? '');
                $new_task->progress = (int) ($update['Progress'] ?? 0);
                $new_task->created_by = $author;
                $new_task->creation_date = date('Y-m-d H:i');

                $new_task->save();

                if ($update['New']) {
                    $saved_ids[$update['TaskID']] = $new_task->task_id;
                }

                if ($update['NewParent']) {
                    $new_parent_ids[] = $new_task->task_id;
                }
            }
        }

        foreach ($new_parent_ids as $parent_task_id) {
            $task = Task::find($parent_task_id);
            $task->parent_task_id = $saved_ids[$task->parent_task_id];
            $task->save();
        }

        $tasks = $this->getTasks($project_id);

        SendProjectUpdatedEmail::dispatch([
            'project_id' => $project_id,
            'updated_tasks' => $updated_tasks,
            'author' => $author,
            'author_role' => $author_role,
            'datetime' => date('m/d/Y H:i')
        ]);

        return response()->json([
            'tasks' => $tasks
        ], 200);
    }

    public function update_resource(Request $request): JsonResponse
    {
        $project_id = $request->input('id');
        $updates = $request->input('data');
        $author = $request->user->full_name;
        $author_role = $request->user->user_role;

        $reassigned_tasks = [];

        foreach ($updates as $update) {
            $task = Task::find($update['TaskID']);

            if ($task->resources !== $update['Resources']) {
                $reassigned_task = [
                    'task_name' => $task->task_name,
                    'old_resource' => $task->resources,
                    'new_resource' => $update['Resources'],
                ];
                $reassigned_tasks[] = $reassigned_task;
            }

            $task->resources = (string) ($update['Resources'] ?? '');
            $task->milestone = (string) ($update['Milestone'] ?? 'Planned');

            $task->last_updated_by = $author;
            $task->last_update_date = date('Y-m-d H:i');

            $task->save();
        }

        foreach ($reassigned_tasks as $reassigned_task) {
            SendTaskAssignedEmail::dispatch([
                'project_id' => $project_id,
                'task_name' => $reassigned_task['task_name'],
                'old_resource' => $reassigned_task['old_resource'],
                'new_resource' => $reassigned_task['new_resource'],
                'author' => $author,
                'author_role' => $author_role,
                'datetime' => date('m/d/Y H:i')
            ]);
        }

        $tasks = $this->getTasks($project_id);

        return response()->json([
            'tasks' => $tasks
        ], 200);
    }

    public function schedule_variance(Request $request): JsonResponse
    {
        $project_id = $request->input('id');
        $task_ids = $request->input('tasks');

        if ($task_ids === null) {
            return response()->json([
                'message' => 'Bad Request'
            ], 500);
        }

        if (count($task_ids) === 0) {
            return response()->json([
                'labels' => [],
                'earned_values' => [],
                'hours_charged' => [],
                'schedule_variance' => [],
            ], 200);
        }

        $tasks = Task::select(
            'task_id',
            'task_name',
            'task_number',
            'schedule_start_date',
            'schedule_end_date',
            'actual_start_date',
            'actual_end_date',
            'parent_task_id',
            'progress',
            'task_dependency',
            'resources',
            'milestone',
        )
            ->where('project_id', $project_id)
            ->whereIn('task_id', $task_ids)
            ->get();

        $labels = [];
        $earned_values = [];
        $hours_charged = [];
        $schedule_variance = [];

        foreach ($tasks as $task) {
            $from = new \DateTime($task->schedule_start_date);
            $to = new \DateTime($task->schedule_end_date);
            $diff = $from->diff($to);
            $duration = $diff->days;

            $earned = 8 * $task['progress'] * $duration / 100;

            array_push($labels, $task->task_name);
            array_push($earned_values, $earned);

            $charged = DB::table('cxs_time_entry_lines')->where([
                'project_id' => $project_id,
                'task_id' => $task->task_id
            ])->sum('straight_time_hours');
            array_push($hours_charged, $charged);
            array_push($schedule_variance, $earned - $charged);
        }

        return response()->json([
            'labels' => $labels,
            'earned_values' => $earned_values,
            'hours_charged' => $hours_charged,
            'schedule_variance' => $schedule_variance,
        ], 200);
    }

    public function team_performance(Request $request): JsonResponse
    {
        $project_id = $request->input('id');

        $company_id = $request->user->company_id;
        $owners = DB::table('cxs_user as cu')
            ->select(['id', 'full_name as name', 'email'])
            ->where('user_status', 'Active')
            ->where('company_id', $company_id)
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('cxs_licensed_products as cp')
                    ->whereColumn('cp.user_id', 'cu.id');
            })
            ->orderBy('full_name')
            ->get();

        $labels = [];
        $total_duration = [];
        $time_charged = [];
        $tasks_assigned = [];
        $overall_progress = [];
        $performance_variance = [];

        foreach ($owners as $owner) {
            $tasks = Task::where('project_id', $project_id)
                ->whereRaw("FIND_IN_SET(?, resources)", [$owner->id])
                ->get();

            $duration = 0;
            $charged = DB::table('cxs_time_entry_lines')
                ->where([
                    'project_id' => $project_id,
                    'user_id' => $owner->id
                ])
                ->sum('straight_time_hours');
            $assigned = count($tasks);
            $completed = 0;

            foreach ($tasks as $task) {
                if ($task->progress === 100) {
                    $completed += 1;
                }

                $from = new \DateTime($task->schedule_start_date);
                $to = new \DateTime($task->schedule_end_date);
                $diff = $from->diff($to);
                $duration += ($diff->days * 8);
            }

            array_push($labels, $owner->name);
            array_push($total_duration, $duration);
            array_push($time_charged, $charged);
            array_push($tasks_assigned, $assigned);
            if ($assigned === 0) {
                array_push($overall_progress, 0);
            } else {
                array_push($overall_progress, ($completed / ($assigned - $completed) * 100));
            }
            array_push($performance_variance, $duration - $charged);
        }

        return response()->json([
            'labels' => $labels,
            'total_duration' => $total_duration,
            'time_charged' => $time_charged,
            'tasks_assigned' => $tasks_assigned,
            'overall_progress' => $overall_progress,
            'performance_variance' => $performance_variance
        ], 200);
    }
}
