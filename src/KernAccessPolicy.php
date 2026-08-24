<?php namespace ProcessWire;

/**
 * Resolves template, field and action access for roles, users and active claimants.
 */
final class KernAccessPolicy extends Wire {

	public const ACTIONS = [
		'request_claim',
		'edit',
		'auto_approve',
		'issue_codes',
		'moderate_claims',
		'moderate_revisions',
		'view_history',
	];

	private const AUDIENCES = ['authenticated', 'claimants'];
	private const MODERATION = ['inherit', 'required', 'automatic'];

	private Kern $module;
	private KernDatabase $db;
	private ?array $rulesCache = null;

	public function __construct(Kern $module, KernDatabase $db) {
		parent::__construct();
		$this->module = $module;
		$this->db = $db;
	}

	public function rules(bool $enabledOnly = false): array {
		if ($this->rulesCache === null) {
			$rows = $this->db->fetchAll(
				'SELECT * FROM `' . KernDatabase::ACCESS_RULES . '` ORDER BY `priority` DESC, `id` ASC'
			);
			$this->rulesCache = array_map(fn(array $row): array => $this->hydrate($row), $rows);
		}
		if (!$enabledOnly) return $this->rulesCache;
		return array_values(array_filter($this->rulesCache, fn(array $rule): bool => (bool)$rule['enabled']));
	}

	public function rule(int $id): ?array {
		foreach ($this->rules() as $rule) {
			if ((int)$rule['id'] === $id) return $rule;
		}
		return null;
	}

	public function save(array $input, User $actor): array {
		$this->assertAdministrator($actor);
		$rule = $this->normalize($input);
		$id = max(0, (int)($input['id'] ?? 0));
		$existing = $id ? $this->rule($id) : null;
		if ($id && !$existing) throw new WireException('Access rule not found.');

		$now = time();
		$row = [
			'name' => $rule['name'],
			'enabled' => $rule['enabled'] ? 1 : 0,
			'priority' => $rule['priority'],
			'rule_json' => $this->json($this->payload($rule)),
			'modified' => $now,
			'modified_by' => (int)$actor->id,
		];
		if ($existing) {
			$this->db->update(KernDatabase::ACCESS_RULES, $row, 'id = ?', [$id]);
			$event = 'access_rule.updated';
		} else {
			$row['created'] = $now;
			$row['created_by'] = (int)$actor->id;
			$id = $this->db->insert(KernDatabase::ACCESS_RULES, $row);
			$event = 'access_rule.created';
		}
		$this->rulesCache = null;
		$saved = $this->rule($id);
		$this->audit($actor, $event, $saved ?: $rule);
		return $saved ?: $rule;
	}

	public function deleteRule(int $id, User $actor): void {
		$this->assertAdministrator($actor);
		$rule = $this->rule($id);
		if (!$rule) throw new WireException('Access rule not found.');
		$this->db->delete(KernDatabase::ACCESS_RULES, 'id = ?', [$id]);
		$this->rulesCache = null;
		$this->audit($actor, 'access_rule.deleted', $rule);
	}

	public function pageIsConfigured(Page $page): bool {
		if (!$this->validPage($page)) return false;
		if (!$this->rules()) return $this->module->legacyPageIsClaimable($page);
		$rules = $this->rules(true);
		foreach ($rules as $rule) {
			if ($this->matchesTemplate($rule, $page)) return true;
		}
		return false;
	}

	public function resolve(Page $page, User $user, ?array $claim = null): array {
		$result = [
			'configured' => $this->pageIsConfigured($page),
			'actions' => array_fill_keys(self::ACTIONS, false),
			'fields' => [],
			'denied_fields' => [],
			'settings' => [
				'claim_moderation' => 'inherit',
				'revision_moderation' => 'inherit',
				'code_expires_days' => 0,
			],
			'matched_rules' => [],
		];
		if (!$result['configured'] || !$user->id || $user->isGuest()) return $result;

		if (!$this->rules()) return $this->legacyResolve($page, $user, $claim, $result);
		$rules = $this->rules(true);

		$grants = [];
		$denies = [];
		$allowedFields = [];
		$deniedFields = [];
		$allFields = false;
		foreach ($rules as $rule) {
			if (!$this->matchesTemplate($rule, $page) || !$this->matchesAudience($rule, $user, $claim)) continue;
			$result['matched_rules'][] = (int)$rule['id'];
			foreach ($rule['grants'] as $action) $grants[$action] = true;
			foreach ($rule['denies'] as $action) $denies[$action] = true;
			foreach ($rule['denied_fields'] as $name) $deniedFields[$name] = true;
			if (in_array('edit', $rule['grants'], true)) {
				if (!$rule['fields']) {
					$allFields = true;
				} else {
					foreach ($rule['fields'] as $name) $allowedFields[$name] = true;
				}
			}
			foreach (['claim_moderation', 'revision_moderation'] as $setting) {
				if ($result['settings'][$setting] === 'inherit' && $rule['settings'][$setting] !== 'inherit') {
					$result['settings'][$setting] = $rule['settings'][$setting];
				}
			}
			if (!$result['settings']['code_expires_days'] && $rule['settings']['code_expires_days']) {
				$result['settings']['code_expires_days'] = $rule['settings']['code_expires_days'];
			}
		}

		foreach (self::ACTIONS as $action) {
			$result['actions'][$action] = isset($grants[$action]) && !isset($denies[$action]);
		}
		$result['fields'] = $allFields ? [] : array_keys($allowedFields);
		$result['denied_fields'] = array_keys($deniedFields);
		return $result;
	}

