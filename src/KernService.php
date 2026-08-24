<?php namespace ProcessWire;

final class KernService extends Wire {

	private Kern $module;
	private KernDatabase $db;
	private KernFieldRegistry $fields;

	public function __construct(
		Kern $module,
		KernDatabase $db,
		KernFieldRegistry $fields
	) {
		parent::__construct();
		$this->module = $module;
		$this->db = $db;
		$this->fields = $fields;
	}

	public function requestClaim(Page $page, User $user, string $note = ''): array {
		$this->assertUser($user);
		$this->module->assertPageClaimable($page);
		$this->assertAccess($page, $user, 'request_claim');

		$existing = $this->claimFor($page, $user);
		if ($existing && in_array($existing['status'], ['pending', 'active'], true)) return $existing;

		$status = $this->module->claimModerationEnabled($page, $user) ? 'pending' : 'active';
		$now = time();
		if ($existing) {
			$this->db->update(KernDatabase::CLAIMS, [
				'status' => $status,
				'source' => 'request',
				'request_note' => $this->cleanNote($note),
				'review_note' => '',
				'created' => $now,
				'created_by' => (int)$user->id,
				'reviewed' => $status === 'active' ? $now : 0,
				'reviewed_by' => 0,
				'revoked' => 0,
				'revoked_by' => 0,
			], 'id = ?', [(int)$existing['id']]);
			$id = (int)$existing['id'];
		} else {
			$id = $this->db->insert(KernDatabase::CLAIMS, [
				'page_id' => (int)$page->id,
				'user_id' => (int)$user->id,
				'status' => $status,
				'source' => 'request',
				'request_note' => $this->cleanNote($note),
				'created' => $now,
				'created_by' => (int)$user->id,
				'reviewed' => $status === 'active' ? $now : 0,
			]);
		}

		$this->event($page->id, $id, 0, $user->id, 'claim.requested', '', [
			'status' => $status,
			'source' => 'request',
		]);
		return $this->claimById($id) ?: [];
	}

	public function generateAccessCode(Page $page, User $actor, array $options = []): array {
		$this->module->assertPageClaimable($page);
		$this->assertAccess($page, $actor, 'issue_codes');

		$plain = $this->newCode();
		$normalized = $this->normalizeCode($plain);
		$days = max(1, min(3650, (int)($options['expires_days'] ?? $this->module->codeExpiresDays($page, $actor))));
		$maxUses = max(1, min(1000, (int)($options['max_uses'] ?? 1)));
		$expires = !empty($options['never_expires']) ? 0 : time() + ($days * 86400);
		$meta = array_intersect_key($options, array_flip(['label', 'note']));

		$id = $this->db->insert(KernDatabase::CODES, [
			'page_id' => (int)$page->id,
			'code_hash' => $this->hashCode($normalized),
			'code_hint' => '…' . substr($normalized, -4),
			'status' => 'active',
			'expires' => $expires,
			'max_uses' => $maxUses,
			'uses' => 0,
			'created' => time(),
			'created_by' => (int)$actor->id,
			'meta_json' => $this->json($meta),
		]);

		$this->event($page->id, 0, 0, $actor->id, 'code.created', '', [
			'code_id' => $id,
			'hint' => '…' . substr($normalized, -4),
			'expires' => $expires,
			'max_uses' => $maxUses,
		]);
		return [
			'id' => $id,
			'code' => $plain,
			'hint' => '…' . substr($normalized, -4),
			'expires' => $expires,
			'max_uses' => $maxUses,
		];
	}

