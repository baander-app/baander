<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ConcurrentAccessNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $currentIp,
        private readonly array $concurrentIps,
        private readonly string $userAgent,
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('CRITICAL: Multiple locations detected for your account')
            ->line('We detected your account being used from multiple locations simultaneously.')
            ->line("Current IP: {$this->currentIp}")
            ->line("Other concurrent IPs: " . implode(', ', $this->concurrentIps))
            ->line("User Agent: {$this->userAgent}")
            ->line('All your sessions have been terminated for security.')
            ->action('Secure Your Account', url('/login'))
            ->line('If this was not you, please change your password immediately.');
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'concurrent_access',
            'current_ip' => $this->currentIp,
            'concurrent_ips' => $this->concurrentIps,
            'user_agent' => $this->userAgent,
            'severity' => 'critical',
        ];
    }
}