<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Task Assignment Update</title>
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
        <p class="mb-4">This is a system-generated message regarding tasks on Project {{ $mailData['project_name'] }}.</p>

        <p><b>{{ $mailData['type'] === "assign" ? "New Tasks Assigned" : "Tasks Unassigned" }}</b></p>
        <p class="mb-4">Task: {{ $mailData['task_name'] }}</p>

        <p>Updated by: {{ $mailData['author']."(".$mailData['author_role'].")" }}</p>
        <p class="mb-4">Updated at: {{ $mailData['datetime'] }}</p>

        <p class="mb-4">These updates reflect the latest changes in your task assignments. Please review them and ensure you're aligned with your updated responsibilities. Please reach out to your Project Owner - {{ $mailData['project_owner'] }} if you have any questions.</p>
        <hr/>
        <p>Note: if there are no new tasks assigned or no unassigned. Then do not display those sections.</p>
    </div>
</body>
</html>
