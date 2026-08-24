<?php namespace ProcessWire;

/**
 * Universal ownership claims, moderated Page revisions, access codes and audit history.
 *
 * @version 100
 * @license MIT
 */

require_once __DIR__ . '/src/KernDatabase.php';
require_once __DIR__ . '/src/KernAccessPolicy.php';
require_once __DIR__ . '/src/FieldAdapters/KernFieldAdapter.php';
require_once __DIR__ . '/src/FieldAdapters/KernAbstractAdapter.php';
require_once __DIR__ . '/src/FieldAdapters/KernRapidAdapter.php';
require_once __DIR__ . '/src/FieldAdapters/KernComboAdapter.php';
require_once __DIR__ . '/src/FieldAdapters/KernTableAdapter.php';
require_once __DIR__ . '/src/FieldAdapters/KernRepeaterAdapter.php';
require_once __DIR__ . '/src/FieldAdapters/KernNativeAdapter.php';
require_once __DIR__ . '/src/KernFieldRegistry.php';
require_once __DIR__ . '/src/KernService.php';

class Kern extends WireData implements Module, ConfigurableModule {

	public const VERSION = 100;
	private const SCHEMA_VERSION = 2;
	public const PERM_MANAGE = 'kern-manage';
	public const PERM_MODERATE = 'kern-moderate';
	public const PERM_ISSUE_CODES = 'kern-issue-codes';
	public const PERM_ACCESS = 'kern-manage-access';

	protected static array $defaults = [
		'claimable_templates' => [],
		'excluded_fields' => [],
		'claim_moderation' => 1,
		'revision_moderation' => 1,
		'code_expires_days' => 30,
		'max_field_payload_bytes' => 1048576,
		'max_revision_bytes' => 4194304,
		'remove_data_on_uninstall' => 0,
		'schema_version' => 0,
	];

	private ?KernDatabase $claimsDatabase = null;
	private ?KernAccessPolicy $accessPolicy = null;
	private ?KernFieldRegistry $fieldRegistry = null;
	private ?KernService $claimsService = null;

	public static function getModuleInfo(): array {
		return [
			'title' => 'Kern',
			'version' => self::VERSION,
			'summary' => 'Universal ownership claims, access codes, moderated Page revisions and immutable audit history.',
			'author' => 'Maxim Semenov',
			'license' => 'MIT',
			'hreflicense' => 'LICENSE',
			'icon' => 'shield',
			'singular' => true,
			'autoload' => true,
			'requires' => ['ProcessWire>=3.0.244', 'PHP>=8.3'],
			'installs' => ['ProcessKern'],
			'permissions' => [
				self::PERM_MANAGE => 'Manage claimed Pages without an individual claim',
				self::PERM_MODERATE => 'Moderate ownership claims and proposed revisions',
				self::PERM_ISSUE_CODES => 'Create and revoke Kern access codes',
				self::PERM_ACCESS => 'Configure Kern templates, fields, roles, users and access rules',
			],
		];
	}

	public function __construct() {
		parent::__construct();
		foreach (self::$defaults as $name => $value) $this->set($name, $value);
	}

	public function init(): void {
		$this->ensureSchema();
		$this->service();
	}

	public function ready(): void {
		$this->migrateLegacyPermissions();
	}

	public function ___install(): void {
		$this->ensureSchema();
		$this->migrateLegacyConfiguration();
	}

	public function ___uninstall(): void {
		if ((bool)$this->remove_data_on_uninstall) $this->databaseLayer()->uninstall();
	}

	public function ___upgrade($fromVersion, $toVersion): void {
		$this->ensureSchema();
	}

	public function databaseLayer(): KernDatabase {
		if (!$this->claimsDatabase) {
			$this->claimsDatabase = $this->wire(new KernDatabase($this->wire()->database));
		}
		return $this->claimsDatabase;
	}

