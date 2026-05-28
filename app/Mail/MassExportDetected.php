<?php

namespace App\Mail;

use App\Events\PaginatedListAccessed;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MassExportDetected extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public PaginatedListAccessed $event,
        public int $count,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Security] Mass-export pattern detected on Powerhouse',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<p>User #'.$this->event->userId.' has accessed '.$this->count
                .' paginated views on <code>'.$this->event->endpoint.'</code> in the last 10 minutes.</p>'
                .'<p>This is the alert threshold (50/10min). Review activity_log for context.</p>',
        );
    }
}
