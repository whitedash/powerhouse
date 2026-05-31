<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEntityBranding;
use App\Models\Invoice;
use App\Models\ReminderTemplate;
use App\Services\ReminderTemplateService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Tiered payment reminder. Subject + body come from the editable
 * ReminderTemplate system (rendered against the invoice), so the manual
 * and automated reminder paths produce identical copy.
 */
class InvoiceReminder extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesEntityBranding;

    public function __construct(
        public Invoice $invoice,
        public ReminderTemplate $template,
    ) {}

    public function build(): self
    {
        $this->invoice->loadMissing(['customer.primaryContact', 'billingEntity']);

        $rendered = app(ReminderTemplateService::class)->renderTemplate($this->template, $this->invoice);

        return $this
            ->subject($rendered['subject'])
            ->view('emails.reminder')
            ->with([
                ...$this->getEntityData($this->invoice->billingEntity),
                'body' => $rendered['body'],
                'invoice' => $this->invoice,
                'tier' => $this->template->tier,
                'dueDate' => $this->invoice->due_date?->format('d M Y'),
                'payPortalUrl' => rtrim((string) config('app.url'), '/').'/portal',
            ]);
    }
}
