# Powerhouse — Frontend conventions

Concrete, enforceable conventions. CLAUDE.md points here; this file holds the
detail. Add to it when a bug class recurs.

---

## Section panels

**The recurring bug class:** page sections rendered without the canonical card
wrapper / inner padding — bare rows with no border, or empty-states / footers /
action rows sitting flush to the card's left edge.

### Canonical pattern

The canonical detail/section panel is the **global `.card` class set** in
`resources/css/app.css` (NOT the rarely-used `resources/js/Components/UI/Card.vue`,
which is legacy and should not be used for new panels):

```
<section class="card">                ← wrapper: border + radius + bg + shadow (NO padding of its own)
  <header class="card-header">        ← icon + title (+ optional .sub); padded 14×18, bottom divider
    <div class="h-icon"><Icon/></div>
    <div><h3>Title</h3><div class="sub">Subtitle</div></div>
  </header>
  <div class="card-body"> … </div>     ← padded body (14×16). OR each content block carries
                                          its own horizontal padding (~16–18px).
</section>
```

Structural contract:
- **Wrapper** = `<section class="card">`. Border/radius/bg/shadow come from `.card`. `.card` has **no padding** and **no `overflow:hidden`** (row `···` menus must escape — see the dropdown-clipping rule in CLAUDE.md).
- **Header** = `.card-header` (icon + title + optional `.sub`) or `.card-head`.
- **Body** = `.card-body` (padding `14px 16px`), or content blocks that each carry their own horizontal padding (~16–18px to line up with the header).
- **Empty states, footers, action rows** live **inside** the padded body and inherit that horizontal padding. They are never flush siblings of `.card`.

### Rules

1. Every detail/section panel MUST use `<section class="card">`. Never hand-roll a section container with raw `<div>`s or inline `border`/`background`/`border-radius` styles.
2. An empty state, footer, or action row MUST sit inside the padded body — never as a direct flush child of `.card`.
3. When an empty-state class is reused under a different parent, give it a padding rule for that parent too (a padding rule scoped to the wrong ancestor renders flush — this was a real bug: `.cust-projects .cp-empty` existed but the element under `.cust-proposals` had none).
4. List/table pages use a bare `<section class="table-card">` (the no-`overflow:hidden` table wrapper — it carries its own border/bg/shadow, so do NOT add the `card` class alongside it); their empty states are in-table `<td colspan>` or a `.empty-state` block — both fine.

### DO / DON'T

```html
<!-- DON'T — hand-rolled container, empty state flush to the edge -->
<div style="border:1px solid var(--border); border-radius:8px; background:#fff;">
  <header class="card-header"><h3>Proposals</h3></header>
  <div class="cp-empty">No proposals yet.</div>   <!-- no horizontal padding → flush -->
</div>

<!-- DO — canonical card, empty state inside the padded body -->
<section class="card">
  <header class="card-header">
    <div class="h-icon"><IconFile :size="16" /></div>
    <div><h3>Proposals</h3><div class="sub">Quotes sent to this customer</div></div>
  </header>
  <div class="card-body">
    <div class="cp-empty">No proposals yet.</div>   <!-- inherits card-body padding -->
  </div>
</section>
```

### Deliberate exceptions (allowed — don't "fix" these)

Some areas use a fully-chromed bespoke panel family on purpose. These are fine
**because they carry their own complete border + bg + padding**; do not force
them onto `.card`, but new work in those areas should follow the area's pattern:
- Portal area: `.wd .card` / `.card-pad` (its own design system in `portal.css`).
- Referrer area (`resources/js/Pages/Referrer/*`): external-facing surface with
  its own card family (shares the portal look); outside the internal
  section-panel rule's scope.
- Settings form family: the `.sec-label` stacked form rows.
- Master-detail: `.billing-entities` (`.ent-list-card` / `.form-card`) used by Settings → BillingEntities + Products.
- Card-grids / accordions: `.plan-card` (ProductPlans), `.form-card` (Forms), `.workflow-card` (Workflows), `.wu-site-card` (WordPress), `.my-account-card` (Account), `.help-info-card` (Help).
- Document/PDF layouts: `.inv-doc` / `.doc-card` / `.proposal-doc` (full-bleed by design).

If you're unsure whether a panel is a deliberate exception, it isn't — use `.card`.

### Guard (hard gate)

`composer audit:sections` (v2) HARD-FAILS — exit 1 fails the gate. Two checks:

1. **Check 1 (the rule itself):** any `<div>` wrapper whose class token list
   contains `card` or `table-card` is a violation — the wrapper must be
   `<section>`. `Pages/Portal/` and `Pages/Referrer/` are path-exempt (their
   own card families); `Pages/Public/` is in scope by decision.
2. **Check 2 (hand-rolled net):** the inline `border` + `border-radius`
   heuristic, filtered through `scripts/audit-sections.allow` — an explicit,
   reviewed allowlist (`path-substring|line-regex|reason`, no `|` inside
   fields). Known non-panels (alert banners, form inputs, modal internals,
   dropdowns) and exempted families are suppressed by named entries.

On a flag: convert the panel. Only add an allowlist entry when the hit is
genuinely not a section panel — every entry carries its reason and is reviewed
in the PR. Known blind spot: dynamic `:class` bindings are invisible to grep.

```
composer audit:sections        # exits 1 on violations (part of composer gate)
composer gate                  # full gate: Pint, PHPStan, audit:sections, tests, build
```
