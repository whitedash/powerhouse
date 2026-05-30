<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\SupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Global search powering the ⌘K bar in the InternalLayout topbar.
 *
 * Returns a flat list of result rows ({type, icon, colour, title, sub,
 * url}) so the frontend doesn't need to know which model is which —
 * it just renders the row and follows the url on click. We deliberately
 * keep this thin (LIKE queries, per-source LIMITs) — the dataset is
 * small and the search needs to feel instant. A real full-text engine
 * (Scout/Meilisearch) lands when we cross ~10k of any one entity.
 */
class SearchController extends Controller
{
    private const PRODUCT_ICON_COLOUR_DEFAULT = '#6366F1';

    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        // <2 chars is too noisy — every customer matching "a" would
        // flood the dropdown with no signal. Empty results short-
        // circuits the frontend's "no results" empty state too.
        if (mb_strlen($q) < 2) {
            return response()->json(['results' => [], 'query' => $q]);
        }

        $results = [];

        // ── Customers — matches the customer's own name or the
        //    name/email of any contact attached to them.
        $customers = Customer::whereNull('archived_at')
            ->where(function ($query) use ($q) {
                $query->where('name', 'like', "%{$q}%")
                    ->orWhereHas('contacts', function ($q2) use ($q) {
                        $q2->where('email', 'like', "%{$q}%")
                            ->orWhere('name', 'like', "%{$q}%");
                    });
            })
            ->take(5)
            ->get(['id', 'name', 'city'])
            ->map(fn (Customer $c): array => [
                'type' => 'customer',
                'icon' => 'building-store',
                'colour' => '#6366F1',
                'title' => $c->name,
                'sub' => $c->city ?? '—',
                'url' => '/customers/'.$c->id,
            ])
            ->all();
        $results = array_merge($results, $customers);

        // ── Invoices — by invoice number or by attached customer
        //    name. Two passes keeps it predictable; OR-whereHas with
        //    orWhere on the same builder is fiddly.
        $invoices = Invoice::with('customer:id,name')
            ->where(function ($query) use ($q) {
                $query->where('number', 'like', "%{$q}%")
                    ->orWhereHas('customer', fn ($q2) => $q2->where('name', 'like', "%{$q}%"));
            })
            ->take(4)
            ->get()
            ->map(fn (Invoice $inv): array => [
                'type' => 'invoice',
                'icon' => 'receipt',
                'colour' => '#F59E0B',
                'title' => $inv->number,
                'sub' => $inv->customer->name.' · £'.number_format((float) $inv->total, 2),
                'url' => '/invoices/'.$inv->id,
            ])
            ->all();
        $results = array_merge($results, $invoices);

        // ── Contacts — direct name/email match. Url routes to the
        //    contact's customer page since there's no contact-detail
        //    screen yet.
        $contacts = Contact::with('customer:id,name')
            ->where(function ($query) use ($q) {
                $query->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            })
            ->take(3)
            ->get()
            ->map(fn (Contact $c): array => [
                'type' => 'contact',
                'icon' => 'user',
                'colour' => '#10B981',
                'title' => $c->name,
                'sub' => ($c->email ?? '—').($c->customer ? ' · '.$c->customer->name : ''),
                'url' => '/customers/'.$c->customer_id,
            ])
            ->all();
        $results = array_merge($results, $contacts);

        // ── Open / in-progress / awaiting tickets. Resolved tickets
        //    aren't in the result set because they're not actionable
        //    work — search them via the Support page filter instead.
        $tickets = SupportTicket::with('customer:id,name')
            ->where('subject', 'like', "%{$q}%")
            ->whereIn('status', ['open', 'in_progress', 'awaiting_customer'])
            ->take(3)
            ->get()
            ->map(fn (SupportTicket $t): array => [
                'type' => 'ticket',
                'icon' => 'headset',
                'colour' => '#3B82F6',
                'title' => $t->subject,
                'sub' => $t->customer->name.' · '.str_replace('_', ' ', $t->status),
                'url' => '/support/'.$t->id,
            ])
            ->all();
        $results = array_merge($results, $tickets);

        // ── Active products only — coming-soon ones don't have a
        //    landing page yet, would 404.
        $products = Product::where('is_active', true)
            ->where('name', 'like', "%{$q}%")
            ->take(3)
            ->get()
            ->map(fn (Product $p): array => [
                'type' => 'product',
                'icon' => 'box',
                'colour' => $p->icon_colour ?? self::PRODUCT_ICON_COLOUR_DEFAULT,
                'title' => $p->name,
                'sub' => 'Product overview',
                'url' => '/products/'.$p->slug,
            ])
            ->all();
        $results = array_merge($results, $products);

        return response()->json([
            // Hard-cap at 15 — the dropdown's max-height is 400px,
            // anything more requires scroll and breaks the "scan
            // and click" UX.
            'results' => array_slice($results, 0, 15),
            'query' => $q,
        ]);
    }
}
