<?php namespace ProcessWire;

final class KernDatabase extends Wire {

	public const CLAIMS = 'kern_claims';
	public const CODES = 'kern_codes';
	public const REVISIONS = 'kern_revisions';
	public const EVENTS = 'kern_events';
	public const ACCESS_RULES = 'kern_access_rules';

	private WireDatabasePDO $database;

	public function __construct(WireDatabasePDO $database) {
		parent::__construct();
		$this->database = $database;
	}

	public function install(): void {
		$this->migrateLegacyTables();
		$charset = 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

		$this->database->exec(
			'CREATE TABLE IF NOT EXISTS `' . self::CLAIMS . '` (
				`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
				`page_id` INT UNSIGNED NOT NULL,
				`user_id` INT UNSIGNED NOT NULL,
				`status` VARCHAR(24) NOT NULL DEFAULT "pending",
				`source` VARCHAR(24) NOT NULL DEFAULT "request",
				`request_note` TEXT NULL,
				`review_note` TEXT NULL,
				`created` INT UNSIGNED NOT NULL,
				`created_by` INT UNSIGNED NOT NULL DEFAULT 0,
				`reviewed` INT UNSIGNED NOT NULL DEFAULT 0,
				`reviewed_by` INT UNSIGNED NOT NULL DEFAULT 0,
				`revoked` INT UNSIGNED NOT NULL DEFAULT 0,
				`revoked_by` INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY (`id`),
				UNIQUE KEY `page_user` (`page_id`, `user_id`),
				KEY `page_status` (`page_id`, `status`),
				KEY `user_status` (`user_id`, `status`),
				KEY `created` (`created`)
			) ENGINE=InnoDB ' . $charset
		);

		$this->database->exec(
			'CREATE TABLE IF NOT EXISTS `' . self::CODES . '` (
				`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
				`page_id` INT UNSIGNED NOT NULL,
				`code_hash` CHAR(64) NOT NULL,
				`code_hint` VARCHAR(16) NOT NULL,
				`status` VARCHAR(24) NOT NULL DEFAULT "active",
				`expires` INT UNSIGNED NOT NULL DEFAULT 0,
				`max_uses` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
				`uses` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				`created` INT UNSIGNED NOT NULL,
				`created_by` INT UNSIGNED NOT NULL,
				`last_used` INT UNSIGNED NOT NULL DEFAULT 0,
				`last_used_by` INT UNSIGNED NOT NULL DEFAULT 0,
				`meta_json` MEDIUMTEXT NULL,
				PRIMARY KEY (`id`),
				UNIQUE KEY `code_hash` (`code_hash`),
				KEY `page_status` (`page_id`, `status`),
				KEY `expires` (`expires`)
			) ENGINE=InnoDB ' . $charset
		);

