<?php

namespace App\Notifications;

use App\Support\Translations;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// The sign-in / sign-up code email (App\Support\Auth\EmailCode), designed in resources/views/mail/login-code
// after familywell.org.
// Sent right away, not queued: the person is waiting on the code screen. In the language the page was shown
// in, since there may be no account yet to read a language from.
class LoginCode extends Notification
{
    // The language rides on Notification's own $locale (set with ->locale() by EmailCode::send).
    public function __construct(public string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $t = fn (string $key) => str_replace(':code', $this->code, Translations::get("auth.code_email_{$key}", $this->locale ?? 'en'));
        $subject = $t('subject');

        return (new MailMessage)->subject($subject)->view('mail.login-code', [
            'locale' => $this->locale ?? 'en',
            'subject' => $subject,
            'preview' => $t('preview'),
            'eyebrow' => $t('eyebrow'),
            'heading' => $t('heading'),
            'intro' => $t('intro'),
            'code' => $this->code,
            'expires' => $t('expires'),
            'ignore' => $t('ignore'),
            'tagline' => $t('tagline'),
            'logo' => asset('images/email/logo.png'),
        ]);
    }
}
