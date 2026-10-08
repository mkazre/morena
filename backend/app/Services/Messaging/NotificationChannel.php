<?php

namespace App\Services\Messaging;

/**
 * Outbound client messaging (handover §1). Implementations: LogChannel (default / dev) and a future
 * WhatsAppBusinessChannel once the WhatsApp Business API credentials are configured.
 */
interface NotificationChannel
{
    public function send(string $to, string $message): void;
}
