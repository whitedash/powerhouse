@php
    /*
     * Proposal PDF — same shop as pdf/invoice.blade.php but
     * quote-flavoured: bigger title, no payment block, optional
     * payment schedule, optional terms section, and an
     * "ACCEPTED" stamp when $with_acceptance is true.
     *
     * Inputs:
     *   $proposal       — Proposal with lines.product / customer.primaryContact / billingEntity / paymentSchedule.items
     *   $entity         — $proposal->billingEntity (shorthand)
     *   $logo_data      — base64 data URL or null (resolved by controller)
     *   $with_acceptance — true on the post-accept PDF; renders the stamp
     */

    $gbp = fn ($n) => '£'.number_format((float) $n, 2, '.', ',');

    $address = $entity?->address ?? [];
    $entityAddressParts = [];
    if (is_array($address)) {
        foreach (['line1','street','address_line1','address_line2','line2','city','postcode','country'] as $k) {
            if (! empty($address[$k])) $entityAddressParts[] = $address[$k];
        }
    }
    $entityAddressLine = implode(', ', $entityAddressParts);

    $customer = $proposal->customer;
    $contactName = $customer?->primaryContact?->name;
    $customerAddressParts = array_filter([
        $customer?->address_line1,
        $customer?->address_line2,
        trim(implode(' ', array_filter([$customer?->city, $customer?->postcode]))),
        $customer?->country && strlen($customer->country) > 2 ? $customer->country : null,
    ]);
    $customerAddressLine = implode(', ', $customerAddressParts);

    $statusKey = $proposal->status;
    $statusLabel = strtoupper($statusKey);
    $colourMap = [
        'draft'    => ['bg' => '#F1F5F9', 'fg' => '#475569', 'border' => '#CBD5E1'],
        'sent'     => ['bg' => '#FFFBEB', 'fg' => '#92400E', 'border' => '#FDE68A'],
        'accepted' => ['bg' => '#D1FAE5', 'fg' => '#065F46', 'border' => '#A7F3D0'],
        'rejected' => ['bg' => '#FEE2E2', 'fg' => '#991B1B', 'border' => '#FCA5A5'],
        'expired'  => ['bg' => '#F1F5F9', 'fg' => '#94A3B8', 'border' => '#E2E8F0'],
    ];
    $sc = $colourMap[$statusKey] ?? $colourMap['draft'];

    $issueDate = $proposal->created_at?->format('j M Y');
    $validUntil = $proposal->valid_until?->format('j M Y');

    $schedule = $proposal->paymentSchedule;
    $vatRegistered = $entity?->vat_registered ?? true;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Proposal {{ $proposal->reference }} — {{ $entity?->name }}</title>
