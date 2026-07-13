<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SignalAlertMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $subjectLine,
        public readonly string $bodyText
    ) {
    }

    public function build(): self
    {
        return $this->subject($this->subjectLine)
            ->text('mail.signal-alert')
            ->with([
                'bodyText' => $this->bodyText,
            ]);
    }
}