		$this->database->exec(
			'CREATE TABLE IF NOT EXISTS `' . self::REVISIONS . '` (
				`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				`claim_id` INT UNSIGNED NOT NULL,
				`page_id` INT UNSIGNED NOT NULL,
				`user_id` INT UNSIGNED NOT NULL,
				`status` VARCHAR(24) NOT NULL DEFAULT "pending",
				`base_modified` INT UNSIGNED NOT NULL DEFAULT 0,
				`note` TEXT NULL,
				`changes_json` MEDIUMTEXT NOT NULL,
				`review_note` TEXT NULL,
				`created` INT UNSIGNED NOT NULL,
				`reviewed` INT UNSIGNED NOT NULL DEFAULT 0,
				`reviewed_by` INT UNSIGNED NOT NULL DEFAULT 0,
				`applied` INT UNSIGNED NOT NULL DEFAULT 0,
				`error_message` TEXT NULL,
				PRIMARY KEY (`id`),
				KEY `claim_status` (`claim_id`, `status`),
				KEY `page_status` (`page_id`, `status`),
				KEY `user_status` (`user_id`, `status`),
				KEY `created` (`created`)
			) ENGINE=InnoDB ' . $charset
		);

		$this->database->exec(
			'CREATE TABLE IF NOT EXISTS `' . self::EVENTS . '` (
				`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				`page_id` INT UNSIGNED NOT NULL DEFAULT 0,
				`claim_id` INT UNSIGNED NOT NULL DEFAULT 0,
				`revision_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
				`actor_id` INT UNSIGNED NOT NULL DEFAULT 0,
				`event_type` VARCHAR(48) NOT NULL,
				`message` TEXT NULL,
				`meta_json` MEDIUMTEXT NULL,
				`created` INT UNSIGNED NOT NULL,
				PRIMARY KEY (`id`),
				KEY `page_created` (`page_id`, `created`),
				KEY `claim_created` (`claim_id`, `created`),
				KEY `revision_created` (`revision_id`, `created`),
				KEY `event_created` (`event_type`, `created`)
			) ENGINE=InnoDB ' . $charset
		);

		$this->database->exec(
			'CREATE TABLE IF NOT EXISTS `' . self::ACCESS_RULES . '` (
				`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
				`name` VARCHAR(255) NOT NULL,
				`enabled` TINYINT UNSIGNED NOT NULL DEFAULT 1,
				`priority` SMALLINT NOT NULL DEFAULT 0,
				`rule_json` MEDIUMTEXT NOT NULL,
				`created` INT UNSIGNED NOT NULL,
				`created_by` INT UNSIGNED NOT NULL DEFAULT 0,
				`modified` INT UNSIGNED NOT NULL,
				`modified_by` INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY (`id`),
				KEY `enabled_priority` (`enabled`, `priority`),
				KEY `modified` (`modified`)
			) ENGINE=InnoDB ' . $charset
		);
	}

	private function migrateLegacyTables(): void {
		foreach ([
			'pageclaims_claims' => self::CLAIMS,
			'pageclaims_codes' => self::CODES,
			'pageclaims_revisions' => self::REVISIONS,
			'pageclaims_events' => self::EVENTS,
		] as $legacy => $current) {
			if (!$this->database->tableExists($legacy) || $this->database->tableExists($current)) continue;
			$this->database->exec("RENAME TABLE `$legacy` TO `$current`");
		}
	}

	public function uninstall(): void {
		foreach ([self::ACCESS_RULES, self::EVENTS, self::REVISIONS, self::CODES, self::CLAIMS] as $table) {
			$this->database->exec("DROP TABLE IF EXISTS `$table`");
		}
	}

	public function fetchOne(string $sql, array $params = []): ?array {
		$stmt = $this->database->prepare($sql);
		$stmt->execute($params);
		$row = $stmt->fetch(\PDO::FETCH_ASSOC);
		return is_array($row) ? $row : null;
	}

	public function fetchAll(string $sql, array $params = []): array {
		$stmt = $this->database->prepare($sql);
		$stmt->execute($params);
		return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
	}

	public function execute(string $sql, array $params = []): int {
		$stmt = $this->database->prepare($sql);
		$stmt->execute($params);
		return $stmt->rowCount();
	}

	public function insert(string $table, array $data): int {
		if (!$data) throw new WireException('Cannot insert an empty row.');
		$columns = array_keys($data);
		$sql = 'INSERT INTO `' . $table . '` (`'
			. implode('`, `', $columns)
			. '`) VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
		$stmt = $this->database->prepare($sql);
		$stmt->execute(array_values($data));
		return (int)$this->database->lastInsertId();
	}

	public function update(string $table, array $data, string $where, array $whereParams = []): int {
		if (!$data) return 0;
		$assignments = [];
		foreach (array_keys($data) as $column) $assignments[] = "`$column` = ?";
		$sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $assignments) . ' WHERE ' . $where;
		$stmt = $this->database->prepare($sql);
		$stmt->execute(array_merge(array_values($data), $whereParams));
		return $stmt->rowCount();
	}

	public function delete(string $table, string $where, array $whereParams = []): int {
		$stmt = $this->database->prepare("DELETE FROM `$table` WHERE $where");
		$stmt->execute($whereParams);
		return $stmt->rowCount();
	}

	public function transaction(callable $callback) {
		$this->database->beginTransaction();
		try {
			$result = $callback();
			$this->database->commit();
			return $result;
		} catch (\Throwable $e) {
			if ($this->database->inTransaction()) $this->database->rollBack();
			throw $e;
		}
	}
}