	public function redeemAccessCode(string $code, User $user): array {
		$this->assertUser($user);
		$normalized = $this->normalizeCode($code);
		if (strlen($normalized) !== 12) throw new WireException('The access code is invalid.');

		$codeHash = $this->hashCode($normalized);
		$claimId = $this->db->transaction(function() use ($codeHash, $user): int {
			$row = $this->db->fetchOne(
				'SELECT * FROM `' . KernDatabase::CODES . '` WHERE `code_hash` = ? LIMIT 1 FOR UPDATE',
				[$codeHash]
			);
			if (!$row || !hash_equals((string)$row['code_hash'], $codeHash)) {
				throw new WireException('The access code is invalid.');
			}
			if ($row['status'] !== 'active') throw new WireException('The access code is not active.');
			if ((int)$row['expires'] > 0 && (int)$row['expires'] < time()) {
				$this->db->update(KernDatabase::CODES, ['status' => 'expired'], 'id = ?', [(int)$row['id']]);
				throw new WireException('The access code has expired.');
			}
			if ((int)$row['uses'] >= (int)$row['max_uses']) {
				$this->db->update(KernDatabase::CODES, ['status' => 'exhausted'], 'id = ?', [(int)$row['id']]);
				throw new WireException('The access code has already been used.');
			}

			$page = $this->wire('pages')->get((int)$row['page_id']);
			if (!$page->id) throw new WireException('The claimed Page no longer exists.');
			$this->module->assertPageClaimable($page);

			$claim = $this->claimFor($page, $user);
			$now = time();
			if ($claim) {
				$this->db->update(KernDatabase::CLAIMS, [
					'status' => 'active',
					'source' => 'code',
					'reviewed' => $now,
					'reviewed_by' => (int)$row['created_by'],
					'revoked' => 0,
					'revoked_by' => 0,
				], 'id = ?', [(int)$claim['id']]);
				$claimId = (int)$claim['id'];
			} else {
				$claimId = $this->db->insert(KernDatabase::CLAIMS, [
					'page_id' => (int)$page->id,
					'user_id' => (int)$user->id,
					'status' => 'active',
					'source' => 'code',
					'created' => $now,
					'created_by' => (int)$row['created_by'],
					'reviewed' => $now,
					'reviewed_by' => (int)$row['created_by'],
				]);
			}

			$uses = (int)$row['uses'] + 1;
			$this->db->update(KernDatabase::CODES, [
				'uses' => $uses,
				'status' => $uses >= (int)$row['max_uses'] ? 'exhausted' : 'active',
				'last_used' => $now,
				'last_used_by' => (int)$user->id,
			], 'id = ?', [(int)$row['id']]);
			$this->event($page->id, $claimId, 0, $user->id, 'code.redeemed', '', [
				'code_id' => (int)$row['id'],
				'hint' => (string)$row['code_hint'],
			]);
			$this->event($page->id, $claimId, 0, $user->id, 'claim.activated', '', ['source' => 'code']);
			return $claimId;
		});
		return $this->claimById($claimId) ?: [];
	}

	public function submitRevision(Page $page, User $user, array $changes, string $note = ''): array {
		$this->assertUser($user);
		$claim = $this->claimFor($page, $user);
		$this->module->assertPageClaimable($page);
		$this->assertAccess($page, $user, 'edit', $claim);

		$editable = $this->module->editableFields($page, $user);
		$prepared = [];
		$errors = [];

		foreach ($changes as $name => $after) {
			$name = $this->wire('sanitizer')->fieldName((string)$name);
			if ($name === '' || !isset($editable[$name])) continue;
			$field = $editable[$name];
			$fieldErrors = $this->fields->validateField($page, $field, $after);
			if ($fieldErrors) {
				foreach ($fieldErrors as $error) $errors[] = "$name: $error";
				continue;
			}

			$before = $this->fields->exportField($page, $field);
			$afterDescriptor = [
				'adapter' => $this->fields->adapterFor($field)->key(),
				'fieldtype' => $field->type->className(),
				'value' => is_array($after) && isset($after['adapter']) && array_key_exists('value', $after)
					? $after['value']
					: $after,
			];
			if ($this->same($before['value'], $afterDescriptor['value'])) continue;
			$prepared[$name] = [
				'label' => (string)($field->label ?: $field->name),
				'before' => $before,
				'after' => $afterDescriptor,
				'summary_before' => $this->fields->summarizeField($field, $before),
				'summary_after' => $this->fields->summarizeField($field, $afterDescriptor),
			];
		}

		if ($errors) throw new WireException(implode("\n", $errors));
		if (!$prepared) throw new WireException('No changed editable fields were submitted.');

		$json = $this->json($prepared);
		if (strlen($json) > $this->module->maxRevisionBytes()) {
			throw new WireException('The revision exceeds the configured size limit.');
		}

		$status = 'pending';
		$id = $this->db->insert(KernDatabase::REVISIONS, [
			'claim_id' => (int)($claim['id'] ?? 0),
			'page_id' => (int)$page->id,
			'user_id' => (int)$user->id,
			'status' => $status,
			'base_modified' => (int)$page->modified,
			'note' => $this->cleanNote($note),
			'changes_json' => $json,
			'created' => time(),
		]);

		$this->event($page->id, (int)($claim['id'] ?? 0), $id, $user->id, 'revision.submitted', '', [
			'fields' => array_keys($prepared),
			'status' => $status,
		]);
		if (!$this->module->revisionModerationEnabled($page, $user)) {
			$this->applyRevision($id, $user, 'Automatic approval', false, true);
		}
		return $this->revisionById($id) ?: [];
	}

