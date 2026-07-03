<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEntityBranding;
use App\Models\Proposal;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Company-facing rejection confirmation. Mirror of ProposalAcceptedCustomer,
 * minus the PDF attachment — a declined proposal produces no stamped document.
 */
class ProposalRejectedCustomer extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesEntityBranding;

    public function __construct(public Proposal $proposal) {}

    public function build(): self
    {
        $this->proposal->loadMissing(['customer.primaryContact', 'billingEntity']);

        $contact = $this->proposal->customer->primaryContact;

        return $this
            ->subject('Proposal declined — '.$this->proposal->reference)
            ->view('emails.proposal-rejected-customer')
            ->with([
                ...$this->getEntityData($this->proposal->billingEntity),
                'proposal' => $this->proposal,
                'contactName' => $contact->name ?? $this->proposal->customer->name,
            ]);
    }
}
