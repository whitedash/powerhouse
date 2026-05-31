<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEntityBranding;
use App\Models\Proposal;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Internal alert to the staff member who created a proposal, the moment a
 * customer accepts it online. Carries the acceptance audit detail.
 */
class ProposalAcceptedStaff extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesEntityBranding;

    public function __construct(public Proposal $proposal) {}

    public function build(): self
    {
        $this->proposal->loadMissing(['customer', 'billingEntity']);

        return $this
            ->subject('Proposal accepted — '.$this->proposal->reference)
            ->view('emails.proposal-accepted-staff')
            ->with([
                ...$this->getEntityData($this->proposal->billingEntity),
                'proposal' => $this->proposal,
                'customerName' => $this->proposal->customer->name,
                'acceptedAt' => $this->proposal->accepted_at?->format('d M Y H:i'),
                'acceptedByName' => $this->proposal->accepted_by_name,
                'acceptedIp' => $this->proposal->accepted_ip,
                'internalUrl' => rtrim((string) config('app.url'), '/').'/proposals/'.$this->proposal->id,
            ]);
    }
}
