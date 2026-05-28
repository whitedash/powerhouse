{{-- Invoice PDF — A4 portrait, rendered by barryvdh/laravel-dompdf.
     dompdf doesn't support CSS variables, external stylesheets, or
     Google Fonts, so every colour is inlined and the typeface is the
     system stack. Layout uses tables; dompdf renders tables most
     reliably out of all its layout primitives. --}}
@php
    $statusLabels = [
        'draft' => 'Draft',
        'sent' => 'Outstanding',
        'paid' => 'Paid',
        'overdue' => 'Overdue',
        'void' => 'Void',
    ];
    $statusColours = [
        'draft' => ['bg' => '#F1F5F9', 'fg' => '#475569', 'border' => '#E2E8F0'],
        'sent' => ['bg' => '#FFFBEB', 'fg' => '#B45309', 'border' => '#FDE68A'],
        'paid' => ['bg' => '#ECFDF5', 'fg' => '#047857', 'border' => '#A7F3D0'],
        'overdue' => ['bg' => '#FEF2F2', 'fg' => '#B91C1C', 'border' => '#FECACA'],
        'void' => ['bg' => '#F1F5F9', 'fg' => '#475569', 'border' => '#E2E8F0'],
    ];
    $status = $invoice->status;
    $sc = $statusColours[$status] ?? $statusColours['draft'];
    $statusLabel = $statusLabels[$status] ?? ucfirst($status);

    $showBank = ! in_array($status, ['paid', 'void'], true);

    $entity = $invoice->billingEntity;
    $customer = $invoice->customer;

    $entityAddressLines = [];
    if (is_array($address)) {
        $order = ['line1', 'street', 'address_line1', 'address_line2', 'line2', 'city', 'postcode', 'country'];
        foreach ($order as $k) {
            if (! empty($address[$k])) {
                $entityAddressLines[] = $address[$k];
            }
        }
        if (! $entityAddressLines) {
            foreach ($address as $v) {
                if (is_string($v) && $v !== '') {
                    $entityAddressLines[] = $v;
                }
            }
        }
    }

    $customerAddressLines = array_filter([
        $customer?->address_line1,
        $customer?->address_line2,
        trim(($customer?->city ?? '').' '.($customer?->postcode ?? '')),
        ($customer?->country && strlen($customer->country) > 2) ? $customer->country : null,
    ]);

    $amountDue = (float) $invoice->total - (float) $invoice->amount_paid;
    $gbp = fn ($v) => '£'.number_format((float) $v, 2, '.', ',');
    $date = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('j M Y') : '—';

    $daysOverdue = null;
    if ($status === 'overdue' && $invoice->due_date) {
        $daysOverdue = (int) max(1, \Illuminate\Support\Carbon::today()->diffInDays($invoice->due_date, false) * -1);
    }
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->number }}</title>
    <style>
        @page { margin: 0; }
        body {
            font-family: Helvetica, Arial, sans-serif;
            font-size: 11px;
            line-height: 1.5;
            color: #0F172A;
            margin: 0;
            padding: 0;
        }
        .page {
            box-sizing: border-box;
            padding: 36px 36px 24px;
            width: 100%;
        }
        table { border-collapse: collapse; width: 100%; }
        td { vertical-align: top; }

        .label {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: #94A3B8;
            font-weight: bold;
        }

        /* Header */
        .head td { padding-bottom: 18px; }
        .brand-row td { vertical-align: middle; padding-bottom: 12px; }
        .brand-mark {
            width: 36px;
            height: 36px;
            background: #F59E0B;
            color: #0F172A;
            font-weight: bold;
            font-size: 16px;
            text-align: center;
            line-height: 36px;
            border-radius: 8px;
            display: inline-block;
        }
        .entity-name {
            font-size: 16px;
            font-weight: bold;
            color: #0F172A;
            padding-left: 12px;
            vertical-align: middle;
        }
        .entity-meta {
            color: #94A3B8;
            font-size: 10px;
            line-height: 1.6;
        }
        .entity-addr {
            color: #64748B;
            font-size: 10px;
            line-height: 1.7;
            margin-top: 8px;
        }

        .invoice-title {
            font-size: 28px;
            font-weight: bold;
            color: #0F172A;
            letter-spacing: -1px;
            text-align: right;
        }
        .invoice-number {
            font-family: 'Courier New', monospace;
            font-size: 13px;
            color: #64748B;
            text-align: right;
            margin-top: 4px;
        }
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: bold;
            margin-top: 8px;
            background: {{ $sc['bg'] }};
            color: {{ $sc['fg'] }};
            border: 1px solid {{ $sc['border'] }};
        }

        /* Bill-to row */
        .billto {
            background: #F1F5F9;
            border-top: 1px solid #EEF2F7;
            border-bottom: 1px solid #EEF2F7;
        }
        .billto td { padding: 16px 24px; width: 33%; }
        .billto .name { font-size: 12px; font-weight: bold; color: #0F172A; margin-top: 4px; }
        .billto .lines { color: #64748B; font-size: 10px; line-height: 1.6; margin-top: 2px; }
        .billto .email { color: #F59E0B; font-size: 10px; font-weight: bold; margin-top: 4px; }
        .billto .date-val { font-weight: bold; color: #0F172A; font-size: 12px; margin-top: 4px; }
        .billto .date-val.danger { color: #EF4444; }
        .billto .mono { font-family: 'Courier New', monospace; font-weight: bold; color: #0F172A; font-size: 12px; margin-top: 4px; }
        .billto .sub-label { margin-top: 12px; }

        /* Line items */
        .lines-wrap { padding: 0; }
        .lines-table { width: 100%; margin-top: 0; }
        .lines-table thead th {
            text-align: left;
            font-size: 9px;
            font-weight: bold;
            color: #94A3B8;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            padding: 14px 0 8px;
            border-bottom: 1px solid #E2E8F0;
        }
        .lines-table thead th.num { text-align: right; }
        .lines-table tbody td {
            padding: 12px 0;
            border-bottom: 1px solid #EEF2F7;
            font-size: 11px;
            color: #0F172A;
            vertical-align: top;
        }
        .lines-table tbody td.num { text-align: right; font-family: 'Courier New', monospace; }
        .lines-table tbody td.amount { font-weight: bold; }
        .line-note { color: #64748B; font-size: 10px; margin-top: 3px; }
        .lines-padding { padding: 0 24px; }
        .lines-empty {
            padding: 16px 0;
            text-align: center;
            color: #94A3B8;
            font-style: italic;
            font-size: 11px;
        }

        /* Totals */
        .totals-table { width: 100%; }
        .totals-table td { padding: 8px 0; }
        .totals-side { padding: 16px 24px; }
        .totals-side .summary { color: #64748B; font-size: 11px; line-height: 1.7; }
        .totals-side .summary .paid { color: #047857; font-weight: bold; }
        .totals-side .summary .due { color: #EF4444; font-weight: bold; font-size: 13px; }
        .totals-side .summary .paid-in-full { color: #047857; font-weight: bold; font-size: 12px; }

        .totals-final {
            font-family: 'Courier New', monospace;
            font-size: 11px;
            color: #64748B;
            text-align: right;
            padding: 0;
        }
        .totals-final .row { padding: 4px 0; }
        .totals-final .row .lbl { padding-right: 16px; }
        .totals-final .grand {
            border-top: 1px solid #EEF2F7;
            padding-top: 8px;
            color: #0F172A;
            font-size: 16px;
            font-weight: bold;
            font-family: Helvetica, Arial, sans-serif;
            margin-top: 6px;
        }

        /* Bank details */
        .bank {
            background: #F1F5F9;
            border-top: 1px solid #EEF2F7;
            padding: 16px 24px;
            color: #0F172A;
            font-size: 10px;
        }
        .bank .section-title { font-size: 9px; text-transform: uppercase; letter-spacing: 0.12em; color: #94A3B8; font-weight: bold; margin-bottom: 8px; }
        .bank table td { padding: 4px 0; }
        .bank .k { color: #64748B; width: 100px; }
        .bank .v { color: #0F172A; font-weight: bold; }
        .bank .v.mono { font-family: 'Courier New', monospace; }

        /* Notes */
        .notes {
            border-top: 1px solid #EEF2F7;
            padding: 16px 24px;
            color: #64748B;
            font-size: 10px;
            line-height: 1.6;
        }
        .notes .section-title { font-size: 9px; text-transform: uppercase; letter-spacing: 0.12em; color: #94A3B8; font-weight: bold; margin-bottom: 6px; }

        /* Footer */
        .footer {
            border-top: 1px solid #EEF2F7;
            background: #F1F5F9;
            padding: 14px 24px 18px;
            text-align: center;
            color: #94A3B8;
            font-size: 9px;
            line-height: 1.6;
        }
        .footer .gen { text-transform: uppercase; letter-spacing: 0.12em; margin-top: 4px; font-size: 8px; }
    </style>
</head>
<body>
<div class="page">

    {{-- ─── Header ─── --}}
    <table class="head">
        <tr>
            <td style="width: 60%;">
                <table class="brand-row">
                    <tr>
                        <td style="width: 48px;"><span class="brand-mark">W</span></td>
                        <td><span class="entity-name">{{ $entity?->name ?? 'Whitedash' }}</span></td>
                    </tr>
                </table>

                <div class="entity-meta">
                    @if($entity?->company_number)Company No. {{ $entity->company_number }}<br>@endif
                    @if($entity?->vat_number)VAT No. {{ $entity->vat_number }}@endif
                </div>

                @if($entityAddressLines)
                    <div class="entity-addr">
                        @foreach($entityAddressLines as $line)
                            {{ $line }}<br>
                        @endforeach
                    </div>
                @endif
            </td>
            <td style="width: 40%; text-align: right;">
                <div class="invoice-title">INVOICE</div>
                <div class="invoice-number">{{ $invoice->number }}</div>
                <div style="text-align: right;">
                    <span class="status-badge">
                        {{ $statusLabel }}@if($daysOverdue) · {{ $daysOverdue }} days @endif
                    </span>
                </div>
            </td>
        </tr>
    </table>

    {{-- ─── Bill to / dates ─── --}}
    <table class="billto">
        <tr>
            <td>
                <div class="label">Bill to</div>
                <div class="name">{{ $customer?->name ?? '—' }}</div>
                @if($customerAddressLines)
                    <div class="lines">
                        @foreach($customerAddressLines as $line)
                            {{ $line }}<br>
                        @endforeach
                    </div>
                @endif
                @if($billing_email)
                    <div class="email">{{ $billing_email }}</div>
                @endif
            </td>
            <td>
                <div class="label">Invoice date</div>
                <div class="date-val">{{ $date($invoice->issue_date) }}</div>
                <div class="label sub-label">Due date</div>
                <div class="date-val {{ $status === 'overdue' ? 'danger' : '' }}">{{ $date($invoice->due_date) }}</div>
            </td>
            <td>
                <div class="label">Payment ref</div>
                <div class="mono">{{ $invoice->number }}</div>
                <div class="label sub-label">Payment terms</div>
                <div class="date-val">Net 14 days</div>
            </td>
        </tr>
    </table>

    {{-- ─── Line items ─── --}}
    <div class="lines-padding">
        <table class="lines-table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th class="num" style="width: 60px;">Qty</th>
                    <th class="num" style="width: 90px;">Unit price</th>
                    <th class="num" style="width: 90px;">Amount</th>
                </tr>
            </thead>
            <tbody>
                @forelse($invoice->lines as $line)
                    <tr>
                        <td>
                            {{ $line->description }}
                            @if($line->note)<div class="line-note">{{ $line->note }}</div>@endif
                        </td>
                        <td class="num">{{ rtrim(rtrim(number_format((float) $line->quantity, 3, '.', ''), '0'), '.') }}</td>
                        <td class="num">{{ $gbp($line->unit_price) }}</td>
                        <td class="num amount">{{ $gbp($line->amount) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="lines-empty">No line items added.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- ─── Totals ─── --}}
    <table style="margin-top: 8px;">
        <tr>
            <td style="width: 55%; padding: 16px 24px;">
                @if($status === 'paid')
                    <div class="totals-side"><div class="summary"><span class="paid-in-full">✓ Paid in full</span></div></div>
                @elseif((float) $invoice->amount_paid > 0)
                    <div class="summary">
                        <span class="paid">Amount paid: {{ $gbp($invoice->amount_paid) }}</span><br>
                        <span class="due">Amount due: {{ $gbp($amountDue) }}</span>
                    </div>
                @elseif($status === 'void')
                    <div class="summary" style="color: #94A3B8; font-style: italic;">This invoice has been voided.</div>
                @else
                    <div class="summary"><span class="due">Amount due: {{ $gbp($invoice->total) }}</span></div>
                @endif
            </td>
            <td style="width: 45%; padding: 16px 24px;">
                <table class="totals-final">
                    <tr class="row"><td class="lbl">Subtotal</td><td>{{ $gbp($invoice->subtotal) }}</td></tr>
                    <tr class="row"><td class="lbl">VAT ({{ round((float) $invoice->vat_rate) }}%)</td><td>{{ $gbp($invoice->vat_amount) }}</td></tr>
                    <tr><td colspan="2"><div class="grand"><table style="width: 100%;"><tr><td>Total</td><td style="text-align: right;">{{ $gbp($invoice->total) }}</td></tr></table></div></td></tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- ─── Bank details (hidden for paid/void) ─── --}}
    @if($showBank && $entity)
        <div class="bank">
            <div class="section-title">Payment details</div>
            <table>
                @if($entity->bank_name)
                    <tr><td class="k">Bank:</td><td class="v">{{ $entity->bank_name }}</td></tr>
                @endif
                @if($entity->account_name)
                    <tr><td class="k">Account name:</td><td class="v">{{ $entity->account_name }}</td></tr>
                @endif
                @if($entity->sort_code)
                    <tr><td class="k">Sort code:</td><td class="v mono">{{ $entity->sort_code }}</td></tr>
                @endif
                @if($entity->account_number)
                    <tr><td class="k">Account no:</td><td class="v mono">{{ $entity->account_number }}</td></tr>
                @endif
            </table>
        </div>
    @endif

    {{-- ─── Notes ─── --}}
    @if($invoice->notes)
        <div class="notes">
            <div class="section-title">Notes</div>
            <div>{!! nl2br(e($invoice->notes)) !!}</div>
        </div>
    @endif

    {{-- ─── Legal footer ─── --}}
    @php
        $legalParts = [];
        if ($entity) {
            $legalParts[] = $entity->legal_name ?? $entity->name;
            if ($entity->company_number) $legalParts[] = 'Company No. '.$entity->company_number;
            if ($entity->vat_number) $legalParts[] = 'VAT '.$entity->vat_number;
        }
    @endphp
    <div class="footer">
        <div>{{ implode(' · ', $legalParts) }}</div>
        <div class="gen">Generated by Powerhouse · Whitedash Holdings</div>
    </div>

</div>
</body>
</html>
