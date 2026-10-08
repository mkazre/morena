<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Log;

class LogChannel implements NotificationChannel
{
    public function send(string $to, string $message): void
    {
        Log::info('[client message]', ['to' => $to, 'message' => $message]);
    }
}
