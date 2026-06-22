# Roles & Permissions — Phase 1 Design (data model + Spatie integration)

> Status: **design / approved architecture, not yet implemented.**
> Scope of this doc: the **data model** and the **Spatie integration approach** for the
> roles-and-permissions system whose UI is mocked in `design/Roles & Permissions.html`.
> This is **phase 1** — it lands the schema and seeds it access-identically to today, but
> **changes no enforcement**. The deliberate enforcement swap is a later phase.
>
> Inputs (do not re-derive — established earlier this session):
> - **Authorization-mechanism inventory** — the ~9 `role:` route gates, the per-model
>   policies reading `isStaff()`/`isSuperAdmin()`, no `Gate::before`, the Part-4 permission
>   catalogue and the Part-6 day-1 staff grant.
> - **Assignment-model investigation** — the four scoped areas and their "Assigned"
>   queries: Projects = `whereHas('members')`, Tasks/Leads/Support = `where('assigned_to', userId)`
>   (Support's `assigned_to` is nullable).
>
> Architecture is **settled**: Spatie laravel-permission for the boolean permissions +
> a **dedicated scope table** for the four areas' tri-state (All/Assigned/None) scopes.
> This doc designs *within* that decision; it does not re-litigate it.

---

## 1. Current setup (grounding the design)

### 1.1 Stack / versions
- Laravel **`^13.8`**, PHP **`^8.3`**, Laravel Passport **`^13.7`** (`composer.json`).
- Already-installed Spatie packages: **`spatie/laravel-activitylog ^5.0`**, **`spatie/laravel-responsecache ^8.4`** — so the Spatie ecosystem already resolves on this Laravel 13 stack. `spatie/laravel-permission` is **not** installed.

### 1.2 Guards & providers (`config/auth.php`)
| Guard | Driver | Provider | Used by |
|---|---|---|---|
| `web` (default) | session | `users` (`App\Models\User`) | **internal staff + referrers** |
| `portal` | session | `portal_users` (`App\Models\PortalUser`) | customer portal |
| `api` | passport | `portal_users` | `/oauth/userinfo`, `/oauth/products` (customer tokens) |

**Internal roles belong to the `web` guard.** Passport's `api` guard resolves *portal users*, not staff — staff never consume their own API — so Spatie's internal roles/permissions are entirely orthogonal to Passport. **No Passport conflict.**

### 1.3 User model (`app/Models/User.php`)
Traits today: `HasApiTokens` (Passport), `HasFactory`, `Notifiable`; implements `OAuthenticatable`.
Role API today: a native string column `role` (enum `super_admin|staff|referrer`) read via two helpers:

```php
public function isSuperAdmin(): bool { return $this->role === 'super_admin'; }
public function isStaff(): bool { return in_array($this->role, ['super_admin', 'staff'], true); }
```

- **No `roles()` relationship exists** → Spatie's `HasRoles` (which adds `roles()`, `permissions()`, `hasRole()`, `assignRole()`, `getRoleNames()`, …) can be added with **no method/relation collision**.
- The existing **`role` (singular, string) attribute coexists** with Spatie — Spatie never defines a `role` attribute (its relation is `roles`, plural). The enum column stays intact through the coexistence window.
- `referrer()` (HasOne), `assignedCustomers()`, `assignedTickets()`, `tasks()` — none clash with Spatie.

### 1.4 How `role` is read across the app (why cutover must be staged)
The enum is load-bearing in **three layers**, all of which keep running in phase 1:
1. **Route middleware** — `EnsureRole` (alias **`role`**), the outer internal group `role:super_admin,staff` plus ~8 nested `role:super_admin` groups, and `role:referrer`.
2. **Policies** — every `App\Policies\*` body returns `isStaff()` / `isSuperAdmin()` (+ a `manage-deployment` Gate).
3. **Controllers / middleware / sharing** — in-controller `isSuperAdmin()` checks (e.g. `ExpenseController::approve`), `RedirectIfReferrer`, and `HandleInertiaRequests::share()` exposing `auth.user.role` to the Vue front-end.

⚠️ **Coexistence flag — middleware alias collision.** The alias **`role` is already bound to `EnsureRole`** in `bootstrap/app.php`. Spatie ships a `RoleMiddleware`/`PermissionMiddleware`/`RoleOrPermissionMiddleware`; in v6 these are **not auto-aliased**, you register them yourself. Phase 1 must **not** register Spatie's middleware under `role` (it would clobber `EnsureRole`). When the enforcement phase swaps gates, it will introduce a **new** alias (e.g. `permission`) and retire `EnsureRole` deliberately.

### 1.5 Deployment implication (must be called out)
`vendor/` is **gitignored** and the host is **cPanel shared hosting with no server-side Composer** (per `cpanel-laravel-deploy`). Adding `spatie/laravel-permission` is a **new Composer dependency**, so:
- **This is the build where the deploy bundle must ship an updated `vendor/`** containing the new package (and its published config/migrations). The bundling step in the deploy skill/process must be re-run against the post-`composer require` tree.
- After deploy + seeding, **`php artisan permission:cache-reset`** (Spatie caches the permission map) must run via the Settings → Deployment clear-cache action.
- `SCHEMA.md` (the project's DB source-of-truth) must gain the Spatie tables + `role_scopes` when the implementation lands.

---

## 2. Spatie laravel-permission integration approach

### 2.1 Version
Target **`spatie/laravel-permission` v6** (current major). v6 supports Laravel 10/11/12 and the L13 line; **confirm the exact compatible minor at install** via `composer require spatie/laravel-permission` (the resolver picks it — the already-installed Spatie packages prove L13 resolves), then **pin the resolved version** in `composer.json`. Do not hand-pick a version number blind.

### 2.2 What installing it creates
Publishing `--provider="Spatie\Permission\PermissionServiceProvider"` yields:
- **Config**: `config/permission.php` (teams **off**, default `guard_name`, cache settings, model class bindings).
- **Migration**: creates five tables — `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`. None of these names exist today (project's domain `role` columns are on `project_members`/`contacts`/etc., not a `roles` table) → **no table collision**.

### 2.3 Guard registration
All internal roles/permissions are registered under **`guard_name = 'web'`** (the default guard, which is the staff guard). Set `protected $guard_name = 'web';` on `User` to make Spatie's guard inference explicit and prevent it ever inferring `api`/`portal`. Portal/referrer concerns stay out of Spatie.

### 2.4 User model change (additive, non-breaking)
```php
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements OAuthenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    protected $guard_name = 'web';   // pin Spatie to the staff guard
    // existing role enum + isStaff()/isSuperAdmin() stay untouched
}
```
Adding the trait only *adds* capability — nothing reads it in phase 1, so behaviour is unchanged.

---

## 3. The scope table (`role_scopes`)

The four scoped areas need a **tri-state** (All/Assigned/None) per role. That is **not** a boolean, so it does **not** live in Spatie's `permissions`. It lives in a dedicated table.

> **Boundary (locked):** the scope table holds **only** the tri-state All/Assigned/None.
> Support's `support.view_unassigned` is a **boolean permission** and lives in Spatie's
> `permissions` as an ordinary toggle — **never** in `role_scopes`. It *composes with*
> the Support scope at enforcement time (§4); it is not itself a scope.

### 3.1 Schema (illustrative design — not a migration to run now)
```
role_scopes
-----------
id            BIGINT PK
role_id       BIGINT  FK -> roles.id  ON DELETE CASCADE
area          ENUM('projects','tasks','leads','support')   NOT NULL
scope         ENUM('all','assigned','none')                NOT NULL
created_at, updated_at   (nullable timestamps — change provenance; mutations also hit activity_log)

UNIQUE (role_id, area)          -- exactly one scope per (role, area): invalid states unrepresentable
INDEX  (area, scope)            -- answers "which roles have area=X at scope=Y" (audit query)
```

Design rationale, point by point:
- **One row = one (role, area) = one scope value.** The `UNIQUE(role_id, area)` makes "two scopes for the same role+area" **impossible**, and the `scope` ENUM makes any value outside `all|assigned|none` impossible — i.e. **invalid states are unrepresentable**, per the locked decision.
- **`area` is ENUM-constrained to the four areas.** DB-level enforcement mirrors the `users.role` ENUM precedent in `SCHEMA.md`. Adding a fifth scoped area later is a small ENUM-altering migration (areas change rarely — acceptable).
- **Code-side source of truth:** back both columns with PHP enums — `App\Enums\ScopeArea` (`projects|tasks|leads|support`) and `App\Enums\AccessScope` (`all|assigned|none`) — cast on the `RoleScope` model. This mirrors the existing `App\Enums\PersonRole` pattern noted in `SCHEMA.md` and keeps the four area keys defined once.
- **FK → `roles.id`, `ON DELETE CASCADE`:** scopes belong to a Spatie role; deleting a role drops its scope rows. (`role_scopes` migration must be timestamped **after** Spatie's `create_permission_tables` so the FK target exists.)
- **Default = None when no row exists.** Absence of a `(role, area)` row is interpreted by the resolver as **`none`** — the safe default that matches "a new role starts blank." The seeder therefore only needs to write rows that are *not* None (it writes staff's four `all` rows; a brand-new custom role with no rows is implicitly None everywhere until the admin sets scopes).
- **`(area, scope)` index** services the auditing query the inventory called out ("which roles have area=X scope=Y").

### 3.2 Model
A thin `App\Models\RoleScope` (belongsTo `Spatie\Permission\Models\Role`), enum casts on `area`/`scope`. A convenience accessor on the role side (`Role::scopes()` hasMany) is optional and additive.

---

## 4. The seam: scope table → enforcement (described, not built)

Phase 1 lands the **data**; a later phase builds the **resolver + filters**. The contract between them:

**What `role_scopes` provides:** for any Spatie role, the tri-state per area (`all|assigned|none`), defaulting to `none` when absent.

**What the enforcement layer will consume (later phase):**
1. **Effective scope per user per area** = the **most-permissive** scope across all the user's roles (`all > assigned > none`); `super_admin` bypasses to `all` (§5). Default `none`.
2. **Area → ownership-expression map** — the single place that encodes the assignment-model investigation's per-area queries:
   - `projects` → `whereHas('members', fn($q) => $q->where('user_id', $userId))`
   - `tasks` → `where('assigned_to', $userId)` (assignee only — *not* `created_by`)
   - `leads` → `where('assigned_to', $userId)`
   - `support` → `where('assigned_to', $userId)`; **if** the user holds `support.view_unassigned`, widen to `->orWhereNull('assigned_to')` (the compose rule)
3. **Application points (both, per the assignment investigation's per-item finding):**
   - **list queries** — apply the constraint as a global/au­to scope on the area's index;
   - **per-item access** — the same predicate gates `show`/actions so a scoped user can't reach an off-scope item by direct ID/URL.
   - `all` → no constraint; `none` → deny (403 / empty).

The seam is deliberately narrow: **the scope table answers "what tri-state does this role have for this area"; the enforcement layer owns "how that becomes a query."** Phase 1 wires none of this into request handling.

---

## 5. Migration-safety mapping (day-1, access-identical)

Seeded by a `RolesAndPermissionsSeeder` (guard `web`). Goal: **on day one every staff user can do exactly what they can today** — with exactly one deliberate exception.

### 5.1 Roles
- Create Spatie roles **`super_admin`** and **`staff`** (guard `web`).
- **`referrer` is untouched** — it stays a `users.role` enum value enforced by `EnsureRole role:referrer`; it is **not** a matrix-governed Spatie role and not in the scope table.
- **Backfill**: assign each existing user the Spatie role matching their enum (`role='super_admin'` → `super_admin`, `role='staff'` → `staff`). `referrer` users get no Spatie role.

### 5.2 Permissions
Seed the full Part-4 catalogue as Spatie permissions (guard `web`): every `{area}.access`, `{area}.manage`, the high-stakes actions (`invoices.void`, `people.delete`, `customers.delete`, `gdpr.export`, `gdpr.erase`, `deployment.run`, `expenses.approve`, `forms.custom_css`, `impersonate`, `staff.manage`, `billing_entities.manage`, `products.manage`, `commission.config`, `commission.approve`, `referrers.manage`, `wordpress.bulk_update`, `maavelus.statements`, `customers.referral.manage`, `customers.exemption`, the `settings.*` sub-permissions), plus `forms.view_submissions` and `support.view_unassigned`.

### 5.3 `staff` day-1 grant (= Part-6 list)
- **All** `{area}.access` + `{area}.manage` that staff reach today.
- The staff-level actions staff hold today (invoice send/mark-paid, expense mark-paid, etc., where modelled as permissions), `forms.view_submissions`, `analytics.manage`, `settings.access` (the overview shell).
- **`support.view_unassigned` = granted** (staff see unassigned tickets today).
- **Scope rows for `staff`:** `projects`, `tasks`, `leads`, `support` all = **`all`** (staff currently sees everything).
- **Withheld from staff** (the SA-only set): every high-stakes/destructive permission and every `settings.*` editor listed in inventory Part 6.

### 5.4 The one deliberate exception — webhook-retry
Today staff can hit the webhook-retry route (it sits in the outer staff group). Under the new model it folds into **`settings.integrations` (super-admin-only)**. The seeder therefore **does not grant `staff` `settings.integrations`**, so **staff loses webhook-retry**. This is the single intentional deviation from access-identical and must be called out in the seeder comments + the cutover changelog.

### 5.5 `super_admin`
Not in the matrix UI; bypasses (§6). In the seeder, **grant `super_admin` every permission** anyway (belt-and-braces so it passes Spatie permission middleware once enforcement swaps), and **no `role_scopes` rows are required** (the bypass forces `all`); optionally seed `all`×4 for the four areas for readability.

---

## 6. `super_admin` bypass mechanism

There is **no `Gate::before` today** — this introduces the first one, and it must grant **only** super_admin.

**Mechanism (two complementary parts, landed in the enforcement phase — see §7):**
1. **Gate::before** (in `AppServiceProvider::boot`) for all gate/policy checks:
   ```php
   Gate::before(function ($user, $ability) {
       return $user->isSuperAdmin() ? true : null;   // null, NOT false
   });
   ```
   - Returning **`true`** short-circuits every `Gate::allows`/policy for super_admin.
   - Returning **`null`** (never `false`) for everyone else lets normal policy resolution proceed — returning `false` would deny everyone.
   - During coexistence the condition reads the **enum** (`isSuperAdmin()`); it can later read the Spatie role. Either way it is behaviourally a **no-op for super_admin today** (every existing policy already returns true for super_admin), so adding it does not change current behaviour — but it is still deferred to the enforcement phase to keep phase 1 strictly data-only.
2. **Spatie permission middleware does *not* consult `Gate::before`.** When route gates swap to `permission:…` middleware, super_admin must also pass *there*. That is covered by §5.5 (super_admin holds every permission). So: **Gate::before covers policy/`@can` checks; the all-permissions grant covers route middleware.** Together they guarantee super_admin is never lockable, and the grant is scoped to **only** the super_admin role.

---

## 7. Phased, non-breaking cutover plan

### Phase 1 — data model + integration (this design; non-breaking)
1. `composer require spatie/laravel-permission` (resolve + **pin**); rebuild the deploy bundle's `vendor/` (§1.5).
2. Publish `config/permission.php` + Spatie migrations; add the `role_scopes` migration (timestamped after Spatie's).
3. Add `HasRoles` + `protected $guard_name='web'` to `User` (additive).
4. `RolesAndPermissionsSeeder`: roles, permissions, `staff` day-1 grant, `super_admin` all-perms, `staff` scope rows (`all`×4), and the webhook-retry carve-out (§5).
5. Data-backfill step: map existing users' enum → Spatie role.
6. Update `SCHEMA.md` with the six new tables; run `permission:cache-reset` post-seed.
7. **Do not**: register Spatie middleware under `role`, add `Gate::before`, or change any route gate / policy / controller check.

**"Phase 1 done" =** the Spatie tables + `role_scopes` exist and are **seeded access-identically**, existing users are backfilled, the model has `HasRoles` — **and the app still enforces exclusively via the old `EnsureRole` + `isStaff()/isSuperAdmin()` path.** Nothing about who-can-do-what changes. The new data is dormant, ready to be read.

### Phase 2 — enforcement swap (later, out of scope here)
Add the `Gate::before` (§6); introduce a new `permission:` middleware alias and migrate the `role:` route gates to it (resolving the alias collision, retiring `EnsureRole`); rewrite policy bodies to check permissions; build the scope resolver + area→expression map and wire it into list queries **and** per-item access (§4); enforce the webhook-retry carve-out at the route. This is the deliberate, reviewable breaking change — done once the dormant data is verified.

### Phase 3 — decommission (later)
Once enforcement reads Spatie exclusively, retire the `users.role` enum reads (keep the column until the front-end `auth.user.role` share and any remaining consumers migrate), then drop/repurpose as a final cleanup.

---

## 8. Risks & notes
- **Alias collision (`role`)** — the single biggest coexistence hazard; phase 1 must not register Spatie middleware. Tracked for phase 2.
- **`Gate::before` + middleware split** — super_admin needs *both* the before-hook (policies) *and* the all-permissions grant (middleware); documenting it here prevents a half-bypass later.
- **Permission cache** — Spatie caches; seed/deploy must `permission:cache-reset` or stale denials appear.
- **`SCHEMA.md` discipline** — the implementation must register all six new tables there (project rule: SCHEMA.md is the DB source of truth).
- **Front-end share** — `HandleInertiaRequests` still shares the enum `role` in phase 1; the matrix UI's eventual reads of Spatie roles/permissions are a phase-2+ front-end concern.
- **Multi-role resolution** — effective scope = most-permissive across a user's roles (defined in §4) so adding a second role can only *widen*, never silently narrow.

---

> This document is the basis for the phase-1 implementation (Spatie install + migration + seeder), to be built in the next step.
