@php
    /* ---- Status label + badge colours ---- */
    $statusKey = $invoice->status;
    $statusLabel = strtoupper($statusKey === 'sent' ? 'Outstanding' : $statusKey);

    $colorMap = [
        'draft' => ['bg' => '#F1F5F9', 'fg' => '#475569', 'border' => '#CBD5E1'],
        'sent' => ['bg' => '#FFFBEB', 'fg' => '#92400E', 'border' => '#FDE68A'],
        'paid' => ['bg' => '#D1FAE5', 'fg' => '#065F46', 'border' => '#A7F3D0'],
        'overdue' => ['bg' => '#FEE2E2', 'fg' => '#991B1B', 'border' => '#FCA5A5'],
        'void' => ['bg' => '#F1F5F9', 'fg' => '#94A3B8', 'border' => '#E2E8F0'],
    ];
    $sc = $colorMap[$statusKey] ?? $colorMap['draft'];

    /* ---- Entity (billing-from) ---- */
    $entity = $invoice->billingEntity;

    $entityMetaParts = [];
    if ($entity?->company_number) $entityMetaParts[] = 'Company No. '.$entity->company_number;
    if ($entity?->vat_number) $entityMetaParts[] = 'VAT No. '.$entity->vat_number;
    $entityMetaLine = implode(' · ', $entityMetaParts);

    $entityAddressOrder = ['line1', 'street', 'address_line1', 'address_line2', 'line2', 'city', 'postcode', 'country'];
    $entityAddressParts = [];
    if (is_array($address)) {
        foreach ($entityAddressOrder as $k) {
            if (! empty($address[$k])) $entityAddressParts[] = $address[$k];
        }
        if (! $entityAddressParts) {
            foreach ($address as $v) {
                if (is_string($v) && $v !== '') $entityAddressParts[] = $v;
            }
        }
    }
    $entityAddressLine = implode(', ', $entityAddressParts);

    /* ---- Customer (bill-to) ---- */
    $customer = $invoice->customer;
    $contactName = $customer?->primaryContact?->name;
    $customerAddressParts = array_filter([
        $customer?->address_line1,
        $customer?->address_line2,
        trim(implode(' ', array_filter([$customer?->city, $customer?->postcode]))),
        $customer?->country && strlen($customer->country) > 2 ? $customer->country : null,
    ]);
    $customerAddressLine = implode(', ', $customerAddressParts);

    /* ---- Dates / payment terms ---- */
    $issueDate = $invoice->issue_date?->format('j M Y');
    $dueDate = $invoice->due_date?->format('j M Y');
    $daysToDue = ($invoice->issue_date && $invoice->due_date)
        ? (int) $invoice->issue_date->copy()->startOfDay()->diffInDays($invoice->due_date->copy()->startOfDay(), false)
        : null;
    $paymentTerms = match (true) {
        $daysToDue === null => 'Net 14 days',
        $daysToDue <= 0 => 'Due on receipt',
        default => 'Net '.$daysToDue.' days',
    };

    /* ---- Totals ---- */
    $subtotal = (float) $invoice->subtotal;
    $vatRate = (float) $invoice->vat_rate;
    $vatAmount = (float) $invoice->vat_amount;
    $total = (float) $invoice->total;
    $amountPaid = (float) $invoice->amount_paid;
    $amountDue = round($total - $amountPaid, 2);

    $vatRateDisplay = (fmod($vatRate, 1.0) === 0.0)
        ? (string) (int) $vatRate
        : rtrim(rtrim(number_format($vatRate, 2, '.', ''), '0'), '.');

    $showBank = ! in_array($statusKey, ['paid', 'void'], true) && $entity;
    $showDueRow = ! in_array($statusKey, ['paid', 'void'], true);

    $gbp = fn ($n) => '£'.number_format((float) $n, 2, '.', ',');

    /* ---- Legal footer ---- */
    $legalParts = [];
    if ($entity) {
        $legalParts[] = $entity->legal_name ?? $entity->name;
        if ($entity->company_number) $legalParts[] = 'Company No. '.$entity->company_number;
        if ($entity->vat_number) $legalParts[] = 'VAT '.$entity->vat_number;
    }
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Invoice {{ $invoice->number }} — {{ $entity?->name }}</title>
<style>
  @page {
    size: A4;
    margin: 0;
  }
  * {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
  }
  html, body {
    background: #FFFFFF;
    color: #0F172A;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 11px;
    line-height: 1.5;
  }
  .page {
    width: 210mm;
    height: 297mm;
    background: #FFFFFF;
    padding: 20mm;
    position: relative;
  }

  /* ---- generic helpers ---- */
  .label {
    font-size: 9px;
    font-weight: bold;
    color: #94A3B8;
    letter-spacing: 2px;
    text-transform: uppercase;
  }
  .clear {
    clear: both;
  }
  table {
    border-collapse: collapse;
  }

  /* ---- header ---- */
  .header-table {
    width: 100%;
    border: none;
  }
  .header-table td {
    vertical-align: top;
  }
  .brand-mark {
    width: 40px;
    height: 40px;
    background: #F59E0B;
    border-radius: 4px;
    color: #FFFFFF;
    font-family: Arial, Helvetica, sans-serif;
    font-weight: bold;
    font-size: 20px;
    text-align: center;
    line-height: 40px;
  }
  .company-name {
    font-size: 18px;
    font-weight: bold;
    color: #0F172A;
    line-height: 1.2;
  }
  .meta-line {
    font-size: 10px;
    color: #64748B;
    line-height: 1.5;
  }
  .address {
    font-size: 11px;
    color: #64748B;
    line-height: 1.6;
    margin-top: 8px;
  }
  .email-gold {
    font-size: 11px;
    color: #F59E0B;
  }
  .invoice-word {
    font-size: 36px;
    font-weight: bold;
    color: #0F172A;
    letter-spacing: 4px;
    line-height: 1;
  }
  .invoice-num {
    font-family: "Courier New", Courier, monospace;
    font-size: 14px;
    font-weight: bold;
    color: #64748B;
    margin-top: 8px;
  }
  .status-badge {
    display: inline-block;
    font-size: 11px;
    font-weight: bold;
    letter-spacing: 1px;
    padding: 4px 12px;
    border-radius: 3px;
    margin-top: 12px;
  }

  /* ---- divider ---- */
  .divider {
    border: none;
    border-top: 2px solid #F59E0B;
    margin: 20px 0;
  }

  /* ---- billing grid ---- */
  .billing-grid {
    width: 100%;
    background: #F8FAFC;
    border: none;
  }
  .billing-grid td {
    vertical-align: top;
    padding: 16px;
  }
  .billing-grid .col-name {
    font-size: 13px;
    font-weight: bold;
    color: #0F172A;
    margin-top: 8px;
  }
  .billing-grid .col-sub {
    font-size: 11px;
    color: #64748B;
    line-height: 1.6;
  }
  .pair {
    margin-bottom: 14px;
  }
  .pair .val {
    font-size: 13px;
    font-weight: bold;
    color: #0F172A;
    margin-top: 3px;
  }
  .pair .val-mono {
    font-family: "Courier New", Courier, monospace;
    font-size: 13px;
    color: #0F172A;
    margin-top: 3px;
  }

  /* ---- line items ---- */
  .items {
    width: 100%;
    margin-top: 28px;
    border: none;
  }
  .items thead th {
    background: #F1F5F9;
    border-bottom: 1px solid #E2E8F0;
    font-size: 9px;
    font-weight: bold;
    color: #94A3B8;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    text-align: left;
    padding: 9px 10px;
  }
  .items thead th.right {
    text-align: right;
  }
  .items tbody td {
    border-bottom: 1px solid #EEF2F7;
    padding: 12px 10px;
    vertical-align: top;
  }
  .items tbody tr.even td {
    background: #FAFBFC;
  }
  .items .desc {
    font-size: 13px;
    color: #0F172A;
  }
  .items .desc-note {
    font-size: 11px;
    color: #64748B;
    font-style: italic;
    margin-top: 3px;
  }
  .items .num {
    font-size: 13px;
    color: #0F172A;
    text-align: right;
    white-space: nowrap;
  }
  .items .amt {
    font-size: 13px;
    font-weight: bold;
    color: #0F172A;
    text-align: right;
    white-space: nowrap;
  }

  /* ---- totals ---- */
  .totals-table {
    width: 100%;
    border: none;
    margin-top: 18px;
  }
  .totals-inner {
    width: 100%;
    border: none;
  }
  .totals-inner td {
    padding: 6px 10px;
    font-size: 12px;
    color: #64748B;
  }
  .totals-inner td.t-label {
    text-align: right;
  }
  .totals-inner td.t-val {
    text-align: right;
    white-space: nowrap;
    width: 120px;
  }
  .totals-inner tr.rule td {
    border-top: 1px solid #E2E8F0;
    padding: 0;
    height: 1px;
    line-height: 0;
    font-size: 0;
  }
  .totals-inner tr.total td {
    font-size: 18px;
    font-weight: bold;
    color: #0F172A;
    padding-top: 12px;
  }
  .totals-inner tr.due td {
    font-size: 14px;
    font-weight: bold;
    color: #F59E0B;
  }
  .totals-inner tr.paid td {
    font-size: 14px;
    font-weight: bold;
    color: #065F46;
  }
  .totals-inner tr.void td {
    font-size: 14px;
    font-weight: bold;
    color: #94A3B8;
    letter-spacing: 2px;
  }

  /* ---- payment / notes boxes ---- */
  .pay-box {
    background: #F8FAFC;
    border: 1px solid #E2E8F0;
    border-left: 4px solid #F59E0B;
    padding: 16px;
    margin-top: 24px;
  }
  .notes-box {
    background: #F8FAFC;
    border: 1px solid #E2E8F0;
    padding: 16px;
    margin-top: 16px;
  }
  .pay-detail-table {
    width: 100%;
    border: none;
    margin-top: 10px;
  }
  .pay-detail-table td {
    padding: 4px 0;
    vertical-align: top;
  }
  .pay-detail-table td.pd-label {
    font-size: 11px;
    font-weight: bold;
    color: #64748B;
    width: 130px;
  }
  .pay-detail-table td.pd-val {
    font-size: 11px;
    color: #0F172A;
  }
  .pay-detail-table td.pd-mono {
    font-family: "Courier New", Courier, monospace;
    font-size: 11px;
    color: #0F172A;
  }
  .pay-detail-table td.pd-mono-bold {
    font-family: "Courier New", Courier, monospace;
    font-size: 11px;
    font-weight: bold;
    color: #0F172A;
  }
  .notes-text {
    font-size: 11px;
    color: #64748B;
    font-style: italic;
    line-height: 1.7;
    margin-top: 8px;
  }

  /* ---- footer ---- */
  .footer {
    position: absolute;
    left: 20mm;
    right: 20mm;
    bottom: 20mm;
    border-top: 1px solid #E2E8F0;
    padding-top: 12px;
  }
  .footer td {
    font-size: 10px;
    color: #94A3B8;
    vertical-align: middle;
  }
  .footer td.f-right {
    text-align: right;
  }
