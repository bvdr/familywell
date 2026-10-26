<?php

namespace App\Mail;

use App\Support\Auth\Handoff;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// "Open FamilyWell on your phone": the onboarding's emailed sign-in link (App\Support\Auth\Handoff).
class HandoffLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your FamilyWell sign-in link');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.handoff-link', with: ['url' => $this->url, 'minutes' => Handoff::TTL_MINUTES]);
    }
}
