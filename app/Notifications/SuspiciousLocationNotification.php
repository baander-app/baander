<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SuspiciousLocationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly array  $locationData,
        private readonly string $ipAddress,
        private readonly string $userAgent,
    )
    {
    }

    public function via($notifiable): array
    {
        return ['mail']; // Add 'database', 'sms', etc. as needed
    }

    public function toMail($notifiable): MailMessage
    {
        $location = $this->locationData['city']
            ? "{$this->locationData['city']}, {$this->locationData['country_name']}"
            : $this->locationData['country_name'];

        return (new MailMessage)
            ->subject('Security Alert: New Location Access')
            ->greeting("Hello {$notifiable->name},")
            ->line('We detected a login to your account from a new location.')
            ->line("Location: {$location}")
            ->line("IP Address: {$this->ipAddress}")
            ->line("Device: {$this->userAgent}")
            ->line("Time: " . now()->format('Y-m-d H:i:s T'))
            ->line('If this was you, you can ignore this message. If you don\'t recognize this activity, please secure your account immediately.')
            ->action('Secure My Account', url('/profile/security'))
            ->line('Thank you for using our application!');
    }

    public function toArray($notifiable): array
    {
        return [
            'type'       => 'suspicious_location',
            'location'   => $this->locationData,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'timestamp'  => now()->toISOString(),
        ];
    }
}