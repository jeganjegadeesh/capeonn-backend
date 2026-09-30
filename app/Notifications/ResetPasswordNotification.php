<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Log;

/**
 * Password reset email. Laravel's default builds a link to a web route that
 * doesn't exist in this API-only backend, so we point at the Flutter app instead
 * and also print the token, so mobile/desktop users can paste it into the app.
 */
class ResetPasswordNotification extends ResetPassword
{
    protected function resetUrl($notifiable): string
    {
        return rtrim(config('capeonn.frontend_url'), '/').'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
    }

    public function toMail($notifiable): MailMessage
    {
        $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        if (app()->environment('local')) {
            // With MAIL_MAILER=log the email body is quoted-printable and hard to read.
            Log::info('[dev] Password reset for '.$notifiable->getEmailForPasswordReset().' token='.$this->token);
        }

        return (new MailMessage)
            ->subject('Reset your Capeonn password')
            ->greeting('Hello '.($notifiable->name ?? '').',')
            ->line('We received a request to reset the password for your Capeonn account.')
            ->action('Reset password', $this->resetUrl($notifiable))
            ->line('If you are using the Capeonn mobile or desktop app, enter this reset code instead:')
            ->line($this->token)
            ->line("This code expires in {$minutes} minutes.")
            ->line('If you did not request this, you can safely ignore this email. Your password will not change.');
    }
}
