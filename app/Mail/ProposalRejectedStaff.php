<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEntityBranding;
use App\Models\Proposal;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Internal alert to the staff member who created a proposal, the moment a
 * customer declines it online. Carries the rejection audit detail. Mirror
 * of ProposalAcceptedStaff.
 */
class ProposalRejectedStaff extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesEntityBranding;

    public function __construct(public Proposal $proposal) {}

    public function build(): self
    {
        $this->proposal->loadMissing(['customer', 'billingEntity']);

        return $this
            ->subject('Proposal declined — '.$this->proposal->reference)
            ->view('emails.proposal-rejected-staff')
            ->with([
                ...$this->getEntityData($this->proposal->billingEntity),
                'proposal' => $this->proposal,
                'customerName' => $this->proposal->customer->name,
                'rejectedAt' => $this->proposal->rejected_at?->format('d M Y H:i'),
                'rejectionReason' => $this->proposal->rejection_reason,
                'internalUrl' => rtrim((string) config('app.url'), '/').'/proposals/'.$this->proposal->id,
            ]);
    }
}