	public function fieldsRegistry(): KernFieldRegistry {
		if (!$this->fieldRegistry) {
			$this->fieldRegistry = $this->wire(new KernFieldRegistry());
		}
		return $this->fieldRegistry;
	}

	public function accessPolicy(): KernAccessPolicy {
		if (!$this->accessPolicy) {
			$this->accessPolicy = $this->wire(new KernAccessPolicy($this, $this->databaseLayer()));
		}
		return $this->accessPolicy;
	}

	public function service(): KernService {
		if (!$this->claimsService) {
			$this->claimsService = $this->wire(new KernService(
				$this,
				$this->databaseLayer(),
				$this->fieldsRegistry()
			));
		}
		return $this->claimsService;
	}

	public function registerFieldAdapter(KernFieldAdapter $adapter, bool $prepend = true): self {
		$this->fieldsRegistry()->register($adapter, $prepend);
		return $this;
	}

	public function isPageClaimable(Page $page): bool {
		return $this->accessPolicy()->pageIsConfigured($page);
	}

	public function legacyPageIsClaimable(Page $page): bool {
		if (!$page->id || $page instanceof NullPage || !$page->template) return false;
		if ($page->isTrash() || $page->template->flags & Template::flagSystem) return false;
		$templates = array_values(array_filter(array_map('intval', (array)$this->claimable_templates)));
		return !$templates || in_array((int)$page->template->id, $templates, true);
	}

	public function assertPageClaimable(Page $page): void {
		if (!$this->isPageClaimable($page)) {
			throw new WirePermissionException('This Page is not enabled for ownership claims.');
		}
	}

	/**
	 * @return array<string, Field>
	 */
	public function editableFields(Page $page, ?User $user = null): array {
		$this->assertPageClaimable($page);
		$user = $user ?: $this->wire()->user;
		$claim = $this->service()->claimFor($page, $user);
		$access = $this->accessFor($page, $user, $claim);
		if (!$access['actions']['edit']) return [];
		$allowed = array_fill_keys((array)$access['fields'], true);
		$all = !$allowed;
		$excluded = array_fill_keys(array_merge(
			array_map('strval', (array)$this->excluded_fields),
			(array)$access['denied_fields'],
			$this->alwaysExcludedFields()
		), true);
		$out = [];
		foreach ($page->template->fields as $field) {
			if ((!$all && !isset($allowed[$field->name])) || isset($excluded[$field->name])) continue;
			$type = $field->type ? $field->type->className() : '';
			if (in_array($type, ['FieldtypePassword', 'FieldtypePermissions', 'FieldtypeRoles'], true)) continue;
			$out[$field->name] = $field;
		}
		return $out;
	}

	public function accessFor(Page $page, ?User $user = null, ?array $claim = null): array {
		$user = $user ?: $this->wire()->user;
		if ($claim === null && $page->id && $user->id && !$user->isGuest()) {
			$claim = $this->service()->claimFor($page, $user);
		}
		$access = $this->accessPolicy()->resolve($page, $user, $claim);
		if ($user->isSuperuser() || $user->hasPermission(self::PERM_MANAGE)) {
			$access['actions']['edit'] = true;
			$access['fields'] = [];
			$access['denied_fields'] = array_values(array_map('strval', (array)$this->excluded_fields));
		}
		if ($user->isSuperuser() || $user->hasPermission(self::PERM_ISSUE_CODES)) {
			$access['actions']['issue_codes'] = true;
		}
		if ($user->isSuperuser() || $user->hasPermission(self::PERM_MODERATE)) {
			$access['actions']['moderate_claims'] = true;
			$access['actions']['moderate_revisions'] = true;
			$access['actions']['view_history'] = true;
		}
		return $access;
	}

	public function can(string $action, Page $page, ?User $user = null): bool {
		$access = $this->accessFor($page, $user);
		return !empty($access['actions'][$action]);
	}

