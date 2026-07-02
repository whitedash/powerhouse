# Side-panel fix — design note (Issues A + B)

Branch `fix/side-panel-dismiss` off `main` (52b997c). Builds on the panel
investigation: no shared slide-over component; panels are per-page in three
patterns. **Pattern A** (headlessui `Dialog @close`) and **Pattern B** (sibling
`.slide-over-backdrop` `@click`) are both immune to the drag-release bug and are
**left untouched**. **Pattern C** — a clickable *ancestor* overlay
(`.slide-over-overlay` / `*-modal-overlay`) with `@click.self`, panel nested
inside — is the confirmed bug and the only thing changed.

Settled approach (not a shared `<SlideOver>`, not a 25-panel migration): a
targeted in-place fix via two small reusable utilities.

## Issue A — `v-overlay-dismiss` directive (the drag-release fix)

`resources/js/directives/overlayDismiss.js`, registered globally in `app.js` as
`v-overlay-dismiss`. Drop-in replacement for `@click.self="close"` on the
overlay element.

Mechanism: a native `click` fires on the **common ancestor** of mousedown+mouseup,
so press-inside-panel / release-on-overlay made `@click.self` match and close.
The directive records, on `pointerdown`, whether the press **started on the
overlay itself** (`e.target === el`); on `click` it invokes the close callback
**only if both** the press and the release (`e.target === el`) were the overlay —
i.e. the drag did not originate inside the panel. Same press-target guard
headlessui uses internally. Also closes on **Escape** while mounted (Pattern C
had none; brings it in line with Pattern A); listener is added on mount and
removed on unmount, and the overlay is `v-if`-gated so it's only live while open.

API: `v-overlay-dismiss="closeFn"` — `closeFn` is called for genuine overlay
click **and** Escape. Pass a plain `() => (show = false)` (Issue A only) or a
dirty-guarded `attemptClose` (Issue A + B). Directive is agnostic.

Transformation at every site: `@click.self="X = false"` → `v-overlay-dismiss="() => (X = false)"` (or `="closeFn"` / `="guard.attemptClose"`).

## Issue B — `useDirtyClose` composable (unsaved-changes discard guard)

`resources/js/Composables/useDirtyClose.js`. `useDirtyClose(() => form.isDirty, () => { show.value = false })`
returns `{ confirmingDiscard, attemptClose, confirmDiscard, cancelDiscard }`.
Route **every** close surface through `attemptClose`: overlay (`v-overlay-dismiss="guard.attemptClose"`),
X button (`@click="guard.attemptClose"`), Escape (via the directive), Cancel
button (`@click="guard.attemptClose"`). If `form.isDirty` → `confirmingDiscard`
flips true; clean panels close immediately.

Discard UX: reuse `Components/UI/ConfirmModal.vue` (already a headlessui Dialog
with its own backdrop/Escape) — one per guarded panel:
```
<ConfirmModal :show="guard.confirmingDiscard" title="Discard changes?"
  message="You have unsaved changes. Discard them?" confirm-label="Discard"
  cancel-label="Keep editing" variant="warning"
  @confirm="guard.confirmDiscard" @cancel="guard.cancelDiscard" />
```
"Discard" closes; "Keep editing" leaves the panel + data intact. Panels reset
their form on open (existing pattern), so a discarded form is clean on reopen.

Navigation-away guarding (Inertia visit/Link) is **out of scope** (not a panel
close surface; would need a global router guard) — reported, not built.

## Pattern-C inventory — 27 overlay sites / 13 files

Issue A (`v-overlay-dismiss`) applies to **every** site below. Issue B column:
✅ = dirty-guard now (useForm form panel); 🔁 = reason mini-form (guard if it
has its own form state); ⊘ = read-only (no form → Issue A only); ⏸ = deferred
(complex custom state — Issue A still applied), with reason.

| File | Panel(s) | Issue B |
|---|---|---|
| Leads/Index | showCreate ✅ · showLostModal 🔁 · showReject 🔁 | guard |
| Leads/Show | showEdit ✅ · showActivity ✅ · showConvert ✅ · showLostModal 🔁 | guard |
| Projects/Index | showCreate (closeCreate) ✅ | guard |
| Projects/Show | showEdit ✅ · showMilestone ✅ · showLogTime ✅ · showAddExpense ✅ · showEditTask ✅ · showBlockedModal 🔁 · showInvoiceModal 🔁 | guard the form panels |
| Suppliers/Index | showForm ✅ | guard |
| Expenses/Index | showForm ✅ | guard |
| Settings/Products | showSupplierForm ✅ | guard |
| Forms/Themes/Index | editorOpen ✅ | guard |
| Customers/Show | websiteModal ✅ · domainModal ✅ · pageSpeedModal ⊘ | guard website+domain |
| Forms/Index | editorOpen ⏸ (large multi-section form builder, bespoke state) · showPreview ⊘ | Issue A only |
| Workflows/Index | editorOpen ⏸ (canvas/graph builder, bespoke state) · | Issue A only |
| Dashboard | showAllAttention ⊘ (read-only attention list) | Issue A only |
| Settings/ReminderTemplates | previewing ⊘ (read-only preview) | Issue A only |

Deferred for Issue B (Issue A still fixes the bug there): the two **builder**
editors (Forms/Index, Workflows/Index) — their "dirty" state isn't a single
`form.isDirty`; a correct guard needs bespoke change-tracking, so per the brief
they're a separate follow-up. Read-only panels need no guard.

Verification: composer gate (Pint/PHPStan/audits/PHPUnit/Vite build) + browser
checks on Leads/Index, Forms/Index, Projects/Show (drag-release fixed, genuine
click closes, Escape, discard confirm across all surfaces, clean closes
immediately) + a Pattern A/B regression check.
