# Kern documentation

## Security model

Kern never grants frontend users ProcessWire page-edit permission and
never saves their submitted values directly. A claimed owner may submit only
fields permitted by module configuration. The normalized before/after values
are stored as a revision, reviewed in the ProcessWire admin, conflict-checked,
and applied only during approval.

Quick-access codes contain 12 characters from an ambiguity-safe alphabet. The
database stores only an HMAC-SHA256 digest and a four-character hint. Plaintext
is returned once when the code is created. Codes have an expiry, use limit, and
revocation state.

Every state transition appends an event to `kern_events`. Events are not
updated by the module. Claim and revision rows keep their current workflow
state, while events provide the audit trail.

## Installation

1. Copy the release files to `site/modules/Kern/`.
2. Refresh ProcessWire modules.
3. Install `Kern`; its `ProcessKern` admin dependency is installed
   automatically.
4. Open **Setup → Kern → Access rules** and define template/audience rules.
5. Grant global Kern permissions only to trusted administrative roles.

The admin dashboard is created below **Setup → Kern**.
The Access Rule editor uses ProcessWire `InputfieldAsmSelect` controls for
templates, fields, roles/groups, users, audiences, grants, and denies.

## Admin design system

Kern follows the Native Module Workspace from
[`mxmsmnv/pw-design-system`](https://github.com/mxmsmnv/pw-design-system).
That repository is a design and markup reference rather than a runtime
dependency. Kern therefore uses the UIkit and Inputfield assets already
provided by ProcessWire AdminThemeUikit.

The dashboard follows the same operational hierarchy as Tickets and Ichiban:
a compact introduction and workflow-health state, the active moderation queue,
decision-oriented metrics, then bounded delegated-access and recent-activity
panels. Metrics cover pending and active ownerships, pending revisions,
conflicts, active codes, and enabled access rules. Direct actions reach claims,
revisions, codes, and policy configuration without duplicating their edit
forms. Primary
navigation uses the native `uk-subnav uk-subnav-pill` pattern shared with
Ichiban and Resend; list filters use quiet `uk-subnav-divider` navigation
with sentence-case labels, neutral count chips, and an explicit current-page
state. Cards, tables, labels, buttons, and responsive grids remain native. Kern-specific CSS
is scoped under `.ProcessKern` and uses current `--pw-*` theme tokens so light
and dark admin themes continue to work.

The revision index is a moderation queue rather than a raw data export. It
shows bounded status counts, human-readable changed-field labels, explicit
review actions, a useful filtered empty state, and dedicated revision cards at
tablet and mobile widths where the full comparison table would be difficult to
scan.

Revision detail screens keep Page and submitter context visible, separate
current and proposed values, resolve Page references to readable titles and
paths, and present Table payloads as bounded rows with named columns. URLs and
formatted text remain readable without exposing markup; complete raw payloads
stay behind an on-demand disclosure. The sticky decision panel repeats the
submitter note and immediate-publish impact beside the actions. It distinguishes
normal approval and rejection from conflict override; force apply is available
only while a revision is in the `conflict` state.

The Access codes workspace uses native ProcessWire Inputfields and Page
autocomplete for creation, placing that primary task before issued-code
history on desktop and mobile. It summarizes active capacity, supports bounded
status filters, presents code history as a desktop table or responsive cards,
and requires a second explicit action before revoking an active code. Page
search results stay within the viewport and wrap long titles and paths without
creating horizontal page overflow. ProcessWire's administrative Page tree is
excluded from these results. Before generation the form explains whether Page
choices come from enabled Access rules or legacy defaults and warns
administrators to prepare a secure handoff. The full plaintext code remains a
one-time reveal immediately after creation.

The History workspace presents the latest 500 authorized audit events as a
readable activity register. A compact audit state and summary strip keep recent
volume, affected Pages, actors, and retained-record scope visible without
pushing the register below oversized metric cards. Category filters isolate
claims, revisions, access codes, and access-rule changes. Bounded search covers
Page title, name and path, actor name and email, event name, message, metadata,
and event ID while preserving the active category.
Human-readable event labels and metadata are shown first; immutable technical
payloads remain available through an explicit disclosure. Tablet and mobile
views use dedicated event cards instead of compressing the desktop table.

The Claims workspace leads with the moderation queue state and a compact
ownership summary before the searchable register. Search covers Page,
requester, email, access source, status, path, and claim ID while preserving
the selected status filter. Pending decisions lead the unfiltered queue. Page
and requester context remain primary while the internal claim ID is secondary;
status-specific guidance distinguishes decisions from audit views.
Claim sources are described as access-code activations or direct requests
instead of internal values. Tablet and mobile views use dedicated claim cards
that keep Page, requester, status, source, date, and next action visible.

The Revisions workspace uses the same operational hierarchy: an attention
state and compact summary lead into a searchable register. Conflicts, pending
decisions, and failed applications appear before completed records. Search
covers Page, account, email, changed field, path, status, and revision ID.
Pending and conflicting proposals expose a Review action, failures expose an
inspection action, and completed records use a read-only View action.

The Quick-access codes workspace exposes active delegated access and remaining
redemption capacity before the creation and audit areas. The wider native
Inputfield form remains the first task on desktop and mobile and retains the
one-time secret disclosure warning. The issued-code register supports bounded
search by label, Page, creator, email, hint, path, status, or code ID. Active
codes lead the unfiltered list; exhausted, expired, and revoked records are
also available through the aggregate Closed filter.

The dashboard is an operational control center rather than a second copy of
each workspace. The Needs attention queue appears before aggregate metrics and
combines pending claims, revisions, and conflicts in chronological order with
direct Review actions. A compact delegated-access panel summarizes active
ownerships, usable codes, and policy coverage beside the latest six readable
events. The full audit record remains in History. Policy readiness makes active
Access rules or the legacy fallback state explicit before access is issued.
Indicators use a compact phone layout and actions stack into full-width
controls on narrow screens.

The Access rules workspace distinguishes an unconfigured explicit policy from
an active rule set and keeps deny precedence visible in every state. When no
rules exist it leads directly to first-rule setup and explains that module
defaults remain active. Configured policies expose enabled, protected,
disabled, and total rule counts in a compact summary. The searchable register
covers rule name, Page type, audience, field, action, settings, status,
priority, and rule ID while preserving status filters. Enabled rules sort first
and then by descending priority to mirror evaluation order. Scope, audiences,
grants, and denies use human-readable labels; tablet and mobile layouts use
dedicated cards. Deleting a rule requires a second explicit confirmation.

The Access rule editor starts with a readable summary of the currently selected
Page scope, audience, allowed and denied actions, enabled state, and evaluation
priority. A warning identifies the intentionally broad default where empty
template and allowed-field selections mean every non-system Page and safe
field. The native ProcessWire form then follows a numbered five-section order:
essentials, Page and field scope, audience, actions, and optional workflow
overrides. This preserves Inputfield validation and save behavior while making
the policy impact understandable before submission.

## Permissions

- `kern-manage`: manage claimable Pages without an individual claim;
- `kern-moderate`: approve/reject claims and revisions;
- `kern-issue-codes`: create and revoke quick-access codes.
- `kern-manage-access`: configure access rules.

These permissions are global administrative overrides. Access rules can grant
page-scoped actions to roles/groups and users without granting a global
permission. Superusers bypass action checks. Access codes still require a
logged-in account when redeemed.

## Configuration

The module configuration screen separates legacy scope defaults, moderation,
access-code defaults, payload safety limits, and destructive data-lifecycle
behavior. Access rules remain the primary policy layer; the screen links
directly to that workspace and indicates whether legacy template defaults are
currently active. Advanced limits and uninstall deletion stay collapsed until
an administrator explicitly opens them.

- **Claimable templates**: legacy fallback used only while no access rules
  exist. Empty means every non-system template.
- **Fields owners may never propose**: global denylist applied across all
  rules.
- **Moderate new ownership requests**: requests remain pending when enabled.
- **Moderate proposed Page changes**: revisions remain pending when enabled.
- **Default access-code lifetime**: used unless explicitly overridden.
- **Payload limits**: reject oversized field and complete revision JSON.
- **Delete data on uninstall**: disabled by default to preserve history.

System/security fields such as page name, status, parent, template, roles,
permissions, and passwords are always excluded.

## Access rules

Rules are stored in `kern_access_rules` and ordered by priority. Priority
chooses the first non-inherited rule setting; it does not weaken a deny.

Each rule contains:

- any number of ProcessWire templates; empty means every non-system template;
- allowed fields; empty means every field on the matched template;
- denied fields, which always override allowed fields;
- ProcessWire roles (groups), individual users, authenticated users, and/or
  users holding an active claim/access code;
- allowed and denied actions;
- claim moderation, revision moderation, and access-code lifetime overrides.

The native Access rule editor presents those choices in task order: rule
essentials, Page and field scope, audience, actions, then optional workflow
overrides. It explains deny precedence before editing and keeps inherited
workflow settings collapsed until they are needed.

Available actions are `request_claim`, `edit`, `auto_approve`, `issue_codes`,
`moderate_claims`, `moderate_revisions`, and `view_history`.

Audience selectors within one rule use OR logic. For example, use two rules
when every authenticated user may request access but only active claimants may
edit:

1. **Request access** — audience `authenticated`, grant `request_claim`.
2. **Claimant editor** — audience `claimants`, grant `edit`, select the fields
   owners may change.

Multiple matching rules are combined. Grants and allowed fields are unioned;
any matching deny removes that action or field. Global protected fields remain
blocked even for a permissive rule.

## API

```php
/** @var Kern $claims */
$claims = $modules->get('Kern');

$claim = $claims->requestClaim($page, $user, 'Proof is available by email.');

$issued = $claims->generateAccessCode($page, [
    'expires_days' => 14,
    'max_uses' => 1,
    'label' => 'Brand onboarding',
], $moderator);

$claim = $claims->redeemAccessCode($issued['code'], $user);

if ($claims->canManage($page, $user)) {
    $revision = $claims->submitRevision($page, [
        'title' => 'Correct company name',
        'body' => [
            'time' => time() * 1000,
            'blocks' => [
                ['type' => 'paragraph', 'data' => ['text' => 'New profile text']],
            ],
            'version' => '2.31.0',
        ],
    ], 'Owner correction', $user);
}

$access = $claims->accessFor($page, $user);
if ($access['actions']['edit']) {
    foreach ($claims->editableFields($page, $user) as $name => $field) {
        // Render a field-specific editor.
    }
}
```

Use `$claims->managedPages($user)` to build an owner dashboard. Use
`$claims->fieldsRegistry()->exportField($page, $field)` when building a generic
editing form so that complex values retain the adapter descriptor expected by
`submitRevision()`.

## Field adapters

The registry selects the first adapter whose `supports()` method accepts the
field. A project module can prepend a specialized adapter:

```php
$claims->registerFieldAdapter(new CompanyAssetsKernAdapter(), true);
```

Built-in support:

- native scalar, multilingual, Options and Page-reference fields;
- existing file/image references;
- Rapid Editor.js documents through the public `RapidValue::toJSON()` and
  `FieldtypeRapid::sanitizeValue()` lifecycle;
- ProFields Combo and Table values;
- existing Repeater and RepeaterMatrix items, recursively.

The generic adapter deliberately rejects file additions/removals/reordering,
Repeater item additions/removals, and RepeaterMatrix type changes. Those
operations need a project-specific staging adapter so uploaded files and new
items can be moderated without touching the live Page.

No ProFields source code is distributed with Kern.

## Conflict handling

Each proposed field stores the value observed at submission time. Approval
exports the current live value again. If it differs, the revision becomes
`conflict` and is not applied. A moderator may inspect the before/current/
proposed state and explicitly force a value conflict. Force approval never
bypasses a field or template access rule that was revoked after submission.

## Frontend integration

The module intentionally does not render a branded frontend. A site-specific
controller should:

1. require an authenticated ProcessWire user;
2. redeem a supplied code or request a claim;
3. list `$claims->managedPages($user)`;
4. render only `$claims->editableFields($page, $user)`;
5. submit normalized values to `submitRevision()`;
6. display revision status without saving the live Page.

All POST handlers should use ProcessWire CSRF tokens and standard input limits.