<style>
  @page { size: A4 portrait; margin: 0; }
  * { margin: 0; padding: 0; box-sizing: border-box; }
  html, body {
    background: #fff;
    color: #0F172A;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 11px;
    line-height: 1.5;
  }
  .page { padding: 15mm; }
  .no-break { page-break-inside: avoid; }
  .clearfix { clear: both; display: block; line-height: 0; font-size: 0; }
  .label {
    font-size: 9px; font-weight: bold; color: #94A3B8;
    letter-spacing: 2px; text-transform: uppercase;
  }
  table { border-collapse: collapse; }

  /* ─── Header ─── */
  .header-table { width: 100%; border: none; margin-bottom: 22px; }
  .header-table td { vertical-align: top; }
  .brand-mark {
    width: 40px; height: 40px;
    background: #F59E0B; border-radius: 4px;
    color: #fff; font-weight: bold;
    text-align: center; line-height: 40px;
    font-size: 22px; display: inline-block;
  }
  .entity-block { margin-left: 6px; display: inline-block; vertical-align: top; }
  .entity-name { font-size: 14px; font-weight: bold; color: #0F172A; }
  .entity-meta { font-size: 9.5px; color: #64748B; margin-top: 2px; }

  .title-block { text-align: right; }
  .doc-title {
    font-size: 24px; font-weight: bold;
    letter-spacing: 6px; color: #0F172A;
    margin-bottom: 6px;
  }
  .doc-ref { font-size: 11px; color: #475569; }
  .doc-ref strong { color: #0F172A; }
  .status-pill {
    display: inline-block; margin-top: 8px;
    padding: 3px 10px; border-radius: 12px;
    background: {{ $sc['bg'] }}; color: {{ $sc['fg'] }};
    border: 1px solid {{ $sc['border'] }};
    font-size: 9.5px; font-weight: bold; letter-spacing: 1.2px;
  }

  /* ─── Address blocks ─── */
  .address-table { width: 100%; margin-bottom: 22px; }
  .address-table td { vertical-align: top; width: 50%; padding-right: 12px; }
  .address-name { font-size: 12px; font-weight: bold; color: #0F172A; margin-top: 4px; }
  .address-line { color: #475569; }

  /* ─── Section heading ─── */
  .section-heading {
    font-size: 13px; font-weight: bold; color: #0F172A;
    margin: 18px 0 8px; padding-bottom: 4px;
    border-bottom: 1.5px solid #F59E0B;
  }
  .section-text { color: #334155; font-style: italic; margin-bottom: 12px; }
  .section-terms { color: #64748B; font-size: 10px; line-height: 1.6; margin-bottom: 12px; }

  /* ─── Line items ─── */
  .lines-table {
    width: 100%; margin: 8px 0 12px;
    border: 1px solid #E2E8F0;
  }
  .lines-table thead th {
    background: #F8FAFC; padding: 7px 8px;
    text-align: left; font-size: 9px;
    color: #64748B; letter-spacing: 1.5px; text-transform: uppercase;
    border-bottom: 1px solid #E2E8F0;
  }
  .lines-table .num { text-align: right; }
  .lines-table tbody td {
    padding: 8px; border-bottom: 1px solid #F1F5F9;
    vertical-align: top;
  }
  .lines-table tbody tr:nth-child(even) td { background: #FAFBFC; }
  .line-desc { font-weight: 600; color: #0F172A; }
  .line-note { color: #64748B; font-size: 10px; margin-top: 2px; }

  /* ─── Totals ─── */
  .totals-wrap { width: 100%; margin-top: 6px; }
  .totals-wrap td { vertical-align: top; }
  .totals-table { width: 240px; margin-left: auto; }
  .totals-table td {
    padding: 4px 6px; font-size: 11px; color: #334155;
  }
  .totals-table td.amt { text-align: right; font-weight: 600; }
  .totals-table tr.grand td {
    border-top: 1.5px solid #0F172A;
    padding-top: 8px; font-size: 14px;
    font-weight: bold; color: #F59E0B;
  }

  /* ─── Schedule ─── */
  .schedule-table { width: 100%; margin: 6px 0 12px; border: 1px solid #E2E8F0; }
  .schedule-table thead th {
    background: #F8FAFC; padding: 6px 8px;
    text-align: left; font-size: 9px;
    color: #64748B; letter-spacing: 1.5px; text-transform: uppercase;
    border-bottom: 1px solid #E2E8F0;
  }
  .schedule-table tbody td {
    padding: 6px 8px; border-bottom: 1px solid #F1F5F9;
  }

  /* ─── Accepted stamp ─── */
  .accept-stamp {
    margin-top: 18px; padding: 14px;
    border: 2px solid #F59E0B; border-radius: 6px;
    background: #FFFBEB;
  }
  .accept-stamp .at-title {
    font-size: 16px; font-weight: bold; color: #92400E;
    letter-spacing: 2px; margin-bottom: 6px;
  }
  .accept-stamp .at-row { color: #334155; margin-bottom: 2px; }
  .accept-stamp .at-row strong { color: #0F172A; }
  .accept-stamp .at-note {
    margin-top: 8px; font-size: 9.5px;
    color: #64748B; font-style: italic;
  }

  /* ─── Footer ─── */
  .doc-footer {
    margin-top: 22px; padding-top: 10px;
    border-top: 1px solid #E2E8F0;
    color: #94A3B8; font-size: 9px;
    text-align: center;
  }
</style>
</head>
<body>
<div class="page">

  <!-- Header -->
  <table class="header-table">
    <tr>
      <td style="width: 50%;">
        @if ($logo_data)
          <img src="{{ $logo_data }}" alt="{{ $entity?->name ?? 'Brand' }}" style="max-height: 40px; max-width: 200px;" />
        @else
          <span class="brand-mark">W</span>
        @endif
        <div class="entity-block">
          <div class="entity-name">{{ $entity?->name ?? 'Whitedash' }}</div>
          @if ($entityAddressLine)
            <div class="entity-meta">{{ $entityAddressLine }}</div>
          @endif
          @if ($entity?->company_number || $entity?->vat_number)
            <div class="entity-meta">
              @if ($entity?->company_number)Company No. {{ $entity->company_number }}@endif
              @if ($entity?->company_number && $entity?->vat_number) · @endif
              @if ($entity?->vat_number)VAT No. {{ $entity->vat_number }}@endif
            </div>
          @endif
        </div>
      </td>
      <td class="title-block" style="width: 50%;">
        <div class="doc-title">PROPOSAL</div>
        <div class="doc-ref">Ref: <strong>{{ $proposal->reference }}</strong></div>
        <div class="doc-ref">Date issued: {{ $issueDate }}</div>
        @if ($validUntil)
          <div class="doc-ref">Valid until: <strong>{{ $validUntil }}</strong></div>
        @endif
        <div><span class="status-pill">{{ $statusLabel }}</span></div>
      </td>
    </tr>
  </table>

  <!-- Prepared for -->
  <table class="address-table">
    <tr>
      <td>
        <div class="label">Prepared For</div>
        <div class="address-name">{{ $customer?->name }}</div>
        @if ($contactName)
          <div class="address-line">Attn: {{ $contactName }}</div>
        @endif
        @if ($customerAddressLine)
          <div class="address-line">{{ $customerAddressLine }}</div>
        @endif
      </td>
      <td>
        <div class="label">Proposal Title</div>
        <div class="address-name">{{ $proposal->title }}</div>
      </td>
    </tr>
  </table>

  <!-- Overview / description -->
  @if ($proposal->description)
    <div class="section-heading">Overview</div>
    <div class="section-text">{{ $proposal->description }}</div>
  @endif

  <!-- Line items -->
  <div class="section-heading">Items</div>
  <table class="lines-table">
    <thead>
      <tr>
        <th style="width: 50%;">Description</th>
        <th class="num" style="width: 10%;">Qty</th>
        <th class="num" style="width: 15%;">Unit Price</th>
        <th class="num" style="width: 12%;">Discount</th>
        <th class="num" style="width: 13%;">Amount</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($proposal->lines as $line)
        <tr>
          <td>
            <div class="line-desc">{{ $line->description }}</div>
            @if ($line->note)<div class="line-note">{{ $line->note }}</div>@endif
          </td>
          <td class="num">{{ rtrim(rtrim(number_format((float) $line->quantity, 2), '0'), '.') }}</td>
          <td class="num">{{ $gbp($line->unit_price) }}</td>
          <td class="num">
            @if ($line->discount_amount > 0)
              -{{ $gbp($line->discount_amount) }}
            @else
              —
            @endif
          </td>
          <td class="num">{{ $gbp($line->amount) }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <!-- Totals -->
  <table class="totals-wrap"><tr><td>
    <table class="totals-table">
      @if ($proposal->discount_amount > 0)
        <tr>
          <td>Subtotal (gross)</td>
          <td class="amt">{{ $gbp($proposal->subtotal + $proposal->discount_amount) }}</td>
        </tr>
        <tr>
          <td style="color: #16A34A;">Discount</td>
          <td class="amt" style="color: #16A34A;">-{{ $gbp($proposal->discount_amount) }}</td>
        </tr>
      @endif
      <tr>
        <td>{{ $proposal->discount_amount > 0 ? 'Net subtotal' : 'Subtotal' }}</td>
        <td class="amt">{{ $gbp($proposal->subtotal) }}</td>
      </tr>
      @if ($vatRegistered && $proposal->vat_amount > 0)
        <tr>
          <td>VAT ({{ rtrim(rtrim(number_format((float) $proposal->vat_rate, 2), '0'), '.') }}%)</td>
          <td class="amt">{{ $gbp($proposal->vat_amount) }}</td>
        </tr>
      @endif
      <tr class="grand">
        <td>TOTAL</td>
        <td class="amt">{{ $gbp($proposal->total) }}</td>
      </tr>
    </table>
  </td></tr></table>

  <!-- Payment schedule -->
  @if ($schedule && $schedule->items->count() > 0)
    <div class="section-heading no-break">Payment Schedule</div>
    <table class="schedule-table no-break">
      <thead>
        <tr>
          <th style="width: 45%;">Milestone / Stage</th>
          <th class="num" style="width: 20%;">Amount</th>
          <th style="width: 35%;">Trigger</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($schedule->items as $item)
          <tr>
            <td>{{ $item->label }}</td>
            <td class="num">{{ $gbp($item->amount) }}</td>
            <td>
              @switch($item->trigger_type)
                @case('immediate') On acceptance @break
                @case('on_date') On {{ $item->trigger_date?->format('j M Y') }} @break
                @case('on_milestone') When milestone completes @break
                @default Manual invoice
              @endswitch
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif

  <!-- Terms & conditions -->
  @if ($proposal->terms)
    <div class="section-heading">Terms & Conditions</div>
    <div class="section-terms">{!! nl2br(e($proposal->terms)) !!}</div>
  @endif

  <!-- Accepted stamp (only on the post-accept PDF) -->
  @if ($with_acceptance && $proposal->accepted_at)
    <div class="accept-stamp no-break">
      <div class="at-title">✓ ACCEPTED</div>
      <div class="at-row"><strong>Accepted by:</strong> {{ $proposal->accepted_by_name }}</div>
      <div class="at-row"><strong>Date:</strong> {{ $proposal->accepted_at->format('j M Y H:i') }}</div>
      @if ($proposal->accepted_ip)
        <div class="at-row"><strong>IP Address:</strong> {{ $proposal->accepted_ip }}</div>
      @endif
      <div class="at-note">
        This document constitutes agreement to the above scope of work,
        pricing, and terms &amp; conditions.
      </div>
    </div>
  @endif

  <!-- Footer -->
  <div class="doc-footer">
    @if ($entity)
      {{ $entity->legal_name ?? $entity->name }}
      @if ($entity->company_number) · Company No. {{ $entity->company_number }}@endif
      @if ($entity->vat_number) · VAT {{ $entity->vat_number }}@endif
      <br>
    @endif
    @if ($validUntil)
      This proposal expires on {{ $validUntil }}. ·
    @endif
    Generated by Powerhouse · Whitedash Holdings
  </div>

</div>
</body>
</html>
