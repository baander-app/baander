<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail as VerifyEmailLaravel;

class VerifyEmail extends VerifyEmailLaravel
{
    /**
     * Get the verification URL for the given notifiable.
     *
     * @param mixed $notifiable
     * @return string
     */
    protected function verificationUrl($notifiable)
    {
        $withToken = str_replace(['{id}'], $notifiable->getKey(), config('app.url') . '/verify/{id}/{emailHash}');
        $withEmail = str_replace(['{emailHash}'], sha1($notifiable->getEmailForVerification()), $withToken);

        return url($withEmail);
    }
}