	public function editableFieldNames(Page $page, User $user, ?array $claim = null): array {
		$access = $this->resolve($page, $user, $claim);
		if (!$access['actions']['edit']) return [];
		$allowed = array_fill_keys($access['fields'], true);
		$denied = array_fill_keys($access['denied_fields'], true);
		$all = !$access['fields'];
		$out = [];
		foreach ($page->template->fields as $field) {
			if (($all || isset($allowed[$field->name])) && !isset($denied[$field->name])) $out[] = $field->name;
		}
		return $out;
	}

	public function directTemplateIds(User $user): array {
		if (!$user->id || $user->isGuest()) return [];
		$ids = [];
		foreach ($this->rules(true) as $rule) {
			if (!in_array('edit', $rule['grants'], true) || in_array('edit', $rule['denies'], true)) continue;
			if (!$this->matchesAudience($rule, $user, null, false)) continue;
			if (!$rule['templates']) {
				foreach ($this->wire('templates') as $template) {
					if (!($template->flags & Template::flagSystem)) $ids[(int)$template->id] = true;
				}
			} else {
				foreach ($rule['templates'] as $id) $ids[$id] = true;
			}
		}
		return array_map('intval', array_keys($ids));
	}

	public function canAny(string $action, User $user): bool {
		if (!in_array($action, self::ACTIONS, true) || !$user->id || $user->isGuest()) return false;
		foreach ($this->rules(true) as $rule) {
			if (!$this->matchesAudience($rule, $user, null, false)) continue;
			if (in_array($action, $rule['grants'], true) && !in_array($action, $rule['denies'], true)) return true;
		}
		$claims = $this->db->fetchAll(
			'SELECT * FROM `' . KernDatabase::CLAIMS . '` WHERE `user_id` = ? AND `status` = "active"',
			[(int)$user->id]
		);
		foreach ($claims as $claim) {
			$page = $this->wire('pages')->get((int)$claim['page_id']);
			if (!$page->id) continue;
			$access = $this->resolve($page, $user, $claim);
			if (!empty($access['actions'][$action])) return true;
		}
		return false;
	}

	private function legacyResolve(Page $page, User $user, ?array $claim, array $result): array {
		$activeClaim = $claim && $claim['status'] === 'active';
		$manager = $user->isSuperuser() || $user->hasPermission(Kern::PERM_MANAGE);
		$result['actions']['request_claim'] = true;
		$result['actions']['edit'] = $manager || $activeClaim;
		$result['actions']['auto_approve'] = !$this->module->revision_moderation;
		$result['actions']['issue_codes'] = $user->isSuperuser() || $user->hasPermission(Kern::PERM_ISSUE_CODES);
		$result['actions']['moderate_claims'] = $user->isSuperuser() || $user->hasPermission(Kern::PERM_MODERATE);
		$result['actions']['moderate_revisions'] = $result['actions']['moderate_claims'];
		$result['actions']['view_history'] = $result['actions']['moderate_claims'];
		$result['denied_fields'] = array_values(array_map('strval', (array)$this->module->excluded_fields));
		return $result;
	}

	private function matchesTemplate(array $rule, Page $page): bool {
		return !$rule['templates'] || in_array((int)$page->template->id, $rule['templates'], true);
	}

	private function matchesAudience(array $rule, User $user, ?array $claim, bool $includeClaimants = true): bool {
		if (in_array((int)$user->id, $rule['users'], true)) return true;
		foreach ($user->roles as $role) {
			if (in_array((int)$role->id, $rule['roles'], true)) return true;
		}
		if (in_array('authenticated', $rule['audiences'], true)) return true;
		return $includeClaimants
			&& in_array('claimants', $rule['audiences'], true)
			&& $claim
			&& $claim['status'] === 'active';
	}

