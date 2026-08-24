# Kern

Kern adds delegated Page ownership, moderated frontend editing, one-time access codes and immutable change history to ProcessWire.

![Kern delegated editing and moderation workflow](assets/images/kern-workflow-doodle.jpg)

It is made for directories, marketplaces, company profiles, products, collections, press releases, job posts and other sites where an authenticated user should propose changes without receiving direct ProcessWire page-edit access.

**Author:** Maxim Semenov<br>
**Website:** [smnv.org](https://smnv.org)<br>
**Email:** [maxim@smnv.org](mailto:maxim@smnv.org)

If this project helps your work, consider supporting future development through [GitHub Sponsors](https://github.com/sponsors/mxmsmnv) or [smnv.org/sponsor](https://smnv.org/sponsor/).

## What Kern does

- Accepts ownership requests for configured ProcessWire Pages.
- Issues expiring, limited-use access codes without storing their plaintext value.
- Lets approved users propose changes only to fields allowed by policy.
- Stores proposed changes as revisions instead of writing directly to live Pages.
- Gives moderators a current-versus-proposed review workspace.
- Detects changes made after submission and blocks conflicting revisions.
- Records claims, codes, revisions, decisions and policy changes in an immutable event history.
- Supports native fields, multilingual values, Page references, existing files and images, Rapid, Combo, Table, Repeater and RepeaterMatrix values.

## Workflow

1. An administrator defines which Pages, audiences, fields and actions are allowed.
2. A user requests ownership or redeems a one-time access code while logged in.
3. The user submits allowed field values as a revision.
4. Kern leaves the live Page unchanged until approval.
5. A moderator reviews the difference and approves or rejects it.
6. Kern checks for conflicts, applies approved values and appends the decision to the audit history.

Rules can target templates, ProcessWire roles, individual users, all authenticated users or active claimants. Grants from matching rules are combined, while explicit action and field denies always win.

## Requirements

- ProcessWire 3.0.244 or newer
- PHP 8.3 or newer
- A logged-in ProcessWire user for claims, code redemption and revisions

Rapid and ProFields integrations are optional. Kern does not distribute any ProFields source code.

## Installation

1. Copy the `Kern` directory to `/site/modules/`.
2. In ProcessWire Admin, refresh the module list.
3. Install `Kern`; `ProcessKern` is installed automatically.
4. Open **Setup → Kern → Access rules**.
5. Define Page scope, audience, permitted fields and actions before exposing a frontend workflow.
6. Grant global Kern permissions only to trusted administrative roles.

Kern creates its own database tables. They are preserved on uninstall unless **Delete data on uninstall** was explicitly enabled beforehand.

## Basic integration

Kern deliberately does not impose frontend markup. Your site controller renders the account, claim and editing experience and calls the module API.

```php
<?php namespace ProcessWire;

if (!$modules->isInstalled('Kern') || $user->isGuest()) {
    throw new Wire404Exception();
}

/** @var Kern $kern */
$kern = $modules->get('Kern');
$pageToManage = $pages->get(1234);

if ($kern->canManage($pageToManage, $user)) {
    $editableFields = $kern->editableFields($pageToManage, $user);

    // Render only these fields and protect the POST handler with ProcessWire CSRF.
}
```

Submitting a revision never writes directly to the Page:

```php
$revision = $kern->submitRevision($pageToManage, [
    'title' => 'Corrected company name',
    'summary' => 'Updated company description',
], 'Submitted by the profile owner', $user);
```

See [EXAMPLES.md](EXAMPLES.md) for complete claim, code, dashboard and custom-adapter patterns.

## Security boundaries

- Guests cannot request claims, redeem codes or submit revisions.
- Access-code plaintext is returned once and is never stored by Kern.
- Page identity, hierarchy, status, roles, permissions and password fields are always protected.
- File additions or removals and new Repeater items require a project-specific staging adapter.
- A force approval can resolve a value conflict but cannot bypass revoked field access.
- Public forms remain the site's responsibility and must use CSRF protection, validation and rate limiting appropriate to the project.

## Documentation

- [DOCUMENTATION.md](DOCUMENTATION.md) — setup, policy, administration and field behavior
- [API.md](API.md) — public methods, inputs, outputs and errors
- [EXAMPLES.md](EXAMPLES.md) — known-good API patterns and a complete company-directory workflow
- [AGENTS.md](AGENTS.md) — guidance and safety boundaries for AI agents
- [CHANGELOG.md](CHANGELOG.md) — public release notes

## License

MIT
