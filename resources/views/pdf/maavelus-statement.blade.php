@php
    /* ---- Computed values ---- */
    $statusLabel = $statement->status === 'confirmed' ? 'CONFIRMED' : 'DRAFT';
    $statusBadge = $statement->status === 'confirmed'
        ? ['bg' => '#D1FAE5', 'fg' => '#065F46', 'border' => '#A7F3D0']
        : ['bg' => '#F1F5F9', 'fg' => '#475569', 'border' => '#CBD5E1'];

    $periodLabel = $statement->period_start?->format('F Y') ?? '—';
    $periodRange = $statement->period_start && $statement->period_end
        ? $statement->period_start->format('j') . '–' . $statement->period_end->format('j F Y')
        : '—';

    $entityMetaParts = [];
    if ($entity?->company_number) $entityMetaParts[] = 'Company No. ' . $entity->company_number;
    if ($entity?->vat_number) $entityMetaParts[] = 'VAT No. ' . $entity->vat_number;
    $entityMetaLine = implode(' · ', $entityMetaParts);

    $dataSourceLabel = $statement->data_source === 'api' ? 'Maavelus Control Panel API' : 'Manual entry';

    $totalCommissions = collect($commission_totals)->sum('total');
    $netRevenue = (float) $statement->total_fees - $totalCommissions;

    $gbp = fn($n) => '£' . number_format((float) $n, 2, '.', ',');

    $legalParts = [];
    if ($entity) {
        $legalParts[] = $entity->legal_name ?? $entity->name;
        if ($entity->company_number) $legalParts[] = 'Company No. ' . $entity->company_number;
        if ($entity->vat_number) $legalParts[] = 'VAT ' . $entity->vat_number;
    }
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Maavelus Revenue Statement — {{ $periodLabel }}</title>
<style>
  @page { size: A4 portrait; margin: 0; }
  * { margin: 0; padding: 0; box-sizing: border-box; }
  html, body {
    background: #FFFFFF;
    color: #0F172A;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 11px;
    line-height: 1.45;
  }
  .page { background: #FFFFFF; padding: 15mm 15mm 15mm 15mm; }
  table { border-collapse: collapse; }
  .label {
    font-size: 9px; font-weight: bold; color: #94A3B8;
    letter-spacing: 2px; text-transform: uppercase;
  }
  .brand-mark {
    width: 40px; height: 40px; background: #F59E0B; border-radius: 4px;
    color: #FFFFFF; font-family: Arial, Helvetica, sans-serif;
    font-weight: bold; font-size: 20px; text-align: center; line-height: 40px;
  }
  .company-name { font-size: 18px; font-weight: bold; color: #0F172A; line-height: 1.2; }
  .meta-line { font-size: 10px; color: #64748B; line-height: 1.5; }
  .statement-word { font-size: 22px; font-weight: bold; color: #0F172A; letter-spacing: 2px; line-height: 1; }
  .period-line { font: bold 13px/1.4 Arial, Helvetica, sans-serif; color: #64748B; margin-top: 6px; }
  .status-badge {
    display: inline-block; font-size: 11px; font-weight: bold;
    letter-spacing: 1px; padding: 4px 12px; border-radius: 3px; margin-top: 10px;
  }
  .divider { border: none; border-top: 2px solid #F59E0B; margin: 14px 0; }

  /* Period summary band */
  .period-band {
    background: #F1F5F9; padding: 14px 16px; margin-top: 12px;
  }
  .period-band td { vertical-align: top; padding: 4px 0; }
  .period-band .k {
    font-size: 9px; font-weight: bold; color: #94A3B8;
    letter-spacing: 1.5px; text-transform: uppercase; width: 160px;
  }
  .period-band .v { font-size: 13px; color: #0F172A; }
  .period-band .v.large {
    font-size: 22px; font-weight: bold; color: #F59E0B; line-height: 1.2;
  }

  /* Line items table */
  .items { width: 100%; margin-top: 22px; border: none; table-layout: fixed; }
  .items thead th {
    background: #F1F5F9; border-bottom: 1px solid #E2E8F0;
    font-size: 9px; font-weight: bold; color: #94A3B8;
    letter-spacing: 1.5px; text-transform: uppercase;
    text-align: left; padding: 8px 10px;
  }
  .items thead th.right { text-align: right; }
  .items tbody td {
    border-bottom: 1px solid #EEF2F7; padding: 10px 10px;
    vertical-align: top; word-wrap: break-word;
  }
  .items tbody tr.even td { background: #FAFBFC; }
  .items .name { font-size: 13px; color: #0F172A; }
  .items .num { font-size: 13px; color: #0F172A; text-align: right; white-space: nowrap; }
  .items .amt { font-size: 13px; font-weight: bold; color: #0F172A; text-align: right; white-space: nowrap; }
  .items tr.total td {
    font-weight: bold; background: #FAFBFC;
    border-top: 2px solid #E2E8F0; border-bottom: 0; padding-top: 12px;
  }
  .items tr.total td.right { color: #F59E0B; font-size: 14px; }

  /* Section heading */
  .section-head {
    font-size: 10px; font-weight: bold; color: #475569;
    letter-spacing: 1.5px; text-transform: uppercase;
    margin-top: 22px; padding-bottom: 6px; border-bottom: 1px solid #E2E8F0;
  }

  /* Net revenue panel */
  .net-panel {
    background: #F8FAFC; border: 1px solid #E2E8F0;
    border-left: 4px solid #F59E0B; padding: 14px 16px; margin-top: 18px;
  }
  .net-panel table { width: 100%; border: none; }
  .net-panel td { padding: 4px 0; font-size: 13px; color: #0F172A; }
  .net-panel td.right { text-align: right; white-space: nowrap; font-weight: 500; }
  .net-panel tr.commissions td { color: #B91C1C; }
  .net-panel tr.divider-row td {
    border-top: 1px solid #E2E8F0; padding: 0; height: 1px;
    line-height: 0; font-size: 0;
  }
  .net-panel tr.net td { font-size: 16px; font-weight: bold; padding-top: 10px; }
  .net-panel tr.net td.right { color: #047857; }

  /* Notes block */
  .notes-box {
    background: #F8FAFC; border: 1px solid #E2E8F0;
    padding: 12px 14px; margin-top: 14px;
  }
  .notes-body {
    font-size: 11px; color: #64748B; font-style: italic;
    line-height: 1.6; margin-top: 6px;
  }

  /* Footer */
  .footer {
    width: 100%; border-top: 1px solid #E2E8F0;
    margin-top: 20px; padding-top: 10px; table-layout: fixed;
  }
  .footer td { font-size: 10px; color: #94A3B8; vertical-align: middle; }
  .footer td.f-right { text-align: right; }
  .draft-notice {
    background: #FFFBEB; color: #92400E; padding: 6px 10px;
    border: 1px solid #FDE68A; border-radius: 3px;
    font-size: 11px; font-weight: bold; margin-top: 10px; display: inline-block;
  }
</style>
</head>
<body>
  <div class="page">

    {{-- ============ HEADER ============ --}}
    <table width="100%" cellpadding="0" cellspacing="0">
      <tr>
        <td width="55%" valign="top">
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
              <td width="52" valign="top" style="padding-right: 12px;">
                @if(! empty($logo_data))
                  <img src="{{ $logo_data }}" alt="" style="height: 40px; max-width: 120px; object-fit: contain;">
                @else
                  <div class="brand-mark">M</div>
                @endif
              </td>
              <td valign="top">
                <div class="company-name">{{ $entity?->name ?? 'Maavelus' }}</div>
                @if($entityMetaLine)
                  <div class="meta-line">{{ $entityMetaLine }}</div>
                @endif
              </td>
            </tr>
          </table>
        </td>
        <td width="45%" valign="top" align="right">
          <div class="statement-word">REVENUE STATEMENT</div>
          <div class="period-line">{{ $periodLabel }}</div>
          <div><span class="status-badge" style="background: {{ $statusBadge['bg'] }}; color: {{ $statusBadge['fg'] }}; border: 1px solid {{ $statusBadge['border'] }};">{{ $statusLabel }}</span></div>
        </td>
      </tr>
    </table>

    <hr class="divider">

    {{-- ============ PERIOD SUMMARY ============ --}}
    <table class="period-band" width="100%" cellpadding="0" cellspacing="0">
      <colgroup>
        <col style="width: 160px;">
        <col>
      </colgroup>
      <tr>
        <td class="k">Period</td>
        <td class="v">{{ $periodLabel }} ({{ $periodRange }})</td>
      </tr>
      <tr>
        <td class="k">Total platform fees</td>
        <td class="v large">{{ $gbp($statement->total_fees) }}</td>
      </tr>
      @if($statement->total_orders)
      <tr>
        <td class="k">Total orders</td>
        <td class="v">{{ number_format($statement->total_orders) }}</td>
      </tr>
      @endif
      <tr>
        <td class="k">Data source</td>
        <td class="v">{{ $dataSourceLabel }}</td>
      </tr>
    </table>

    {{-- ============ PER RESTAURANT TABLE ============ --}}
    <div class="section-head">Fees per restaurant</div>
    <table class="items" width="100%" cellpadding="0" cellspacing="0">
      <colgroup>
        <col style="width: 60%;">
        <col style="width: 20%;">
        <col style="width: 20%;">
      </colgroup>
      <thead>
        <tr>
          <th width="60%">Restaurant</th>
          <th width="20%" class="right">Fees collected</th>
          <th width="20%" class="right">Orders</th>
        </tr>
      </thead>
      <tbody>
        @foreach($statement->lines as $i => $line)
          <tr class="{{ $i % 2 === 0 ? 'odd' : 'even' }}">
            <td width="60%"><div class="name">{{ $line->customer?->name ?? 'Unknown' }}</div></td>
            <td width="20%" class="amt">{{ $gbp($line->total_fees) }}</td>
            <td width="20%" class="num">{{ $line->order_count !== null ? number_format($line->order_count) : '—' }}</td>
          </tr>
        @endforeach
        <tr class="total">
          <td width="60%">Total</td>
          <td width="20%" class="right">{{ $gbp($statement->total_fees) }}</td>
          <td width="20%" class="num">{{ $statement->total_orders ? number_format($statement->total_orders) : '—' }}</td>
        </tr>
      </tbody>
    </table>

    {{-- ============ REFERRAL COMMISSIONS ============ --}}
    @if($statement->commissions_generated && ! empty($commission_totals))
      <div class="section-head">Referral commissions</div>
      <table class="items" width="100%" cellpadding="0" cellspacing="0">
        <colgroup>
          <col style="width: 70%;">
          <col style="width: 30%;">
        </colgroup>
        <thead>
          <tr>
            <th width="70%">Referrer</th>
            <th width="30%" class="right">Commission due</th>
          </tr>
        </thead>
        <tbody>
          @foreach($commission_totals as $i => $row)
            <tr class="{{ $i % 2 === 0 ? 'odd' : 'even' }}">
              <td width="70%"><div class="name">{{ $row['referrer_name'] }}</div></td>
              <td width="30%" class="amt">{{ $gbp($row['total']) }}</td>
            </tr>
          @endforeach
          <tr class="total">
            <td width="70%">Total commissions</td>
            <td width="30%" class="right">{{ $gbp($totalCommissions) }}</td>
          </tr>
        </tbody>
      </table>
    @elseif($statement->commissions_generated)
      <div class="section-head">Referral commissions</div>
      <p style="font-size: 12px; color: #64748B; font-style: italic; padding: 10px 0;">
        No referrer commissions for this period — no attributed customers had an active rule.
      </p>
    @endif

    {{-- ============ NET MAAVELUS REVENUE ============ --}}
    @if($statement->commissions_generated)
      <div class="net-panel">
        <table>
          <tr>
            <td>Total platform fees</td>
            <td class="right">{{ $gbp($statement->total_fees) }}</td>
          </tr>
          <tr class="commissions">
            <td>Less referral commissions</td>
            <td class="right">({{ $gbp($totalCommissions) }})</td>
          </tr>
          <tr class="divider-row"><td colspan="2"></td></tr>
          <tr class="net">
            <td>Net Maavelus revenue</td>
            <td class="right">{{ $gbp($netRevenue) }}</td>
          </tr>
        </table>
      </div>
    @endif

    {{-- ============ NOTES ============ --}}
    @if($statement->notes)
      <div class="notes-box">
        <div class="label">Notes</div>
        <div class="notes-body">{!! nl2br(e($statement->notes)) !!}</div>
      </div>
    @endif

    {{-- ============ FOOTER ============ --}}
    @if($statement->isConfirmed())
      <table class="footer" width="100%" cellpadding="0" cellspacing="0">
        <colgroup>
          <col style="width: 50%;">
          <col style="width: 50%;">
        </colgroup>
        <tr>
          <td width="50%">
            Generated by Powerhouse · Whitedash Holdings
          </td>
          <td width="50%" class="f-right">
            Confirmed by {{ $statement->confirmedBy?->name ?? 'Unknown' }}
            on {{ $statement->confirmed_at?->format('j M Y') ?? '—' }}
          </td>
        </tr>
      </table>
    @else
      <div class="draft-notice">DRAFT — not yet confirmed</div>
      <table class="footer" width="100%" cellpadding="0" cellspacing="0" style="margin-top: 8px;">
        <tr>
          <td>Generated by Powerhouse · Whitedash Holdings</td>
        </tr>
      </table>
    @endif

  </div>
</body>
</html>
