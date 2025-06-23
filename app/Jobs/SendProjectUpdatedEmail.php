<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use App\Mail\ProjectUpdatedEmail;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

class SendProjectUpdatedEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function handle(): void
    {
        $project_id = $this->data['project_id'];
        $updated_tasks = $this->data['updated_tasks'];
        $author = $this->data['author'];
        $author_role = $this->data['author_role'];
        $datetime = $this->data['datetime'];

        $project = Project::find($project_id);
        $project_owner_id = $project->project_owner;

        $project_owner = User::find($project_owner_id);
        $project_owner_name = $project_owner->full_name;

        $tasks = Task::where('project_id', $project_id)->get();

        $assigned_user_ids = [$project_owner_id];

        foreach ($tasks as $task) {
            $resources = explode(',', $task->resources);
            foreach ($resources as $resource) {
                if ($resource !== "" && in_array($resource, $assigned_user_ids) === false) {
                    $assigned_user_ids[] = $resource;
                }
            }
        }

        foreach ($assigned_user_ids as $assigned_user_id) {
            $user = User::find($assigned_user_id);
            $email = $user->email;
            $full_name = $user->full_name;
            Mail::to($email)->send(new ProjectUpdatedEmail([
                'project_name' => $project->project_name,
                'project_owner' => $project_owner_name,
                'user_name' => $full_name,
                'updated_tasks' => $updated_tasks,
                'author' => $author,
                'author_role' => $author_role,
                'datetime' => $datetime,
            ]));
        }
    }
}
