<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEntityBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Generic email sent by the send_email workflow action. The builder supplies
 * a subject and body (both already rendered from {{field}} templates by the
 * WorkflowEngine), so this Mailable is intentionally dumb — it just frames the
 * pre-rendered strings in the shared email layout with default branding.
 *
 * Sent synchronously by WorkflowEngine (after the workflow transaction
 * commits), consistent with the app's no-ShouldQueue mail decision.
 */
class WorkflowEmail extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesEntityBranding;

    public function __construct(
        public string $subjectLine,
        public string $body,
    ) {}

    public function build(): self
    {
        return $this
            ->subject($this->subjectLine)
            ->view('emails.workflow')
            ->with([
                ...$this->getEntityData(null),
                'subjectLine' => $this->subjectLine,
                'body' => $this->body,
            ]);
    }
}
