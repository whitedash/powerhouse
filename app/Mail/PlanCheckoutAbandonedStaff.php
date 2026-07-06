<?php

namespace App\Mail;

use App\Models\PlanCheckoutAttempt;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Staff alert for an abandoned Plans-widget checkout — same channel as
 * SupportTicketStaffAlert (email to the staff inbox), sent exactly once
 * per attempt by the plans:reconcile-abandoned-checkouts sweep. Goes to
 * STAFF, never the visitor: an unsolicited "you didn't finish buying"
 * mail is a follow-up decision for a human, not an automation.
 */
class PlanCheckoutAbandonedStaff extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public PlanCheckoutAttempt $attempt) {}

    public function build(): self
    {
        $this->attempt->loadMissing('planPrice.plan.product');

        return $this
            ->subject('Abandoned plan checkout — '.$this->attempt->purchaser_email)
            ->view('emails.plan-checkout-abandoned-staff')
            ->with([
                'attempt' => $this->attempt,
                'productName' => $this->attempt->planPrice?->plan?->product?->name,
                'planName' => $this->attempt->planPrice?->plan?->name,
            ]);
    }
}