	public function canAny(string $action, ?User $user = null): bool {
		$user = $user ?: $this->wire()->user;
		if ($user->isSuperuser()) return true;
		$globalPermission = [
			'edit' => self::PERM_MANAGE,
			'issue_codes' => self::PERM_ISSUE_CODES,
			'moderate_claims' => self::PERM_MODERATE,
			'moderate_revisions' => self::PERM_MODERATE,
			'view_history' => self::PERM_MODERATE,
		][$action] ?? '';
		if ($globalPermission && $user->hasPermission($globalPermission)) return true;
		return $this->accessPolicy()->canAny($action, $user);
	}

	public function claimModerationEnabled(?Page $page = null, ?User $user = null): bool {
		if ($page && $page->id) {
			$mode = $this->accessFor($page, $user)['settings']['claim_moderation'] ?? 'inherit';
			if ($mode !== 'inherit') return $mode === 'required';
		}
		return (bool)$this->claim_moderation;
	}

	public function revisionModerationEnabled(?Page $page = null, ?User $user = null): bool {
		if ($page && $page->id) {
			$access = $this->accessFor($page, $user);
			$mode = $access['settings']['revision_moderation'] ?? 'inherit';
			if (!empty($access['actions']['auto_approve'])) return false;
			if ($mode !== 'inherit') return $mode === 'required';
		}
		return (bool)$this->revision_moderation;
	}

	public function codeExpiresDays(?Page $page = null, ?User $user = null): int {
		if ($page && $page->id) {
			$days = (int)($this->accessFor($page, $user)['settings']['code_expires_days'] ?? 0);
			if ($days > 0) return max(1, min(3650, $days));
		}
		return max(1, min(3650, (int)$this->code_expires_days));
	}

	public function maxRevisionBytes(): int {
		return max(65536, (int)$this->max_revision_bytes);
	}

	public function requestClaim(Page $page, ?User $user = null, string $note = ''): array {
		return $this->service()->requestClaim($page, $user ?: $this->wire()->user, $note);
	}

	public function generateAccessCode(Page $page, array $options = [], ?User $actor = null): array {
		return $this->service()->generateAccessCode($page, $actor ?: $this->wire()->user, $options);
	}

	public function redeemAccessCode(string $code, ?User $user = null): array {
		return $this->service()->redeemAccessCode($code, $user ?: $this->wire()->user);
	}

	public function submitRevision(Page $page, array $changes, string $note = '', ?User $user = null): array {
		return $this->service()->submitRevision($page, $user ?: $this->wire()->user, $changes, $note);
	}

	public function canManage(Page $page, ?User $user = null): bool {
		return $this->service()->canManage($page, $user ?: $this->wire()->user);
	}

	public function managedPages(?User $user = null): PageArray {
		return $this->service()->managedPages($user ?: $this->wire()->user);
	}

	public function accessRules(bool $enabledOnly = false): array {
		return $this->accessPolicy()->rules($enabledOnly);
	}

	public function saveAccessRule(array $rule, ?User $actor = null): array {
		return $this->accessPolicy()->save($rule, $actor ?: $this->wire()->user);
	}

	public function deleteAccessRule(int $id, ?User $actor = null): void {
		$this->accessPolicy()->deleteRule($id, $actor ?: $this->wire()->user);
	}

	public function alwaysExcludedFields(): array {
		return [
			'id', 'name', 'status', 'sort', 'parent', 'template', 'created', 'modified',
			'created_users_id', 'modified_users_id', 'roles', 'permissions', 'pass',
		];
	}

	private function ensureSchema(): void {
		if ((int)$this->schema_version >= self::SCHEMA_VERSION) return;
		$this->databaseLayer()->install();
		$config = (array)$this->wire()->modules->getConfig($this);
		$config['schema_version'] = self::SCHEMA_VERSION;
		$this->wire()->modules->saveConfig($this, $config);
		$this->schema_version = self::SCHEMA_VERSION;
	}