</style>
</head>

<body>
  <div class="page">

    <!-- ============ HEADER ============ -->
    <table class="header-table">
      <tbody><tr>
        <!-- FROM / brand -->
        <td style="width: 58%;">
          <table style="border: none;">
            <tbody><tr>
              <td style="vertical-align: top; padding-right: 12px;">
                <div class="brand-mark">W</div>
              </td>
              <td style="vertical-align: top;">
                <div class="company-name">{{ $entity?->name }}</div>
                @if($entityMetaLine)
                  <div class="meta-line">{{ $entityMetaLine }}</div>
                @endif
              </td>
            </tr>
          </tbody></table>
          @if($entityAddressLine)
            <div class="address">
              {{ $entityAddressLine }}
            </div>
          @endif
          @if($entity?->postmark_sender_email)
            <div class="email-gold">{{ $entity->postmark_sender_email }}</div>
          @endif
        </td>

        <!-- INVOICE / status -->
        <td style="width: 42%; text-align: right; vertical-align: top;">
          <div class="invoice-word">INVOICE</div>
          <div class="invoice-num">{{ $invoice->number }}</div>
          <div><span class="status-badge" style="background: {{ $sc['bg'] }}; color: {{ $sc['fg'] }}; border: 1px solid {{ $sc['border'] }};">{{ $statusLabel }}</span></div>
        </td>
      </tr>
    </tbody></table>

    <hr class="divider">

    <!-- ============ BILLING GRID ============ -->
    <table class="billing-grid">
      <tbody><tr>
        <td style="width: 40%;">
          <div class="label">Bill To</div>
          <div class="col-name">{{ $customer?->name ?? '—' }}</div>
          @if($contactName || $customerAddressLine)
            <div class="col-sub" style="margin-top: 4px;">
              @if($contactName){{ $contactName }}@endif
              @if($contactName && $customerAddressLine)<br>@endif
              @if($customerAddressLine){{ $customerAddressLine }}@endif
            </div>
          @endif
          @if($billing_email)
            <div class="email-gold" style="margin-top: 4px;">{{ $billing_email }}</div>
          @endif
        </td>
        <td style="width: 30%;">
          <div class="pair">
            <div class="label">Invoice Date</div>
            <div class="val">{{ $issueDate ?? '—' }}</div>
          </div>
          <div class="pair" style="margin-bottom: 0;">
            <div class="label">Due Date</div>
            <div class="val">{{ $dueDate ?? '—' }}</div>
          </div>
        </td>
        <td style="width: 30%;">
          <div class="pair">
            <div class="label">Payment Ref</div>
            <div class="val-mono">{{ $invoice->number }}</div>
          </div>
          <div class="pair" style="margin-bottom: 0;">
            <div class="label">Payment Terms</div>
            <div class="val">{{ $paymentTerms }}</div>
          </div>
        </td>
      </tr>
    </tbody></table>

    <!-- ============ LINE ITEMS ============ -->
    <table class="items">
      <thead>
        <tr>
          <th style="width: 58%;">Description</th>
          <th class="right" style="width: 8%;">Qty</th>
          <th class="right" style="width: 17%;">Unit Price</th>
          <th class="right" style="width: 17%;">Amount</th>
        </tr>
      </thead>
      <tbody>
        @foreach($invoice->lines as $i => $line)
          <tr class="{{ $i % 2 === 0 ? 'odd' : 'even' }}">
            <td>
              <div class="desc">{{ $line->description }}</div>
              @if($line->note)
                <div class="desc-note">{{ $line->note }}</div>
              @endif
            </td>
            <td class="num">{{ rtrim(rtrim(number_format((float) $line->quantity, 3, '.', ''), '0'), '.') }}</td>
            <td class="num">{{ $gbp($line->unit_price) }}</td>
            <td class="amt">{{ $gbp($line->amount) }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>

    <!-- ============ TOTALS ============ -->
    <table class="totals-table">
      <tbody><tr>
        <td style="width: 55%;"></td>
        <td style="width: 45%;">
          <table class="totals-inner">
            <tbody><tr>
              <td class="t-label">Subtotal</td>
              <td class="t-val">{{ $gbp($subtotal) }}</td>
            </tr>
            <tr>
              <td class="t-label">VAT ({{ $vatRateDisplay }}%)</td>
              <td class="t-val">{{ $gbp($vatAmount) }}</td>
            </tr>
            <tr class="rule"><td></td><td></td></tr>
            <tr class="total">
              <td class="t-label">TOTAL</td>
              <td class="t-val">{{ $gbp($total) }}</td>
            </tr>
            @if($statusKey === 'paid')
              <tr class="paid">
                <td class="t-label">PAID IN FULL</td>
                <td class="t-val">{{ $gbp($amountPaid) }}</td>
              </tr>
            @elseif($statusKey === 'void')
              <tr class="void">
                <td class="t-label">VOIDED</td>
                <td class="t-val">—</td>
              </tr>
            @else
              @if($amountPaid > 0)
                <tr>
                  <td class="t-label">Amount paid</td>
                  <td class="t-val">{{ $gbp($amountPaid) }}</td>
                </tr>
              @endif
              <tr class="due">
                <td class="t-label">AMOUNT DUE</td>
                <td class="t-val">{{ $gbp($amountDue) }}</td>
              </tr>
            @endif
          </tbody></table>
        </td>
      </tr>
    </tbody></table>

    @if($showBank)
    <!-- ============ PAYMENT DETAILS ============ -->
    <div class="pay-box">
      <div class="label">Payment Details</div>
      <table class="pay-detail-table">
        <tbody>
        @if($entity->bank_name)
          <tr>
            <td class="pd-label">Bank:</td>
            <td class="pd-val">{{ $entity->bank_name }}</td>
          </tr>
        @endif
        @if($entity->account_name)
          <tr>
            <td class="pd-label">Account name:</td>
            <td class="pd-val">{{ $entity->account_name }}</td>
          </tr>
        @endif
        @if($entity->sort_code)
          <tr>
            <td class="pd-label">Sort code:</td>
            <td class="pd-mono">{{ $entity->sort_code }}</td>
          </tr>
        @endif
        @if($entity->account_number)
          <tr>
            <td class="pd-label">Account number:</td>
            <td class="pd-mono">{{ $entity->account_number }}</td>
          </tr>
        @endif
          <tr>
            <td class="pd-label">Reference:</td>
            <td class="pd-mono-bold">{{ $invoice->number }}</td>
          </tr>
        </tbody>
      </table>
    </div>
    @endif

    @if($invoice->notes)
    <!-- ============ NOTES ============ -->
    <div class="notes-box">
      <div class="label">Notes</div>
      <div class="notes-text">{!! nl2br(e($invoice->notes)) !!}</div>
    </div>
    @endif

    <!-- ============ FOOTER ============ -->
    <table class="footer">
      <tbody><tr>
        <td>{{ implode(' · ', $legalParts) }}</td>
        <td class="f-right">Generated by Powerhouse · Whitedash Holdings</td>
      </tr>
    </tbody></table>

  </div>


</body></html>
