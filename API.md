# Kern public API

This file documents the supported integration surface for Kern 1.0.0 (ProcessWire module version `100`). All examples use the `ProcessWire` namespace.

## Obtain the module

```php
/** @var Kern $kern */
$kern = $modules->get('Kern');
```

Feature-detect optional installations with `$modules->isInstalled('Kern')` before calling the API.

## Authorization and scope

### `isPageClaimable(Page $page): bool`

Returns whether the Page is covered by the active Kern policy. System templates, trashed Pages and missing Pages are never claimable.

### `assertPageClaimable(Page $page): void`

Throws `WirePermissionException` when the Page is outside policy.

### `accessFor(Page $page, ?User $user = null, ?array $claim = null): array`

Resolves all matching rules for a Page and user. The current user is used when `$user` is omitted.

The returned array contains:

```php
[
    'configured' => true,
    'actions' => [
        'request_claim' => false,
        'edit' => false,
        'auto_approve' => false,
        'issue_codes' => false,
        'moderate_claims' => false,
        'moderate_revisions' => false,
        'view_history' => false,
    ],
    'fields' => [],          // Empty means every otherwise-safe field.
    'denied_fields' => [],
    'settings' => [
        'claim_moderation' => 'inherit',
        'revision_moderation' => 'inherit',
        'code_expires_days' => 0,
    ],
    'matched_rules' => [],
]
```

Global Kern permissions and superuser access are applied by this method. Explicit rule denies still determine delegated policy.

### `can(string $action, Page $page, ?User $user = null): bool`

Checks one action on one Page. Valid actions are `request_claim`, `edit`, `auto_approve`, `issue_codes`, `moderate_claims`, `moderate_revisions` and `view_history`.

### `canAny(string $action, ?User $user = null): bool`

Returns whether the user has the action through a global permission, direct rule or active claim on at least one Page.

### `editableFields(Page $page, ?User $user = null): array`

Returns an associative array of field name to `Field` for fields the user may propose on that Page. Protected fields and configured denies are removed. Returns an empty array when the user cannot edit.

Throws `WirePermissionException` when the Page is outside Kern policy.

### `canManage(Page $page, ?User $user = null): bool`

Returns whether an authenticated user can currently propose changes to the Page.

### `managedPages(?User $user = null): PageArray`

Returns claim-managed and directly rule-managed Pages that the authenticated user can edit.

## Claims and access codes

### `requestClaim(Page $page, ?User $user = null, string $note = ''): array`

Creates or reopens a claim for an authenticated user. Returns the stored claim row. If an existing claim is already `pending` or `active`, that row is returned unchanged.

The resulting status is `pending` when claim moderation is required and `active` otherwise.

Throws when the user is a guest, the Page is outside policy, or `request_claim` is not granted.

### `generateAccessCode(Page $page, array $options = [], ?User $actor = null): array`

Creates an access code for an actor with `issue_codes` access.

Options:

- `expires_days` — integer from 1 to 3650; defaults to resolved policy;
- `never_expires` — truthy value stores no expiry;
- `max_uses` — integer from 1 to 1000; default `1`;
- `label` — optional audit/admin label;
- `note` — optional metadata note.

Returns:

```php
[
    'id' => 42,
    'code' => 'ABCD-EFGH-IJKL', // Plaintext, returned once only.
    'hint' => '…IJKL',
    'expires' => 1780000000,   // Unix timestamp or 0.
    'max_uses' => 1,
]
```

Kern stores only an HMAC-SHA256 digest and the short hint. The caller is responsible for the secure one-time handoff.

### `redeemAccessCode(string $code, ?User $user = null): array`

Activates or updates a claim for an authenticated user and consumes one code use. Returns the active claim row.

Throws `WireException` for invalid, inactive, expired or exhausted codes, a missing target Page, or a Page no longer covered by policy.

## Revisions

### `submitRevision(Page $page, array $changes, string $note = '', ?User $user = null): array`

Validates allowed fields, stores before/after descriptors and returns the revision row. The target Page is not written during submission. When policy enables automatic approval, Kern immediately attempts the normal conflict-checked apply step.

`$changes` is keyed by ProcessWire field name. A value may be a raw adapter payload or a descriptor returned by `fieldsRegistry()->exportField()`.

