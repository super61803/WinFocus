<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>You've been Mentioned</title>
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

        <p class="mb-4">This is a system-generated message.</p>

        <p class="mb-4">You've been mentioned in a recent team collaboration for {{ $mailData['project_name'] }}. Here are the details:</p>

        <p><b>Message</b></p>
        <div class="mb-4">{{ $mailData['message'] }}</div>

        <p>Received from: {{ $mailData['author'] }}</p>
        <p class="mb-4">Received at: {{ $mailData['datetime'] }}</p>

        <p class="mb-4">You can review the conversation and respond directly by accessing the team's collaboration space.</p>
        <hr/>
    </div>
</body>
</html>