	private function migrateLegacyConfiguration(): void {
		$legacy = (array)$this->wire()->modules->getConfig('PageClaims');
		$config = (array)$this->wire()->modules->getConfig($this);
		if ($legacy) {
			$config = array_replace($config, array_intersect_key($legacy, self::$defaults));
		}

		$permissions = [
			'page-claims-manage' => self::PERM_MANAGE,
			'page-claims-moderate' => self::PERM_MODERATE,
			'page-claims-issue-codes' => self::PERM_ISSUE_CODES,
		];
		$pendingRoles = [];
		foreach ($this->wire()->roles as $role) {
			foreach ($permissions as $oldName => $newName) {
				if (!$role->hasPermission($oldName)) continue;
				$pendingRoles[$newName][] = (int)$role->id;
			}
		}
		if ($pendingRoles) $config['legacy_permission_roles'] = $pendingRoles;
		if ($config) $this->wire()->modules->saveConfig($this, $config);
	}

	private function migrateLegacyPermissions(): void {
		$config = (array)$this->wire()->modules->getConfig($this);
		$pendingRoles = (array)($config['legacy_permission_roles'] ?? []);
		if (!$pendingRoles) return;

		foreach ($pendingRoles as $permissionName => $roleIds) {
			$permission = $this->wire()->permissions->get((string)$permissionName);
			if (!$permission->id) return;
			foreach ((array)$roleIds as $roleId) {
				$role = $this->wire()->roles->get((int)$roleId);
				if (!$role->id || $role->hasPermission($permission)) continue;
				$role->addPermission($permission);
				$role->save();
			}
		}

		unset($config['legacy_permission_roles']);
		$this->wire()->modules->saveConfig($this, $config);
	}