Unknown or non-editable fields are ignored. Kern throws `WireException` when no changed editable fields remain, validation fails or the revision exceeds the configured payload limit.

Common revision statuses are `pending`, `approved`, `rejected`, `conflict` and `failed`.

## Field adapters

### `fieldsRegistry(): KernFieldRegistry`

Returns the adapter registry. Useful methods are:

- `exportField(Page $page, Field $field): array`;
- `validateField(Page $page, Field $field, mixed $payload): array`;
- `summarizeField(Field $field, mixed $payload): string`;
- `keys(): array`.

`exportField()` returns:

```php
[
    'adapter' => 'native',
    'fieldtype' => 'FieldtypeText',
    'value' => 'Current value',
]
```

### `registerFieldAdapter(KernFieldAdapter $adapter, bool $prepend = true): Kern`

Registers a project adapter. Prepending is the normal choice because the built-in native adapter accepts any remaining field.

An adapter implements:

```php
interface KernFieldAdapter {
    public function key(): string;
    public function supports(Field $field): bool;
    public function exportValue(Page $page, Field $field, $value);
    public function importValue(Page $page, Field $field, $payload);
    public function validatePayload(Page $page, Field $field, $payload): array;
    public function summarize(Field $field, $payload): string;
}
```

Reading or exporting a field must not mutate the Page or its child items.

## Access-rule administration

These calls change authorization policy and require a superuser or the `kern-manage-access` permission.

### `accessRules(bool $enabledOnly = false): array`

Returns normalized rules ordered by descending priority and then ascending ID.

### `saveAccessRule(array $rule, ?User $actor = null): array`

Creates or updates a rule and appends an audit event. Important keys are `id`, `name`, `enabled`, `priority`, `templates`, `fields`, `denied_fields`, `roles`, `users`, `audiences`, `grants`, `denies` and `settings`.

Empty `templates` means all non-system templates. Empty `fields` combined with an `edit` grant means every otherwise-safe field. Treat both as broad access.

### `deleteAccessRule(int $id, ?User $actor = null): void`

Deletes a rule and appends an audit event. Prefer disabling a rule when a reversible change is required.

## Moderation and operational records

Moderation is normally performed through **Setup → Kern**. For controlled integrations, `service()` returns `KernService`, whose supported operational methods are:

- `moderateClaim(int $id, string $decision, User $actor, string $note = ''): array` — `approve` or `reject` a pending claim; `revoke` an active claim;
- `approveRevision(int $id, User $actor, string $note = '', bool $force = false): array`;
- `rejectRevision(int $id, User $actor, string $note = ''): array`;
- `revokeCode(int $id, User $actor): void`;
- `claimFor(Page $page, User $user): ?array`;
- `claimById(int $id): ?array`;
- `revisionById(int $id): ?array`;
- `claims(array $filters = [], int $limit = 100): array`;
- `revisions(array $filters = [], int $limit = 100): array`;
- `codes(array $filters = [], int $limit = 100): array`;
- `history(array $filters = [], int $limit = 200): array`;
- `counts(): array`.

List filters accept only known record columns: `id`, `page_id`, `claim_id`, `revision_id`, `user_id`, `status` and `event_type`. Limits are clamped to 1–500.

Force approval resolves changed-value conflicts only. It cannot apply a field that is no longer allowed.

## Configuration keys

- `claimable_templates` — legacy fallback template IDs used only when no access rules exist;
- `excluded_fields` — global field-name denylist;
- `claim_moderation` — require moderation for direct claim requests;
- `revision_moderation` — require moderation for proposed changes;
- `code_expires_days` — default access-code lifetime;
- `max_field_payload_bytes` — per-field JSON limit;
- `max_revision_bytes` — complete revision JSON limit;
- `remove_data_on_uninstall` — delete Kern tables during uninstall when enabled.

Change these through ProcessWire module configuration unless a reviewed deployment process explicitly requires configuration automation.

## Hooks

Kern 1.0.0 does not define a public hook/event API. Database event rows are audit records, not ProcessWire hooks.

## Internal APIs

`KernDatabase`, raw table names, SQL, `ProcessKern` rendering methods and private adapter helpers are internal. Do not use them from site templates or other modules.
