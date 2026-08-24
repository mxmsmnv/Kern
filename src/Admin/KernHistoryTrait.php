<?php namespace ProcessWire;

trait KernHistoryTrait {

	public function executeHistory(): string {
		$this->requireAny(['view_history']);
		$this->headline($this->_('Audit history'));
		$allRows = $this->filterRowsForAction($this->service()->history([], 500), 'view_history', true);
		$scope = $this->sanitizer->name((string)$this->input->get('scope'));
		$query = trim($this->sanitizer->text((string)$this->input->get('q')));
		$scopes = [
			'' => $this->_('All activity'),
			'claims' => $this->_('Claims'),
			'revisions' => $this->_('Revisions'),
			'codes' => $this->_('Access codes'),
			'access' => $this->_('Access rules'),
		];
		if (!array_key_exists($scope, $scopes)) $scope = '';
		$counts = array_fill_keys(array_keys($scopes), 0);
		$pageIds = [];
		$actorIds = [];
		$recentCount = 0;
		$recentCutoff = time() - 604800;
		foreach ($allRows as $row) {
			$counts['']++;
			$rowScope = $this->historyEventScope((string)$row['event_type']);
			if (isset($counts[$rowScope])) $counts[$rowScope]++;
			if ((int)$row['page_id']) $pageIds[(int)$row['page_id']] = true;
			if ((int)$row['actor_id']) $actorIds[(int)$row['actor_id']] = true;
			if ((int)$row['created'] >= $recentCutoff) $recentCount++;
		}
		$rows = $scope === '' ? $allRows : array_values(array_filter(
			$allRows,
			fn(array $row): bool => $this->historyEventScope((string)$row['event_type']) === $scope
		));
		if ($query !== '') {
			$pages = [];
			$actors = [];
			$rows = array_values(array_filter($rows, function(array $row) use ($query, &$pages, &$actors): bool {
				$pageId = (int)$row['page_id'];
				$actorId = (int)$row['actor_id'];
				if (!isset($pages[$pageId])) $pages[$pageId] = $this->pages->get($pageId);
				if (!isset($actors[$actorId])) $actors[$actorId] = $this->users->get($actorId);
				$page = $pages[$pageId];
				$actor = $actors[$actorId];
				$haystack = implode(' ', [
					(string)$row['id'], (string)$row['event_type'], (string)$row['message'], (string)$row['meta_json'],
					$page->id ? (string)$page->getUnformatted('title') : '',
					$page->id ? (string)$page->name : '',
					$page->id ? (string)$page->path : '',
					$actor->id ? (string)$actor->name : '',
					$actor->id ? (string)$actor->email : '',
				]);
				return function_exists('mb_stripos') ? mb_stripos($haystack, $query) !== false : stripos($haystack, $query) !== false;
			}));
		}
		$sectionTitle = $scope === '' ? $this->_('All recorded activity') : $scopes[$scope];
		$sectionDescription = match ($scope) {
			'claims' => $this->_('Ownership requests, decisions, activations, and revocations.'),
			'revisions' => $this->_('Submitted Page changes, moderation decisions, conflicts, and failures.'),
			'codes' => $this->_('Access-code creation, redemption, expiry, and revocation events.'),
			'access' => $this->_('Access-rule creation, policy changes, and deletions.'),
			default => $this->_('Ownership, content, delegated-access, and permission changes in newest-first order.'),
		};

		$out = $this->nav() . '<div class="KernWorkspace KernHistoryWorkspace pw-module-workspace">';
		$out .= '<section class="KernHistoryIntro"><div><span class="uk-text-meta">' . $this->_('Immutable audit trail')
			. '</span><h2 class="uk-h3 uk-margin-small-top uk-margin-remove-bottom">' . $this->_('Trace every governance change')
			. '</h2><p class="uk-text-muted uk-margin-small-top">'
			. $this->_('Find who changed ownership, content, delegated access, or policy and inspect the recorded evidence.')
			. '</p></div><div class="KernHistoryQueueState" data-state="' . ($recentCount ? 'active' : 'idle')
			. '"><span class="KernHistoryQueueDot"></span><span><strong>'
			. ($recentCount ? sprintf($this->_('%d events in 7 days'), $recentCount) : $this->_('No activity in 7 days'))
			. '</strong><small>' . ($allRows
				? sprintf($this->_('Latest: %s'), strip_tags($this->dateTime((int)$allRows[0]['created'])))
				: $this->_('The audit trail is empty'))
			. '</small></span></div></section>';
		$out .= '<div class="KernHistorySummary" aria-label="' . $this->h($this->_('Audit history summary')) . '">';
		foreach ([
			[$this->_('Recent'), $recentCount, $this->_('Last 7 days')],
			[$this->_('Pages'), count($pageIds), $this->_('Affected records')],
			[$this->_('Actors'), count($actorIds), $this->_('People and systems')],
			[$this->_('Recorded'), count($allRows), sprintf($this->_('Latest %d retained'), 500)],
		] as [$label, $value, $note]) {
			$out .= '<div><span>' . $this->h($label) . '</span><strong>' . (int)$value
				. '</strong><small>' . $this->h($note) . '</small></div>';
		}
		$out .= '</div><section class="KernHistoryPanel"><div class="KernWorkspaceHead"><div><span class="uk-text-meta">'
			. $this->_('Activity register') . '</span><h2 class="uk-h3 uk-margin-small-top uk-margin-remove-bottom">'
			. $this->h($sectionTitle) . '</h2><p class="uk-text-muted uk-margin-small-top">'
			. $this->h($sectionDescription) . ' ' . $this->_('Technical payloads stay collapsed until needed.')
			. '</p></div><div class="KernQueueSummary"><strong>' . count($rows) . '</strong><span>'
			. $this->_('events shown') . '</span></div></div>'
			. '<form class="KernHistorySearch" method="get" action="' . $this->page->url . 'history/">'
			. ($scope !== '' ? '<input type="hidden" name="scope" value="' . $this->h($scope) . '">' : '')
			. '<label class="uk-form-label" for="KernHistorySearch">' . $this->_('Search audit history') . '</label><div>'
			. '<input class="uk-input" id="KernHistorySearch" name="q" type="search" value="' . $this->h($query)
			. '" placeholder="' . $this->h($this->_('Page, actor, event, message, or ID')) . '">'
			. '<button class="uk-button uk-button-primary" type="submit"><span uk-icon="icon: search"></span> '
			. $this->_('Search') . '</button>'
			. ($query !== '' ? '<a class="uk-button uk-button-default" href="' . $this->page->url . 'history/'
				. ($scope !== '' ? '?scope=' . rawurlencode($scope) : '') . '">' . $this->_('Clear') . '</a>' : '')
			. '</div></form>'
			. $this->historyFilterLinks($scopes, $scope, $counts, $query);
		$out .= $rows ? $this->historyTable($rows) : $this->emptyState(
			$query !== '' ? $this->_('No events match this search') : $this->_('No activity in this category'),
			$query !== '' ? $this->_('Try a Page title, actor, event name, message, or record ID.')
				: $this->_('Choose another category to review the recorded audit trail.'),
			'history',
			$scope === '' && $query === '' ? null : $this->page->url . 'history/',
			$scope === '' && $query === '' ? null : $this->_('View all activity')
		);
		return $this->assets() . $out . '</section></div>';
	}
	private function historyTable(array $rows, bool $panel = true): string {
		$class = $panel ? 'uk-overflow-auto KernTablePanel' : 'uk-overflow-auto';
		$out = '<div class="' . $class . ' KernHistoryTable"><table class="AdminDataTable AdminDataList uk-table uk-table-divider uk-table-hover uk-table-middle uk-table-small"><thead><tr><th>'
			. $this->_('When') . '</th><th>' . $this->_('Activity') . '</th><th>' . $this->_('Context') . '</th><th>'
			. $this->_('Details') . '</th></tr></thead><tbody>';
		$pages = [];
		$users = [];
		foreach ($rows as $row) {
			$pageId = (int)$row['page_id'];
			$actorId = (int)$row['actor_id'];
			if (!isset($pages[$pageId])) $pages[$pageId] = $this->pages->get($pageId);
			if (!isset($users[$actorId])) $users[$actorId] = $this->users->get($actorId);
			$page = $pages[$pageId];
			$user = $users[$actorId];
			$meta = $this->decode($row['meta_json']);
			$actor = $this->h($user->id ? $user->name : ($actorId ? '#' . $actorId : $this->_('System')));
			$event = (string)$row['event_type'];
			$details = $this->historyDetails((string)$row['message'], $meta);
			$out .= '<tr><td class="KernHistoryWhen">' . $this->dateTime((int)$row['created']) . '</td><td>'
				. $this->historyEventLabel($event) . '</td><td><div class="KernHistoryContext">' . $this->pageLabel($page)
				. '<span class="uk-text-meta">' . sprintf($this->_('Actor: %s'), $actor) . '</span></div></td><td>'
				. $details . '</td></tr>';
		}
		$out .= '</tbody></table></div><div class="KernHistoryCards">';
		foreach ($rows as $row) {
			$pageId = (int)$row['page_id'];
			$actorId = (int)$row['actor_id'];
			$page = $pages[$pageId];
			$user = $users[$actorId];
			$actor = $this->h($user->id ? $user->name : ($actorId ? '#' . $actorId : $this->_('System')));
			$meta = $this->decode($row['meta_json']);
			$message = trim((string)$row['message']);
			$out .= '<article class="uk-card uk-card-default uk-card-small KernHistoryEventCard"><div class="uk-card-header KernHistoryEventHead"><div>'
				. $this->historyEventLabel((string)$row['event_type']) . '<div class="uk-text-meta uk-margin-small-top">'
				. $this->dateTime((int)$row['created']) . '</div></div></div><div class="uk-card-body"><div class="KernHistoryContext">'
				. $this->pageLabel($page) . '<span class="uk-text-meta">' . sprintf($this->_('Actor: %s'), $actor)
				. '</span></div>' . (($message !== '' || $meta)
					? '<details class="KernHistoryCardDetails"><summary>' . $this->_('View event details') . '</summary><div>'
						. $this->historyDetails($message, $meta) . '</div></details>'
					: '')
				. '</div></article>';
		}
		return $out . '</div>';
	}
	private function historyFilterLinks(array $items, string $active, array $counts, string $query = ''): string {
		$out = '<ul class="uk-subnav uk-subnav-divider KernFilters KernFilterNav KernHistoryFilters" aria-label="'
			. $this->h($this->_('Filter activity by category')) . '">';
		foreach ($items as $value => $label) {
			$params = [];
			if ($value !== '') $params['scope'] = $value;
			if ($query !== '') $params['q'] = $query;
			$url = $this->page->url . 'history/' . ($params ? '?' . http_build_query($params) : '');
			$out .= '<li' . ($value === $active ? ' class="uk-active"' : '') . '><a href="' . $url . '"'
				. ($value === $active ? ' aria-current="page"' : '') . '>'
				. $this->h($label) . ' <span class="KernFilterCount">' . (int)($counts[$value] ?? 0)
				. '</span></a></li>';
		}
		return $out . '</ul>';
	}
	private function historyEventScope(string $event): string {
		return match (true) {
			str_starts_with($event, 'claim.') => 'claims',
			str_starts_with($event, 'revision.') => 'revisions',
			str_starts_with($event, 'code.') => 'codes',
			str_starts_with($event, 'access_rule.') => 'access',
			default => '',
		};
	}
	private function historyEventLabel(string $event): string {
		$label = match ($event) {
			'claim.requested' => $this->_('Ownership requested'),
			'claim.active',
			'claim.activated' => $this->_('Ownership activated'),
			'claim.rejected' => $this->_('Ownership rejected'),
			'claim.revoked' => $this->_('Ownership revoked'),
			'code.created' => $this->_('Access code created'),
			'code.redeemed' => $this->_('Access code redeemed'),
			'code.revoked' => $this->_('Access code revoked'),
			'revision.submitted' => $this->_('Revision submitted'),
			'revision.approved' => $this->_('Revision approved'),
			'revision.rejected' => $this->_('Revision rejected'),
			'revision.conflict' => $this->_('Revision conflict'),
			'revision.failed' => $this->_('Revision failed'),
			'access_rule.created' => $this->_('Access rule created'),
			'access_rule.updated' => $this->_('Access rule updated'),
			'access_rule.deleted' => $this->_('Access rule deleted'),
			default => ucwords(str_replace(['.', '_'], ' ', $event)),
		};
		$scope = $this->historyEventScope($event);
		$icon = match ($scope) {
			'claims' => 'users',
			'revisions' => 'file-edit',
			'codes' => 'lock',
			'access' => 'settings',
			default => 'history',
		};
		return '<div class="KernHistoryEvent"><span class="KernHistoryEventIcon" uk-icon="icon: ' . $icon
			. '"></span><span><strong>' . $this->h($label) . '</strong><code>' . $this->h($event) . '</code></span></div>';
	}
	private function historyDetails(string $message, array $meta): string {
		$out = $message !== '' ? '<p class="KernHistoryMessage">' . nl2br($this->h($message)) . '</p>' : '';
		if ($meta) {
			$out .= '<dl class="KernHistoryMeta">';
			foreach ($meta as $key => $value) {
				$label = match ((string)$key) {
					'code_id' => $this->_('Code'),
					'max_uses' => $this->_('Maximum uses'),
					'rule_id' => $this->_('Rule'),
					default => ucwords(str_replace('_', ' ', (string)$key)),
				};
				if ((string)$key === 'expires' && is_numeric($value)) {
					$formatted = $this->dateTime((int)$value);
				} elseif (is_array($value)) {
					$items = array_map(static function($item): string {
						if ($item === null) return '—';
						if (is_bool($item)) return $item ? 'Yes' : 'No';
						if (is_scalar($item)) return (string)$item;
						return (string)json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
					}, $value);
					$formatted = $items ? $this->h(implode(', ', $items)) : '<span class="uk-text-muted">' . $this->_('None') . '</span>';
				} elseif (is_bool($value)) {
					$formatted = $value ? $this->_('Yes') : $this->_('No');
				} else {
					$formatted = $this->h((string)$value);
				}
				$out .= '<div><dt>' . $this->h($label) . '</dt><dd>' . $formatted . '</dd></div>';
			}
			$out .= '</dl><details class="KernEventTechnical"><summary>' . $this->_('Technical details')
				. '</summary><pre>' . $this->pretty($meta) . '</pre></details>';
		}
		return $out !== '' ? $out : '<span class="uk-text-muted">' . $this->_('No additional details') . '</span>';
	}
}

