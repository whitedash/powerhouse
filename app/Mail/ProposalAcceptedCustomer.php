<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEntityBranding;
use App\Models\Proposal;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Customer-facing acceptance confirmation, with the stamped (accepted) PDF
 * attached when it's been generated.
 */
class ProposalAcceptedCustomer extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesEntityBranding;

    public function __construct(public Proposal $proposal) {}

    public function build(): self
    {
        $this->proposal->loadMissing(['customer.primaryContact', 'billingEntity']);

        $contact = $this->proposal->customer->primaryContact;

        $mail = $this
            ->subject('Proposal accepted — '.$this->proposal->reference)
            ->view('emails.proposal-accepted-customer')
            ->with([
                ...$this->getEntityData($this->proposal->billingEntity),
                'proposal' => $this->proposal,
                'contactName' => $contact->name ?? $this->proposal->customer->name,
            ]);

        $path = $this->proposal->accepted_pdf_path;
        if ($path && Storage::disk('private')->exists($path)) {
            $mail->attachData(
                Storage::disk('private')->get($path),
                $this->proposal->reference.'-accepted.pdf',
                ['mime' => 'application/pdf'],
            );
        }

        return $mail;
    }
}
