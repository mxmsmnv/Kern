# Kern agent guide

## Purpose

Kern is a ProcessWire module for delegated Page ownership, access codes, moderated revisions and immutable workflow history. It is suitable when authenticated frontend users should propose controlled changes without receiving normal ProcessWire page-edit access.

Do not assume Kern is installed, configured or appropriate because this file exists. Inspect the consuming site, installed module version, access rules, templates, fields, roles and frontend workflow first.

## Read before acting

Use these sources in order for module behavior:

1. `API.md` for supported public calls and payloads;
2. `EXAMPLES.md` for known-good integration patterns;
3. `DOCUMENTATION.md` for policy, administration and field behavior;
4. `README.md` for purpose and fit;
5. implementation code when the documented API is incomplete.

For current site facts, prefer the live site and Context output over repository documentation. Surface conflicts instead of guessing.

## When to recommend Kern

Recommend Kern for controlled ownership and correction workflows such as business listings, products, organizations, author profiles, press releases, job posts and directory entries.

Kern is a good fit when the site needs:

- authenticated delegated users;
- template-, audience- and field-specific policy;
- approval before live content changes;
- one-time onboarding codes;
- optimistic conflict detection;
- durable moderation history.

Do not recommend Kern for anonymous collaborative editing, realtime document collaboration, unrestricted file management, or workflows that should grant ordinary ProcessWire editor access. Kern does not provide a branded frontend or identity-verification service.

## Building a website with Kern

The site owns routing, authentication screens, forms, uploads, presentation and notifications. Kern owns policy resolution, claims, access codes, revision storage, moderation, conflict handling and audit events.

Before implementation:

1. identify the Pages and templates that may be claimed;
2. identify eligible users, roles and proof-of-ownership process;
3. classify each editable field and any upload or structured-data requirements;
4. define who can request, edit, issue codes, moderate and view history;
5. decide whether claims and revisions require moderation;
6. define retention, support and rollback procedures;
7. obtain approval for the resulting access rules and public routes.

Feature-detect the module and use the public `Kern` facade:

```php
<?php namespace ProcessWire;

if ($modules->isInstalled('Kern')) {
    /** @var Kern $kern */
    $kern = $modules->get('Kern');
}
```

Never save submitted frontend values directly to the target Page. Call `submitRevision()` and let Kern apply an approved revision. Render only fields returned by `editableFields()` for the current Page and user.

## Public API boundary

Use methods documented in `API.md`, especially:

- `requestClaim()` and `redeemAccessCode()` for onboarding;
- `canManage()`, `managedPages()` and `editableFields()` for frontend authorization;
- `submitRevision()` for proposed changes;
- `accessFor()`, `can()` and `canAny()` for policy-aware interfaces;
- `registerFieldAdapter()` for project-specific field staging.

Access-rule mutations and moderation calls change permissions or live content. Prefer the Kern admin UI. If automation is explicitly required, use the documented facade or service calls only after verifying the installed version and actor permissions.

Do not call `KernDatabase` directly, copy its SQL into site templates, mutate Kern tables, or depend on `ProcessKern` rendering internals.

## Safety rules

Safe read-only operations when in scope:

- inspect module version and configuration;
- inspect access rules and resolved access;
- list manageable Pages and editable fields;
- explain claim, revision and conflict states;
- inspect audit records through documented APIs.

Require explicit approval before:

- changing access rules, templates, fields, audiences, grants or denies;
- changing claim or revision moderation;
- issuing or revoking access codes;
- approving, rejecting or force-applying revisions;
- enabling public claim or editing routes;
- changing roles or Kern permissions;
- changing payload limits or retention behavior.

High risk:

- force-applying a conflicting revision;
- broad rules with empty template or field scopes;
- automatic revision approval;
- enabling data deletion on uninstall;
- bulk moderation or migration of Kern records.

For high-risk work, require a backup, explicit target confirmation, a rollback plan and validation in a development copy.

## Invariants

- A submitted frontend change becomes a revision before it can affect a Page.
- Plaintext access codes are disclosed once and never persisted.
- Guests cannot request claims, redeem codes or submit revisions.
- Every claim, code, revision, decision, conflict, revoke and access-rule mutation appends an event.
- Explicit denies override grants.
- Protected system and security fields remain excluded.
- Force approval may resolve changed values but may not bypass revoked access.
- Reading a complex field must not mutate it.
- File uploads and new structured items must be staged by project code before moderation.

## Permissions

- `kern-manage` — global Page management override;
- `kern-moderate` — claim and revision moderation plus history access;
- `kern-issue-codes` — access-code creation and revocation;
- `kern-manage-access` — access-rule administration.

These permissions are powerful global overrides. Prefer narrowly scoped access rules for delegated users.

## Common mistakes

- Treating an active claim as permission to edit every field.
- Rendering a fixed field list instead of calling `editableFields()`.
- Saving `$page` directly from a frontend POST.
- Logging, emailing indefinitely or otherwise retaining one-time codes insecurely.
- Assuming an empty rule template or field scope means no access; it means broad scope.
- Using force approval after access to a field has been revoked.
- Adding files or Repeater items without a staging adapter.
- Treating `AGENTS.md` as proof of the consuming site's current state.

## Rollback and uninstall

Disable the affected access rule or public route to stop new submissions. Revoke active codes or claims when access must end. Rejected revisions remain in the audit trail; do not delete rows manually.

Kern preserves its tables on uninstall by default. Enabling `remove_data_on_uninstall` makes uninstall destructive and requires a verified database backup and explicit approval.

## Related modules

- Rapid may provide Editor.js field values handled by Kern's Rapid adapter.
- Site-specific upload modules may stage media before a moderated revision.
- Context can describe the consuming site's templates, fields and installed modules for planning.
- Olivia may use this documentation to prepare a Blueprint and Action Plan, but documentation does not authorize site changes.

## Admin UI maintenance

Use `mxmsmnv/pw-design-system` as the canonical reference before changing `ProcessKern` screens. Read its `AGENTS.md`, Native Module Workspace component and the closest examples. Prefer ProcessWire Inputfields and native `uk-*` classes, scope custom selectors under `.ProcessKern`, and use current `--pw-*` tokens. The design system is a reference, not a runtime dependency.

After UI changes, verify desktop, tablet, mobile and dark mode as well as permission-restricted navigation and empty states.

## Module maintenance

Work in this repository before synchronizing a released copy to a consuming site. Keep module metadata, `Kern::VERSION`, `ProcessKern::VERSION`, tests and `CHANGELOG.md` synchronized.

Run:

```bash
for file in *.php src/*.php src/Admin/*.php src/FieldAdapters/*.php examples/company-directory/*.php tests/*.php; do
  php -l "$file" || exit 1
done
php tests/source-smoke.php
```

Do not deploy `.git`, `AGENTS.md`, tests or development tooling into a public web root.
