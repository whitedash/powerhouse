<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('customer_products', function (Blueprint $table) {
            // billing_interval drives MRR/ARR math — every other column
            // here is contextual; this one is structural and queried
            // (so it gets an index).
            $table->enum('billing_interval', ['monthly', 'annual', 'one_off'])
                ->default('monthly')
                ->after('price_monthly');

            // Stripe sync fields — populated by the (not-yet-built) Stripe
            // webhook integration. Kept nullable so manual entries work
            // until the webhook is wired.
            $table->string('stripe_subscription_id', 100)->nullable()->after('billing_entity_id');
            $table->string('stripe_price_id', 100)->nullable()->after('stripe_subscription_id');

            $table->date('next_billing_date')->nullable()->after('started_at');
            $table->decimal('discount_pct', 5, 2)->nullable()->after('next_billing_date');
            $table->date('discount_expires_at')->nullable()->after('discount_pct');
            $table->date('cancels_at')->nullable()->after('discount_expires_at');

            $table->index('billing_interval');
            $table->index('next_billing_date');
        });
    }

    public function down(): void
    {
        Schema::table('customer_products', function (Blueprint $table) {
            $table->dropIndex(['billing_interval']);
            $table->dropIndex(['next_billing_date']);
            $table->dropColumn([
                'billing_interval',
                'stripe_subscription_id',
                'stripe_price_id',
                'next_billing_date',
                'discount_pct',
                'discount_expires_at',
                'cancels_at',
            ]);
        });
    }
};
