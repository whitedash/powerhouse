<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEntityBranding;
use App\Models\Proposal;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Sends a proposal to the customer with the PDF attached and an online
 * acceptance link (the token minted at send-time).
 */
class ProposalSent extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesEntityBranding;

    public function __construct(public Proposal $proposal) {}

    public function build(): self
    {
        $this->proposal->loadMissing(['customer.primaryContact', 'billingEntity']);

        $contact = $this->proposal->customer->primaryContact;
        $acceptUrl = $this->proposal->acceptance_token
            ? route('proposal.accept.show', $this->proposal->acceptance_token)
            : null;

        $mail = $this
            ->subject('Proposal '.$this->proposal->reference.($this->proposal->billingEntity ? ' from '.$this->proposal->billingEntity->legal_name : ''))
            ->view('emails.proposal-sent')
            ->with([
                ...$this->getEntityData($this->proposal->billingEntity),
                'proposal' => $this->proposal,
                'contactName' => $contact->name ?? $this->proposal->customer->name,
                'validUntil' => $this->proposal->valid_until?->format('d M Y'),
                'acceptUrl' => $acceptUrl,
            ]);

        // Attach the stored unsigned PDF if it exists on the private disk.
        if ($this->proposal->pdf_path && Storage::disk('private')->exists($this->proposal->pdf_path)) {
            $mail->attachData(
                Storage::disk('private')->get($this->proposal->pdf_path),
                $this->proposal->reference.'.pdf',
                ['mime' => 'application/pdf'],
            );
        }

        return $mail;
    }
}