	public function moderateClaim(int $id, string $decision, User $actor, string $note = ''): array {
		$claim = $this->claimById($id);
		if (!$claim) throw new WireException('Claim not found.');
		$page = $this->wire('pages')->get((int)$claim['page_id']);
		if (!$page->id) throw new WireException('The claimed Page no longer exists.');
		$this->assertAccess($page, $actor, 'moderate_claims');
		if (!in_array($decision, ['approve', 'reject', 'revoke'], true)) {
			throw new WireException('Invalid claim moderation decision.');
		}
		$allowed = match ((string)$claim['status']) {
			'pending' => ['approve', 'reject'],
			'active' => ['revoke'],
			default => [],
		};
		if (!in_array($decision, $allowed, true)) {
			throw new WireException('This ownership claim cannot receive that decision in its current state.');
		}
		$status = ['approve' => 'active', 'reject' => 'rejected', 'revoke' => 'revoked'][$decision];
		if ($decision === 'approve') {
			$this->module->assertPageClaimable($page);
		}
		$data = [
			'status' => $status,
			'review_note' => $this->cleanNote($note),
			'reviewed' => time(),
			'reviewed_by' => (int)$actor->id,
		];
		if ($decision === 'revoke') {
			$data['revoked'] = time();
			$data['revoked_by'] = (int)$actor->id;
		}
		$this->db->update(KernDatabase::CLAIMS, $data, 'id = ?', [$id]);
		$this->event((int)$claim['page_id'], $id, 0, $actor->id, "claim.$status", $note);
		return $this->claimById($id) ?: [];
	}

	public function approveRevision(
		int $id,
		User $actor,
		string $note = '',
		bool $force = false
	): array {
		$revision = $this->revisionById($id);
		if (!$revision) throw new WireException('Revision not found.');
		$page = $this->wire('pages')->get((int)$revision['page_id']);
		if (!$page->id) throw new WireException('The target Page no longer exists.');
		$this->assertAccess($page, $actor, 'moderate_revisions');
		return $this->applyRevision($id, $actor, $note, $force, false);
	}

	private function applyRevision(
		int $id,
		User $actor,
		string $note,
		bool $force,
		bool $automatic
	): array {
		$revision = $this->revisionById($id);
		if (!$revision) throw new WireException('Revision not found.');
		if (!in_array($revision['status'], ['pending', 'conflict'], true)) {
			throw new WireException('This revision cannot be approved in its current state.');
		}
		$page = $this->wire('pages')->get((int)$revision['page_id']);
		if (!$page->id) throw new WireException('The target Page no longer exists.');
		$this->module->assertPageClaimable($page);
		$revisionUser = $this->wire('users')->get((int)$revision['user_id']);
		$editable = $this->module->editableFields($page, $revisionUser);
		$changes = $this->decode((string)$revision['changes_json']);
		$conflicts = [];
		$forbidden = [];

		foreach ($changes as $name => $change) {
			$field = $editable[(string)$name] ?? null;
			if (!$field instanceof Field) {
				$conflicts[$name] = 'Field is no longer editable through Kern.';
				$forbidden[$name] = true;
				continue;
			}
			$current = $this->fields->exportField($page, $field);
			if (!$this->same($current['value'], $change['before']['value'] ?? null)) {
				$conflicts[$name] = 'Field changed after this revision was submitted.';
			}
		}

		if ($conflicts && (!$force || $forbidden)) {
			$this->db->update(KernDatabase::REVISIONS, [
				'status' => 'conflict',
				'review_note' => $this->cleanNote($note),
				'reviewed' => time(),
				'reviewed_by' => (int)$actor->id,
				'error_message' => $this->json($conflicts),
			], 'id = ?', [$id]);
			$this->event($page->id, (int)$revision['claim_id'], $id, $actor->id, 'revision.conflict', $note, [
				'fields' => array_keys($conflicts),
				'access_revoked' => array_keys($forbidden),
			]);
			return $this->revisionById($id) ?: [];
		}

		try {
			$this->db->transaction(function() use ($page, $changes, $revision, $actor, $note, $id, $force) {
				$page->of(false);
				foreach ($changes as $name => $change) {
					$field = $page->template->fields->get((string)$name);
					if (!$field) continue;
					$value = $this->fields->importField($page, $field, $change['after'] ?? null);
					$page->set($field->name, $value);
				}
				$page->save();
				$this->db->update(KernDatabase::REVISIONS, [
					'status' => 'approved',
					'review_note' => $this->cleanNote($note),
					'reviewed' => time(),
					'reviewed_by' => (int)$actor->id,
					'applied' => time(),
					'error_message' => '',
				], 'id = ?', [$id]);
				$this->event($page->id, (int)$revision['claim_id'], $id, $actor->id, 'revision.approved', $note, [
					'fields' => array_keys($changes),
					'force' => $force,
					'automatic' => $automatic,
				]);
			});
		} catch (\Throwable $e) {
			$this->db->update(KernDatabase::REVISIONS, [
				'status' => 'failed',
				'reviewed' => time(),
				'reviewed_by' => (int)$actor->id,
				'error_message' => mb_substr($e->getMessage(), 0, 4000),
			], 'id = ?', [$id]);
			$this->event($page->id, (int)$revision['claim_id'], $id, $actor->id, 'revision.failed', $e->getMessage());
			throw $e;
		}
		return $this->revisionById($id) ?: [];
	}

