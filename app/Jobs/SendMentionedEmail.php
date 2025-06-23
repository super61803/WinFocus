<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use App\Mail\MentionedEmail;
use App\Models\Project;
use App\Models\User;

class SendMentionedEmail implements ShouldQueue
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
        $text = $this->data['text'];
        $message = $this->data['message'];
        $author = $this->data['author'];
        $datetime = $this->data['datetime'];

        $project = Project::find($project_id);
        $project_name = $project->project_name;

        $chunks = explode("\"", $text);
        foreach ($chunks as $chunk) {
            if (str_starts_with($chunk, "mailto:")) {
                $email = substr($chunk, 7);
                $user = User::where('email', $email)->first();
                Mail::to($email)->send(new MentionedEmail([
                    'project_name' => $project_name,
                    'user_name' => $user->full_name,
                    'message' => $message,
                    'author' => $author,
                    'datetime' => $datetime,
                ]));
            }
        }
    }
}
