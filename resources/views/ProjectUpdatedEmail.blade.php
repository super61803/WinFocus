<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Project Progress Update</title>
    <style>
        body {
            margin: 0;
            padding: 0;
        }

        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 24px;
        }

        h1, h2, h3, h4, h5, h6, p {
            margin: 0;
        }

        .my-4 {
            margin-top: 16px;
            margin-bottom: 16px;
        }

        .mb-4 {
            margin-bottom: 16px;
        }
    </style>
</head>

<body>
    <div class="container">
        <img src="https://ylot.com/cxspsa/images/header_logo.png" width="100" height="auto"/>
        <p class="my-4">{{ $mailData['user_name'] }},</p>
        <p class="mb-4">This is a system-generated update on the progress of Project {{ $mailData['project_name'] }}.</p>

        @foreach ($mailData['updated_tasks'] as $task)
            <p>Task: {{ $task['task_name'] }}</p>
            <p class="mb-4">Updated from {{ $task['old_progress'] }}% to {{ $task['new_progress'] }}%</p>
        @endforeach

        <p>Updated by: {{ $mailData['author']."(".$mailData['author_role'].")" }}</p>
        <p class="mb-4">Updated at: {{ $mailData['datetime'] }}</p>

        <p class="mb-4">Please review the updated information and reach out to your Project Owner - {{ $mailData['project_owner'] }} if you have any questions.</p>
        <hr/>
    </div>
</body>
</html>