	public function getModuleConfigInputfields(InputfieldWrapper $inputfields) {
		$modules = $this->wire()->modules;
		$adminTheme = $this->wire()->adminTheme;
		if ($adminTheme) $adminTheme->addBodyClass('ProcessKern');
		$this->wire()->config->styles->add($this->wire()->config->urls->siteModules . 'Kern/assets/css/admin.css?v=' . self::VERSION);
		$rules = $this->accessRules(false);
		$ruleCount = count($rules);
		$adminUrl = $this->wire()->config->urls->admin;
		$accessRulesUrl = $adminUrl . 'setup/kern/access/';
		$dashboardUrl = $adminUrl . 'setup/kern/';
		$policyLabel = $ruleCount > 0 ? $this->_('Access rules') : $this->_('Module defaults');
		$policyStateClass = $ruleCount > 0 ? 'KernConfigState--ready' : 'KernConfigState--attention';
		$claimState = (bool)$this->claim_moderation ? $this->_('Review required') : $this->_('Granted immediately');
		$revisionState = (bool)$this->revision_moderation ? $this->_('Review required') : $this->_('Applied immediately');
		$codeLifetime = sprintf($this->_('%d days'), (int)$this->code_expires_days);
		$section = static function(InputfieldWrapper $field, string $class, bool $expanded = false): InputfieldWrapper {
			$field->collapsed = $expanded ? Inputfield::collapsedNo : Inputfield::collapsedYes;
			$field->addClass('KernConfigSection ' . $class . ($expanded ? ' KernConfigSection--expanded' : ''), 'wrapClass');
			return $field;
		};

		/** @var InputfieldMarkup $overview */
		$overview = $modules->get('InputfieldMarkup');
		$overview->name = 'kern_configuration_overview';
		$overview->label = $this->_('Kern settings');
		$overview->icon = 'shield';
		$overview->addClass('KernConfigOverview', 'wrapClass');
		$policyContext = $ruleCount > 0
			? sprintf($this->_('%d explicit access rules currently define Page scope, audiences and allowed actions.'), $ruleCount)
			: $this->_('No explicit access rules are configured. The default Page scope below is currently active.');
		$overview->markupText = '<section class="KernConfigHero" aria-labelledby="kern-config-title">'
			. '<div class="KernConfigHeroHead"><div><span class="KernConfigEyebrow">' . $this->_('Ownership and editing') . '</span>'
			. '<h2 id="kern-config-title">' . $this->_('Policy settings') . '</h2><p>' . $policyContext . '</p></div>'
			. '<span class="KernConfigState ' . $policyStateClass . '"><span aria-hidden="true"></span>' . $policyLabel . '</span></div>'
			. '<dl class="KernConfigSummary">'
			. '<div><dt>' . $this->_('Policy source') . '</dt><dd>' . $policyLabel . '</dd></div>'
			. '<div><dt>' . $this->_('Ownership claims') . '</dt><dd>' . $claimState . '</dd></div>'
			. '<div><dt>' . $this->_('Page revisions') . '</dt><dd>' . $revisionState . '</dd></div>'
			. '<div><dt>' . $this->_('Code lifetime') . '</dt><dd>' . $codeLifetime . '</dd></div>'
			. '</dl><div class="KernConfigActions">'
			. '<a class="uk-button uk-button-primary" href="' . $accessRulesUrl . '"><span uk-icon="icon: settings"></span> '
			. $this->_('Manage access rules') . '</a><a class="uk-button uk-button-default" href="' . $dashboardUrl
			. '"><span uk-icon="icon: grid"></span> ' . $this->_('Open dashboard') . '</a></div></section>';
		$inputfields->add($overview);

		/** @var InputfieldFieldset $scope */
		$scope = $modules->get('InputfieldFieldset');
		$scope->name = 'kern_default_scope';
		$scope->label = $this->_('Default Page scope');
		$scope->description = $this->_('This fallback scope is used only when no Access rules exist. Global protected fields remain denied in every mode.');
		$scope->icon = 'sitemap';
		$section($scope, 'KernConfigSection--scope', true);

		/** @var InputfieldAsmSelect $f */
		$f = $modules->get('InputfieldAsmSelect');
		$f->name = 'claimable_templates';
		$f->label = $this->_('Claimable templates');
		$f->description = $this->_('Select the Page types owners may claim when no Access rules exist. Leave empty to allow every non-system template.');
		$f->notes = $ruleCount > 0
			? $this->_('Currently inactive because Access rules are configured.')
			: $this->_('Currently active as the fallback template scope.');
		$f->icon = 'files-o';
		foreach ($this->wire()->templates as $template) {
			if ($template->flags & Template::flagSystem) continue;
			$f->addOption((int)$template->id, (string)$template->label ?: (string)$template->name);
		}
		$f->value = (array)$this->claimable_templates;
		$f->columnWidth = 50;
		$scope->add($f);

		/** @var InputfieldAsmSelect $f */
		$f = $modules->get('InputfieldAsmSelect');
		$f->name = 'excluded_fields';
		$f->label = $this->_('Fields owners may never propose');
		$f->description = $this->_('Global denylist applied after every Access rule and default Page scope setting.');
		$f->notes = $this->_('Passwords, roles, permissions, Page identity, template, parent and status remain protected automatically.');
		$f->icon = 'ban';
		foreach ($this->wire()->fields as $field) {
			if (in_array($field->name, ['pass', 'roles', 'permissions'], true)) continue;
			$f->addOption($field->name, (string)$field->label ?: (string)$field->name);
		}
		$f->value = (array)$this->excluded_fields;
		$f->columnWidth = 50;
		$scope->add($f);
		$inputfields->add($scope);

		/** @var InputfieldFieldset $workflow */
		$workflow = $modules->get('InputfieldFieldset');
		$workflow->name = 'kern_moderation_workflow';
		$workflow->label = $this->_('Moderation workflow');
		$workflow->description = $this->_('Choose which owner actions require an administrator decision before they affect live ownership or content.');
		$workflow->icon = 'check-square-o';
		$section($workflow, 'KernConfigSection--workflow', true);

		foreach ([
			'claim_moderation' => [
				$this->_('Moderate new ownership requests'),
				$this->_('Direct ownership requests remain pending until an administrator approves or rejects them.'),
			],
			'revision_moderation' => [
				$this->_('Moderate proposed Page changes'),
				$this->_('Owner proposals remain pending and never change the live Page until approval.'),
			],
		] as $name => [$label, $description]) {
			/** @var InputfieldCheckbox $f */
			$f = $modules->get('InputfieldCheckbox');
			$f->name = $name;
			$f->label = $label;
			$f->description = $description;
			$f->value = 1;
			$f->checked = (bool)$this->$name;
			$f->columnWidth = 50;
			$workflow->add($f);
		}
		$inputfields->add($workflow);

		/** @var InputfieldFieldset $codes */
		$codes = $modules->get('InputfieldFieldset');
		$codes->name = 'kern_access_codes';
		$codes->label = $this->_('Access codes');
		$codes->description = $this->_('Default expiry used when an Access rule or individual code does not provide a more specific lifetime.');
		$codes->icon = 'key';
		$section($codes, 'KernConfigSection--codes', true);
		/** @var InputfieldInteger $f */
		$f = $modules->get('InputfieldInteger');
		$f->name = 'code_expires_days';
		$f->label = $this->_('Default lifetime');
		$f->description = $this->_('How long a newly generated access code remains redeemable.');
		$f->notes = $this->_('Enter a value from 1 to 3650 days.');
		$f->icon = 'calendar';
		$f->min = 1;
		$f->max = 3650;
		$f->value = (int)$this->code_expires_days;
		$f->columnWidth = 50;
		$codes->add($f);
		$inputfields->add($codes);

		/** @var InputfieldFieldset $limits */
		$limits = $modules->get('InputfieldFieldset');
		$limits->name = 'kern_payload_limits';
		$limits->label = $this->_('Payload safety limits');
		$limits->description = $this->_('Reject unexpectedly large proposals before they consume excessive storage or processing time.');
		$limits->icon = 'tachometer';
		$section($limits, 'KernConfigSection--limits');
		foreach ([
			'max_field_payload_bytes' => [
				$this->_('Maximum value per field'), 65536, 16777216,
				$this->_('Bytes; default 1 MiB (1,048,576).'),
			],
			'max_revision_bytes' => [
				$this->_('Maximum complete revision'), 65536, 67108864,
				$this->_('Bytes; default 4 MiB (4,194,304).'),
			],
		] as $name => [$label, $min, $max, $notes]) {
			/** @var InputfieldInteger $f */
			$f = $modules->get('InputfieldInteger');
			$f->name = $name;
			$f->label = $label;
			$f->notes = $notes;
			$f->min = $min;
			$f->max = $max;
			$f->value = (int)$this->$name;
			$f->columnWidth = 50;
			$limits->add($f);
		}
		$inputfields->add($limits);

		/** @var InputfieldFieldset $lifecycle */
		$lifecycle = $modules->get('InputfieldFieldset');
		$lifecycle->name = 'kern_data_lifecycle';
		$lifecycle->label = $this->_('Data lifecycle');
		$lifecycle->description = $this->_('Destructive uninstall behavior. Normal module removal preserves Kern records by default.');
		$lifecycle->icon = 'trash';
		$section($lifecycle, 'KernConfigSection--danger');
		/** @var InputfieldCheckbox $f */
		$f = $modules->get('InputfieldCheckbox');
		$f->name = 'remove_data_on_uninstall';
		$f->label = $this->_('Permanently delete Kern data during uninstall');
		$f->description = $this->_('Enable only when claims, access codes, revisions, rules and immutable history should be erased with the module.');
		$f->notes = $this->_('Danger: this data cannot be recovered after uninstall.');
		$f->value = 1;
		$f->checked = (bool)$this->remove_data_on_uninstall;
		$f->wrapClass('InputfieldIsWarning');
		$lifecycle->add($f);
		$inputfields->add($lifecycle);

		return $inputfields;
	}
}