	public function rejectRevision(int $id, User $actor, string $note = ''): array {
		$revision = $this->revisionById($id);
		if (!$revision) throw new WireException('Revision not found.');
		$page = $this->wire('pages')->get((int)$revision['page_id']);
		if (!$page->id) throw new WireException('The target Page no longer exists.');
		$this->assertAccess($page, $actor, 'moderate_revisions');
		$this->db->update(KernDatabase::REVISIONS, [
			'status' => 'rejected',
			'review_note' => $this->cleanNote($note),
			'reviewed' => time(),
			'reviewed_by' => (int)$actor->id,
		], 'id = ?', [$id]);
		$this->event((int)$revision['page_id'], (int)$revision['claim_id'], $id, $actor->id, 'revision.rejected', $note);
		return $this->revisionById($id) ?: [];
	}

	public function revokeCode(int $id, User $actor): void {
		$code = $this->db->fetchOne(
			'SELECT * FROM `' . KernDatabase::CODES . '` WHERE `id` = ?',
			[$id]
		);
		if (!$code) throw new WireException('Access code not found.');
		$page = $this->wire('pages')->get((int)$code['page_id']);
		if (!$page->id) throw new WireException('The target Page no longer exists.');
		$this->assertAccess($page, $actor, 'issue_codes');
		$this->db->update(KernDatabase::CODES, ['status' => 'revoked'], 'id = ?', [$id]);
		$this->event((int)$code['page_id'], 0, 0, $actor->id, 'code.revoked', '', ['code_id' => $id]);
	}

	public function canManage(Page $page, User $user): bool {
		if (!$page->id || !$user->id || $user->isGuest()) return false;
		if (!$this->module->isPageClaimable($page)) return false;
		$claim = $this->claimFor($page, $user);
		$access = $this->module->accessFor($page, $user, $claim);
		return !empty($access['actions']['edit']);
	}

	public function managedPages(User $user): PageArray {
		$result = new PageArray();
		if (!$user->id || $user->isGuest()) return $result;
		$rows = $this->db->fetchAll(
			'SELECT `page_id` FROM `' . KernDatabase::CLAIMS . '` WHERE `user_id` = ? AND `status` = "active"',
			[(int)$user->id]
		);
		foreach ($rows as $row) {
			$page = $this->wire('pages')->get((int)$row['page_id']);
			if ($page->id && $this->canManage($page, $user)) $result->add($page);
		}
		$templateIds = $this->module->accessPolicy()->directTemplateIds($user);
		if ($templateIds) {
			foreach ($this->wire('pages')->find('include=all, template=' . implode('|', $templateIds)) as $page) {
				if (!$result->has($page) && $this->canManage($page, $user)) $result->add($page);
			}
		}
		return $result;
	}

	public function claimFor(Page $page, User $user): ?array {
		return $this->db->fetchOne(
			'SELECT * FROM `' . KernDatabase::CLAIMS . '` WHERE `page_id` = ? AND `user_id` = ? LIMIT 1',
			[(int)$page->id, (int)$user->id]
		);
	}

	public function claimById(int $id): ?array {
		return $this->db->fetchOne(
			'SELECT * FROM `' . KernDatabase::CLAIMS . '` WHERE `id` = ?',
			[$id]
		);
	}

