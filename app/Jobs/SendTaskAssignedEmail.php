<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use App\Mail\TaskAssignedEmail;
use App\Models\Project;
use App\Models\User;

class SendTaskAssignedEmail implements ShouldQueue
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

        $project = Project::find($project_id);
        $project_owner_id = $project->project_owner;
        $project_owner = User::find($project_owner_id);
        $project_owner_name = $project_owner->full_name;

        $task_name = $this->data['task_name'];
        $old_resource = $this->data['old_resource'];
        $new_resource = $this->data['new_resource'];

        $author = $this->data['author'];
        $author_role = $this->data['author_role'];
        $datetime = $this->data['datetime'];

        $old_resources = explode(',', $old_resource);
        $new_resources = explode(',', $new_resource);

        foreach ($new_resources as $resource) {
            if ($resource !== "" && in_array($resource, $old_resources) == false) {
                $user = User::find($resource);
                $email = $user->email;

                Mail::to($email)->send(new TaskAssignedEmail([
                    'project_name' => $project->project_name,
                    'project_owner' => $project_owner_name,
                    'task_name' => $task_name,
                    'user_name' => $user->full_name,
                    'type' => 'assign',
                    'author' => $author,
                    'author_role' => $author_role,
                    'datetime' => $datetime,
                ]));
            }
        }

        foreach ($old_resources as $resource) {
            if ($resource !== "" && in_array($resource, $new_resources) == false) {
                $user = User::find($resource);
                $email = $user->email;

                Mail::to($email)->send(new TaskAssignedEmail([
                    'project_name' => $project->project_name,
                    'project_owner' => $project_owner_name,
                    'task_name' => $task_name,
                    'user_name' => $user->full_name,
                    'type' => 'unassign',
                    'author' => $author,
                    'author_role' => $author_role,
                    'datetime' => $datetime,
                ]));
            }
        }
    }
}
