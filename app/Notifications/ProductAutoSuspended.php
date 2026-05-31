<?php

namespace App\Notifications;

use App\Models\CustomerProduct;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A customer product was auto-suspended by the overdue-invoice sweep.
 * Notifies super_admins so a human is aware the system pulled access.
 *
 * Channels: database only for now (bell). The mail channel is stubbed —
 * switched on by adding 'mail' to via() once the Postmark sprint lands.
 */
class ProductAutoSuspended extends Notification
{
    public function __construct(
        public CustomerProduct $customerProduct,
        public float $overdueAmount,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $cp = $this->customerProduct;
        $customerName = $cp->customer->name ?? 'A customer';
        $productName = $cp->product->name ?? 'a product';
        $amount = '£'.number_format($this->overdueAmount, 2);

        return [
            'type' => 'product_auto_suspended',
            'title' => 'Product auto-suspended',
            'message' => "Auto-suspended: {$customerName} {$productName} — {$amount} overdue",
            'url' => '/customers/'.$cp->customer_id,
            'icon' => 'ti-ban',
            'colour' => '#EF4444',
            'entity_type' => 'customer',
            'entity_id' => $cp->customer_id,
        ];
    }

    // TODO(Postmark): build the MailMessage and add 'mail' to via().
    public function toMail(object $notifiable): ?MailMessage
    {
        return null;
    }
}
