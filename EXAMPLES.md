# Kern examples

These examples assume ProcessWire 3.0.244+, PHP 8.3+ and an installed, configured Kern 1.0.0 module.

For a complete practical implementation, see the [company directory example](examples/company-directory/README.md). It includes claim and code onboarding, a manageable-Page dashboard, a frontend edit form and revision submission.

## Bootstrap safely

```php
<?php namespace ProcessWire;

if (!$modules->isInstalled('Kern')) {
    throw new Wire404Exception();
}

/** @var Kern $kern */
$kern = $modules->get('Kern');
```

## Request ownership

Protect the POST route with ProcessWire CSRF and require authentication before this call.

```php
if ($user->isGuest()) {
    $session->redirect($pages->get('/login/')->url);
}

$pageToClaim = $pages->get((int)$input->post('page_id'));
if (!$pageToClaim->id || !$kern->can('request_claim', $pageToClaim, $user)) {
    throw new WirePermissionException('This Page cannot be claimed.');
}

$session->CSRF->validate();
$claim = $kern->requestClaim(
    $pageToClaim,
    $user,
    $sanitizer->textarea((string)$input->post('note'))
);
```

The returned claim may be `pending` or `active`, depending on policy.

## Issue and redeem a one-time code

Issue codes only from a trusted administrative workflow:

```php
$issued = $kern->generateAccessCode($companyPage, [
    'expires_days' => 14,
    'max_uses' => 1,
    'label' => 'Company owner onboarding',
], $user);

$oneTimeCode = $issued['code'];
```

Display or transmit `$oneTimeCode` once through an approved secure channel. Do not log it or store it in a Page field.

Redeem it for the logged-in user:

```php
$session->CSRF->validate();
$code = $sanitizer->text((string)$input->post('access_code'));
$claim = $kern->redeemAccessCode($code, $user);
```

## Build a manageable-Page dashboard

```php
$managed = $kern->managedPages($user);

foreach ($managed as $managedPage) {
    echo '<a href="/account/edit/?id=' . (int)$managedPage->id . '">'
        . $sanitizer->entities($managedPage->title)
        . '</a>';
}
```

Re-check `canManage()` on the destination route. A list rendered earlier is not authorization for a later request.

## Render only editable fields

```php
$managedPage = $pages->get((int)$input->get('id'));
if (!$kern->canManage($managedPage, $user)) {
    throw new Wire404Exception();
}

$editable = $kern->editableFields($managedPage, $user);

foreach ($editable as $name => $field) {
    $descriptor = $kern->fieldsRegistry()->exportField($managedPage, $field);
    // Map the field and descriptor to a project-owned form component.
}
```

The site owns form markup and input normalization. Do not render arbitrary fields based only on a template definition.

## Submit a scalar revision

```php
$session->CSRF->validate();

if (!$kern->canManage($managedPage, $user)) {
    throw new WirePermissionException('You cannot edit this Page.');
}

$changes = [
    'title' => $sanitizer->text((string)$input->post('title')),
    'summary' => $sanitizer->textarea((string)$input->post('summary')),
];

$revision = $kern->submitRevision(
    $managedPage,
    $changes,
    'Profile owner correction',
    $user
);
```

Do not call `$managedPage->save()` in this handler. Kern stores the proposal and applies it only through its moderation path.

## Submit Page references

The native adapter accepts Page IDs or descriptors containing `_page`:

```php
$revision = $kern->submitRevision($managedPage, [
    'categories' => [
        ['_page' => 1201],
        ['_page' => 1207],
    ],
], 'Updated categories', $user);
```

The target field's normal ProcessWire sanitization still applies.

## Submit a Rapid document

```php
$rapidDocument = [
    'time' => time() * 1000,
    'blocks' => [
        [
            'type' => 'paragraph',
            'data' => ['text' => 'Updated profile copy'],
        ],
    ],
    'version' => '2.31.0',
];

$revision = $kern->submitRevision(
    $managedPage,
    ['body' => $rapidDocument],
    'Updated profile body',
    $user
);
```

Kern uses Rapid's public serialization and sanitization lifecycle when the field is `FieldtypeRapid`.

## Inspect resolved policy

```php
$access = $kern->accessFor($managedPage, $user);

if ($access['actions']['edit']) {
    $allowedNames = $access['fields'];
    $deniedNames = $access['denied_fields'];
}
```

An empty `fields` array means all otherwise-safe fields, not no fields. Prefer `editableFields()` when building a form because it applies all exclusions and returns actual `Field` objects.

## Moderate from controlled code

The admin UI is preferred. If a reviewed integration needs moderation:

```php
if (!$kern->can('moderate_revisions', $managedPage, $user)) {
    throw new WirePermissionException('Moderation is not allowed.');
}

$revision = $kern->service()->approveRevision(
    $revisionId,
    $user,
    'Verified against the submitted evidence'
);
```

Passing `true` as the fourth argument force-applies changed-value conflicts. Require an explicit human decision before doing so; field access revoked after submission still cannot be bypassed.

## Register a project field adapter

Use an adapter when a custom field requires staging or a payload different from Kern's built-in adapters:

```php
<?php namespace ProcessWire;

final class CompanyAssetsKernAdapter extends KernAbstractAdapter {
    public function key(): string {
        return 'company-assets';
    }

    public function supports(Field $field): bool {
        return $field->type && $field->type->className() === 'FieldtypeCompanyAssets';
    }

    public function exportValue(Page $page, Field $field, $value) {
        return $this->normalize($value);
    }

    public function importValue(Page $page, Field $field, $payload) {
        // Resolve only previously staged assets here.
        return $field->type->sanitizeValue($page, $field, $payload);
    }
}

$kern->registerFieldAdapter(new CompanyAssetsKernAdapter(), true);
```

Register adapters during module initialization before Kern exports or imports the relevant field. Export and validation must not mutate live Page data.

## Disable a workflow safely

To stop new activity without deleting history:

1. remove or disable the public claim/edit route;
2. disable the affected access rule;
3. revoke active codes or ownerships through Kern's admin UI when required;
4. retain claims, revisions and events for audit.

Do not delete Kern database rows directly.