	public function revisionById(int $id): ?array {
		return $this->db->fetchOne(
			'SELECT * FROM `' . KernDatabase::REVISIONS . '` WHERE `id` = ?',
			[$id]
		);
	}

	public function claims(array $filters = [], int $limit = 100): array {
		return $this->filteredRows(KernDatabase::CLAIMS, $filters, $limit);
	}

	public function revisions(array $filters = [], int $limit = 100): array {
		return $this->filteredRows(KernDatabase::REVISIONS, $filters, $limit);
	}

	public function codes(array $filters = [], int $limit = 100): array {
		return $this->filteredRows(KernDatabase::CODES, $filters, $limit);
	}

	public function history(array $filters = [], int $limit = 200): array {
		return $this->filteredRows(KernDatabase::EVENTS, $filters, $limit);
	}

	public function counts(): array {
		$out = [];
		foreach ([
			'pending_claims' => [KernDatabase::CLAIMS, 'status = "pending"'],
			'active_claims' => [KernDatabase::CLAIMS, 'status = "active"'],
			'pending_revisions' => [KernDatabase::REVISIONS, 'status = "pending"'],
			'conflicts' => [KernDatabase::REVISIONS, 'status = "conflict"'],
			'active_codes' => [KernDatabase::CODES, 'status = "active"'],
		] as $key => [$table, $where]) {
			$row = $this->db->fetchOne("SELECT COUNT(*) AS total FROM `$table` WHERE $where");
			$out[$key] = (int)($row['total'] ?? 0);
		}
		return $out;
	}

	private function filteredRows(string $table, array $filters, int $limit): array {
		$allowed = ['id', 'page_id', 'claim_id', 'revision_id', 'user_id', 'status', 'event_type'];
		$where = [];
		$params = [];
		foreach ($filters as $key => $value) {
			if (!in_array($key, $allowed, true)) continue;
			$where[] = "`$key` = ?";
			$params[] = $value;
		}
		$sql = "SELECT * FROM `$table`";
		if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
		$sql .= ' ORDER BY `id` DESC LIMIT ' . max(1, min(500, $limit));
		return $this->db->fetchAll($sql, $params);
	}

	private function event(
		int $pageId,
		int $claimId,
		int $revisionId,
		int $actorId,
		string $type,
		string $message = '',
		array $meta = []
	): void {
		$this->db->insert(KernDatabase::EVENTS, [
			'page_id' => $pageId,
			'claim_id' => $claimId,
			'revision_id' => $revisionId,
			'actor_id' => $actorId,
			'event_type' => $type,
			'message' => $this->cleanNote($message),
			'meta_json' => $meta ? $this->json($meta) : null,
			'created' => time(),
		]);
	}

	private function assertUser(User $user): void {
		if (!$user->id || $user->isGuest()) {
			throw new WirePermissionException('A logged-in account is required.');
		}
	}

	private function assertAccess(Page $page, User $user, string $action, ?array $claim = null): void {
		$this->assertUser($user);
		$access = $this->module->accessFor($page, $user, $claim);
		if (empty($access['actions'][$action])) {
			throw new WirePermissionException("Kern action '$action' is not allowed for this Page.");
		}
	}

	private function cleanNote(string $value): string {
		return mb_substr($this->wire('sanitizer')->textarea($value), 0, 10000);
	}

	private function json($value): string {
		$json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		return $json;
	}

	private function decode(string $json): array {
		if ($json === '') return [];
		$value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		return is_array($value) ? $value : [];
	}

	private function same($a, $b): bool {
		return $this->canonical($a) === $this->canonical($b);
	}

	private function canonical($value): string {
		if (is_array($value)) {
			if (!array_is_list($value)) ksort($value);
			foreach ($value as &$item) {
				if (is_array($item)) $item = json_decode($this->canonical($item), true);
			}
			unset($item);
		}
		return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	private function newCode(): string {
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$bytes = random_bytes(12);
		$out = '';
		for ($i = 0; $i < 12; $i++) $out .= $alphabet[ord($bytes[$i]) % strlen($alphabet)];
		return substr($out, 0, 4) . '-' . substr($out, 4, 4) . '-' . substr($out, 8, 4);
	}

	private function normalizeCode(string $code): string {
		return strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim($code)) ?: '');
	}

	private function hashCode(string $normalized): string {
		// Keep the original domain separator so access codes issued before the
		// PageClaims → Kern rename remain valid after the data migration.
		$secret = (string)$this->wire('config')->userAuthSalt . '|PageClaims';
		return hash_hmac('sha256', $normalized, $secret);
	}
}