	private function normalize(array $input): array {
		$name = trim((string)($input['name'] ?? ''));
		if ($name === '') throw new WireException('Access rule name is required.');
		$settings = (array)($input['settings'] ?? []);
		$claimModeration = (string)($settings['claim_moderation'] ?? 'inherit');
		$revisionModeration = (string)($settings['revision_moderation'] ?? 'inherit');
		return [
			'id' => max(0, (int)($input['id'] ?? 0)),
			'name' => mb_substr($this->wire('sanitizer')->text($name), 0, 255),
			'enabled' => !empty($input['enabled']),
			'priority' => max(-32768, min(32767, (int)($input['priority'] ?? 0))),
			'templates' => $this->ids($input['templates'] ?? []),
			'fields' => $this->fieldNames($input['fields'] ?? []),
			'denied_fields' => $this->fieldNames($input['denied_fields'] ?? []),
			'roles' => $this->ids($input['roles'] ?? []),
			'users' => $this->ids($input['users'] ?? []),
			'audiences' => $this->allowedStrings($input['audiences'] ?? [], self::AUDIENCES),
			'grants' => $this->allowedStrings($input['grants'] ?? [], self::ACTIONS),
			'denies' => $this->allowedStrings($input['denies'] ?? [], self::ACTIONS),
			'settings' => [
				'claim_moderation' => in_array($claimModeration, self::MODERATION, true) ? $claimModeration : 'inherit',
				'revision_moderation' => in_array($revisionModeration, self::MODERATION, true) ? $revisionModeration : 'inherit',
				'code_expires_days' => max(0, min(3650, (int)($settings['code_expires_days'] ?? 0))),
			],
		];
	}

	private function hydrate(array $row): array {
		$data = json_decode((string)$row['rule_json'], true);
		if (!is_array($data)) $data = [];
		return $this->normalize(array_merge($data, [
			'id' => (int)$row['id'],
			'name' => (string)$row['name'],
			'enabled' => (bool)$row['enabled'],
			'priority' => (int)$row['priority'],
		])) + [
			'created' => (int)$row['created'],
			'created_by' => (int)$row['created_by'],
			'modified' => (int)$row['modified'],
			'modified_by' => (int)$row['modified_by'],
		];
	}

	private function payload(array $rule): array {
		return array_intersect_key($rule, array_flip([
			'templates', 'fields', 'denied_fields', 'roles', 'users', 'audiences', 'grants', 'denies', 'settings',
		]));
	}

	private function ids($values): array {
		$out = [];
		foreach ((array)$values as $value) {
			$id = (int)$value;
			if ($id > 0) $out[$id] = true;
		}
		return array_keys($out);
	}

	private function fieldNames($values): array {
		$out = [];
		foreach ((array)$values as $value) {
			$name = $this->wire('sanitizer')->fieldName((string)$value);
			if ($name !== '') $out[$name] = true;
		}
		return array_keys($out);
	}

	private function allowedStrings($values, array $allowed): array {
		return array_values(array_unique(array_intersect(array_map('strval', (array)$values), $allowed)));
	}

	private function validPage(Page $page): bool {
		return $page->id
			&& !($page instanceof NullPage)
			&& $page->template
			&& !$page->isTrash()
			&& !($page->template->flags & Template::flagSystem);
	}

	private function assertAdministrator(User $actor): void {
		if (!$actor->id || $actor->isGuest() || (!$actor->isSuperuser() && !$actor->hasPermission(Kern::PERM_ACCESS))) {
			throw new WirePermissionException('Permission to manage Kern access rules is required.');
		}
	}

	private function audit(User $actor, string $event, array $rule): void {
		$this->db->insert(KernDatabase::EVENTS, [
			'page_id' => 0,
			'claim_id' => 0,
			'revision_id' => 0,
			'actor_id' => (int)$actor->id,
			'event_type' => $event,
			'message' => mb_substr((string)$rule['name'], 0, 255),
			'meta_json' => $this->json([
				'rule_id' => (int)($rule['id'] ?? 0),
				'templates' => $rule['templates'] ?? [],
				'roles' => $rule['roles'] ?? [],
				'users' => $rule['users'] ?? [],
				'grants' => $rule['grants'] ?? [],
				'denies' => $rule['denies'] ?? [],
			]),
			'created' => time(),
		]);
	}

	private function json($value): string {
		return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}
}
