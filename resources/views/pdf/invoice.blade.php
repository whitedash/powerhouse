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
  /* Page margins live on the .page wrapper as padding rather than the
     @page rule. dompdf 2.x parses @page margins inconsistently across
     viewers — relying on wrapper padding renders the same white space
     in every viewer because it becomes part of the page content, not
     a rendering hint. @page keeps size: A4 only. */
  @page {
    size: A4 portrait;
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
    line-height: 1.45;
  }
  .page {
    background: #FFFFFF;
    padding: 15mm 15mm 15mm 15mm;
  }

  .no-break {
    page-break-inside: avoid;
  }
  .clearfix {
    clear: both;
    display: block;
    line-height: 0;
    font-size: 0;
  }

  /* ---- generic helpers ---- */
  .label {
    font-size: 9px;
    font-weight: bold;
    color: #94A3B8;
    letter-spacing: 2px;
    text-transform: uppercase;
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
    font-size: 30px;
    font-weight: bold;
    color: #0F172A;
    letter-spacing: 3px;
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
    margin: 14px 0;
  }

  /* ---- billing grid ---- */
  .billing-grid {
    width: 100%;
    background: #F8FAFC;
    border: none;
  }
  .billing-grid td {
    vertical-align: top;
    padding: 12px 14px;
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

  /* ---- line items ----
     table-layout: fixed forces dompdf to honour declared column widths
     rather than auto-shrinking the rightmost (Amount) column when the
     description text is long. <colgroup> below the table also adds an
     explicit-width belt. */
  .items {
    width: 100%;
    margin-top: 20px;
    border: none;
    table-layout: fixed;
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
    padding: 8px 10px;
  }
  .items thead th.right {
    text-align: right;
  }
  .items tbody td {
    border-bottom: 1px solid #EEF2F7;
    padding: 10px 10px;
    vertical-align: top;
    word-wrap: break-word;
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
    margin-top: 14px;
    table-layout: fixed;
  }
  .totals-inner {
    width: 100%;
    border: none;
    table-layout: fixed;
  }
  .totals-inner td {
    padding: 5px 10px;
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
    padding: 12px 14px;
    margin-top: 18px;
  }
  .notes-box {
    background: #F8FAFC;
    border: 1px solid #E2E8F0;
    padding: 12px 14px;
    margin-top: 12px;
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

  /* ---- footer ----
     Rendered inline (was previously position: absolute bottom: 20mm,
     which together with .page { height: 297mm } caused content to
     collide with the page edge and tip onto a blank second page). */
  .footer {
    width: 100%;
    border-top: 1px solid #E2E8F0;
    margin-top: 20px;
    padding-top: 10px;
    table-layout: fixed;
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
    <table class="header-table no-break" width="100%" cellpadding="0" cellspacing="0">
      <colgroup>
        <col style="width: 55%;">
        <col style="width: 45%;">
      </colgroup>
      <tbody><tr>
        <!-- FROM / brand -->
        <td width="55%" valign="top">
          <table width="100%" cellpadding="0" cellspacing="0" style="border: none;">
            <colgroup>
              <col style="width: 52px;">
              <col>
            </colgroup>
            <tbody><tr>
              <td width="52" valign="top" style="padding-right: 12px;">
                <div class="brand-mark">W</div>
              </td>
              <td valign="top">
                <div class="company-name">{{ $entity?->name }}</div>
                @if($entityMetaLine)
                  <div class="meta-line">{{ $entityMetaLine }}</div>
                @endif
              </td>
            </tr>
          </tbody></table>
          @if($entityAddressLine)
            <div class="address">{{ $entityAddressLine }}</div>
          @endif
          @if($entity?->postmark_sender_email)
            <div class="email-gold">{{ $entity->postmark_sender_email }}</div>
          @endif
        </td>

        <!-- INVOICE / status -->
        <td width="45%" valign="top" align="right">
          <div class="invoice-word">INVOICE</div>
          <div class="invoice-num">{{ $invoice->number }}</div>
          <div><span class="status-badge" style="background: {{ $sc['bg'] }}; color: {{ $sc['fg'] }}; border: 1px solid {{ $sc['border'] }};">{{ $statusLabel }}</span></div>
        </td>
      </tr>
    </tbody></table>
    <div class="clearfix"></div>

    <hr class="divider">

    <!-- ============ BILLING GRID ============ -->
    <table class="billing-grid no-break" width="100%" cellpadding="0" cellspacing="0" style="table-layout: fixed;">
      <colgroup>
        <col style="width: 40%;">
        <col style="width: 30%;">
        <col style="width: 30%;">
      </colgroup>
      <tbody><tr>
        <td width="40%" valign="top">
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
        <td width="30%" valign="top">
          <div class="pair">
            <div class="label">Invoice Date</div>
            <div class="val">{{ $issueDate ?? '—' }}</div>
          </div>
          <div class="pair" style="margin-bottom: 0;">
            <div class="label">Due Date</div>
            <div class="val">{{ $dueDate ?? '—' }}</div>
          </div>
        </td>
        <td width="30%" valign="top">
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
    <table class="items" width="100%" cellpadding="0" cellspacing="0">
      <colgroup>
        <col style="width: 55%;">
        <col style="width: 10%;">
        <col style="width: 17%;">
        <col style="width: 18%;">
      </colgroup>
      <thead>
        <tr>
          <th width="55%">Description</th>
          <th width="10%" class="right">Qty</th>
          <th width="17%" class="right">Unit Price</th>
          <th width="18%" class="right">Amount</th>
        </tr>
      </thead>
      <tbody>
        @foreach($invoice->lines as $i => $line)
          <tr class="{{ $i % 2 === 0 ? 'odd' : 'even' }}">
            <td width="55%">
              <div class="desc">{{ $line->description }}</div>
              @if($line->note)
                <div class="desc-note">{{ $line->note }}</div>
              @endif
            </td>
            <td width="10%" class="num">{{ rtrim(rtrim(number_format((float) $line->quantity, 3, '.', ''), '0'), '.') }}</td>
            <td width="17%" class="num">{{ $gbp($line->unit_price) }}</td>
            <td width="18%" class="amt">{{ $gbp($line->amount) }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>

    <!-- ============ TOTALS ============ -->
    <table class="totals-table no-break" width="100%" cellpadding="0" cellspacing="0">
      <colgroup>
        <col style="width: 50%;">
        <col style="width: 50%;">
      </colgroup>
      <tbody><tr>
        <td width="50%"></td>
        <td width="50%" align="right">
          <table class="totals-inner" width="100%" cellpadding="0" cellspacing="0">
            <colgroup>
              <col>
              <col style="width: 120px;">
            </colgroup>
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
    <table class="footer" width="100%" cellpadding="0" cellspacing="0">
      <colgroup>
        <col style="width: 50%;">
        <col style="width: 50%;">
      </colgroup>
      <tbody><tr>
        <td width="50%">{{ implode(' · ', $legalParts) }}</td>
        <td width="50%" class="f-right">Generated by Powerhouse · Whitedash Holdings</td>
      </tr>
    </tbody></table>

  </div>


</body></html>
