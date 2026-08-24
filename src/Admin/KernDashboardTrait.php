<?php namespace ProcessWire;

trait KernDashboardTrait {

	public function execute(): string {
		$this->requireAny(['view_history', 'moderate_claims', 'moderate_revisions', 'issue_codes'], true);
		$this->headline($this->_('Kern dashboard'));
		$pendingClaims = $this->claims()->canAny('moderate_claims', $this->user)
			? $this->filterRowsForAction($this->service()->claims(['status' => 'pending'], 6), 'moderate_claims')
			: [];
		$pendingRevisions = $this->claims()->canAny('moderate_revisions', $this->user)
			? $this->filterRowsForAction($this->service()->revisions(['status' => 'pending'], 6), 'moderate_revisions')
			: [];
		$conflicts = $this->claims()->canAny('moderate_revisions', $this->user)
			? $this->filterRowsForAction($this->service()->revisions(['status' => 'conflict'], 6), 'moderate_revisions')
			: [];
		$history = $this->claims()->canAny('view_history', $this->user)
			? $this->filterRowsForAction($this->service()->history([], 6), 'view_history', true)
			: [];
		$canManageAccess = $this->user->isSuperuser() || $this->user->hasPermission(Kern::PERM_ACCESS);
		$accessRules = $canManageAccess ? $this->claims()->accessRules(false) : [];
		$counts = $this->dashboardCounts($accessRules);
		$openDecisionCount = $counts['pending_claims'] + $counts['pending_revisions'] + $counts['conflicts'];
		if ($counts['conflicts'] > 0) {
			$healthState = 'danger';
			$healthTitle = $this->_('Conflict review required');
			$healthNote = sprintf($this->_('%d conflicting revisions'), (int)$counts['conflicts']);
		} elseif ($openDecisionCount > 0) {
			$healthState = 'warning';
			$healthTitle = $this->_('Moderation waiting');
			$healthNote = sprintf($this->_('%d open decisions'), $openDecisionCount);
		} elseif ($canManageAccess && !$accessRules) {
			$healthState = 'warning';
			$healthTitle = $this->_('Policy setup incomplete');
			$healthNote = $this->_('Legacy fallback is active');
		} else {
			$healthState = 'success';
			$healthTitle = $this->_('Workflow is under control');
			$healthNote = sprintf($this->_('%d active ownerships'), (int)$counts['active_claims']);
		}

		$out = '<div class="KernWorkspace pw-module-workspace">';
		$out .= $this->nav();
		$out .= '<section class="KernDashboardIntro"><div><p class="uk-text-meta uk-text-uppercase uk-margin-remove">'
			. $this->_('Ownership and content governance') . '</p><p class="uk-text-muted uk-margin-small-top uk-margin-remove-bottom">'
			. $this->_('Review ownership requests, proposed Page changes, delegated access, policy coverage, and immutable workflow activity.')
			. '</p></div><div class="KernDashboardIntroAside"><div class="KernDashboardHealth" data-state="'
			. $healthState . '"><span class="KernDashboardHealthDot"></span><div><strong>' . $this->h($healthTitle)
			. '</strong><small>' . $this->h($healthNote) . '</small></div></div><div class="KernWorkspaceActions">';
		if ($this->claims()->canAny('issue_codes', $this->user)) {
			$out .= '<a class="uk-button uk-button-default" href="' . $this->page->url . 'codes/">'
				. '<span uk-icon="icon: lock"></span> ' . $this->_('Create access code') . '</a>';
		}
		if ($canManageAccess) {
			$out .= '<a class="uk-button uk-button-primary" href="' . $this->page->url
				. ($accessRules ? 'access/' : 'access-edit/') . '"><span uk-icon="icon: '
				. ($accessRules ? 'settings' : 'plus') . '"></span> '
				. ($accessRules ? $this->_('Manage access rules') : $this->_('Add access rule')) . '</a>';
		}
		$out .= '</div></div></section>';
		$out .= $this->dashboardAttentionCard(
			$pendingClaims,
			array_merge($conflicts, $pendingRevisions),
			$openDecisionCount
		);

		$out .= '<nav class="KernDashboardSummary" aria-label="' . $this->_('Kern workflow summary') . '">';
		foreach ([
			'pending_claims' => [$this->_('Pending claims'), 'claims/?status=pending', 'users', $this->_('Ownership decisions')],
			'pending_revisions' => [$this->_('Pending revisions'), 'revisions/?status=pending', 'file-edit', $this->_('Proposed Page changes')],
			'conflicts' => [$this->_('Conflicts'), 'revisions/?status=conflict', 'warning', $this->_('Require manual review')],
			'active_claims' => [$this->_('Active ownerships'), 'claims/?status=active', 'check', $this->_('Pages under delegated management')],
			'active_codes' => [$this->_('Active codes'), 'codes/?status=active', 'lock', $this->_('Unused delegated access')],
			'enabled_rules' => [$this->_('Enabled rules'), 'access/?status=enabled', 'settings', sprintf($this->_('%d rules configured'), (int)$counts['total_rules'])],
		] as $key => [$label, $path, $icon, $meta]) {
			if ($key === 'enabled_rules' && !$canManageAccess) continue;
			$out .= '<a href="' . $this->page->url . $path . '"><span class="KernDashboardSummaryIcon" uk-icon="icon: '
				. $icon . '; ratio: .8"></span><span><strong>' . (int)$counts[$key] . '</strong><span>'
				. $this->h($label) . '</span><small>' . $this->h($meta) . '</small></span></a>';
		}
		$out .= '</nav>';

		$out .= '<div class="uk-grid-small KernDashboardPanels" uk-grid><div class="uk-width-expand@l">'
			. '<section class="uk-card uk-card-default uk-card-small KernActivityCard KernDashboardPanel">'
			. '<div class="uk-card-header KernCardHead"><div><h2 class="uk-card-title uk-margin-remove">'
			. $this->_('Recent activity') . '</h2><p class="uk-text-meta uk-margin-small-top">'
			. $this->_('The latest security and workflow events, without technical payloads.') . '</p></div>'
			. '<span class="uk-badge">' . count($history) . '</span></div><div class="uk-card-body">'
			. ($history ? $this->dashboardActivityList($history) : $this->emptyState(
				$this->_('No activity yet'),
				$this->_('Kern will record claim, revision, access-code, and rule events here.'),
				'history'
			)) . '</div>'
			. ($this->claims()->canAny('view_history', $this->user)
				? '<div class="uk-card-footer"><a class="uk-button uk-button-text" href="' . $this->page->url . 'history/">'
					. $this->_('Open history') . '</a></div>'
				: '') . '</section></div><div class="uk-width-1-3@l">'
			. $this->dashboardAccessSummary($counts, $accessRules, $canManageAccess) . '</div></div></div>';
		return $this->assets() . $out;
	}
	private function dashboardAccessSummary(array $counts, array $accessRules, bool $canManageAccess): string {
		$rows = [];
		if ($this->claims()->canAny('moderate_claims', $this->user)) {
			$rows[] = [$this->_('Active ownerships'), (int)$counts['active_claims'], $this->_('Managed Pages'), 'claims/?status=active'];
		}
		if ($this->claims()->canAny('issue_codes', $this->user)) {
			$rows[] = [$this->_('Active codes'), (int)$counts['active_codes'], $this->_('Available delegated access'), 'codes/?status=active'];
		}
		if ($canManageAccess) {
			$rows[] = [
				$this->_('Access rules'),
				(int)$counts['enabled_rules'],
				$accessRules ? sprintf($this->_('%d configured'), (int)$counts['total_rules']) : $this->_('No explicit rules'),
				$accessRules ? 'access/' : 'access-edit/',
			];
		}
		$out = '<section class="uk-card uk-card-default uk-card-small KernDashboardAccessPanel KernDashboardPanel">'
			. '<div class="uk-card-header KernCardHead"><div><h2 class="uk-card-title uk-margin-remove">'
			. $this->_('Delegated access') . '</h2><p class="uk-text-meta uk-margin-small-top">'
			. $this->_('Ownership coverage, active codes, and policy readiness.') . '</p></div></div>'
			. '<div class="uk-card-body"><ul class="KernDashboardAccessList">';
		foreach ($rows as [$label, $value, $meta, $path]) {
			$out .= '<li><a href="' . $this->page->url . $path . '"><span><strong>' . $this->h($label)
				. '</strong><small>' . $this->h($meta) . '</small></span><span class="KernDashboardAccessValue">'
				. $value . '</span><span uk-icon="icon: chevron-right; ratio: .75"></span></a></li>';
		}
		$out .= '</ul></div><div class="uk-card-footer KernDashboardLinks">';
		if ($this->claims()->canAny('issue_codes', $this->user)) {
			$out .= '<a class="uk-button uk-button-text" href="' . $this->page->url . 'codes/">' . $this->_('Manage access codes') . '</a>';
		}
		if ($canManageAccess) {
			$out .= '<a class="uk-button uk-button-text" href="' . $this->page->url . ($accessRules ? 'access/' : 'access-edit/') . '">'
				. ($accessRules ? $this->_('Manage access rules') : $this->_('Create first rule')) . '</a>';
		}
		return $out . '</div></section>';
	}
	private function dashboardAttentionCard(array $claims, array $revisions, int $total): string {
		$items = [];
		foreach ($claims as $row) $items[] = ['kind' => 'claim', 'row' => $row];
		foreach ($revisions as $row) $items[] = ['kind' => 'revision', 'row' => $row];
		usort($items, fn(array $a, array $b): int => (int)$b['row']['created'] <=> (int)$a['row']['created']);
		$items = array_slice($items, 0, 6);
		if (!$items) {
			return '<section class="KernDashboardClearState"><span class="KernDashboardClearIcon" uk-icon="icon: check; ratio: .85"></span><div><strong>'
				. $this->_('No moderation waiting') . '</strong><small>'
				. $this->_('Claims, proposed changes, and conflicts are all clear.')
				. '</small></div><span class="KernDashboardClearMeta">' . $this->_('Queue healthy') . '</span></section>';
		}
		$out = '<section class="uk-card uk-card-default uk-card-small KernQueueCard KernAttentionCard KernDashboardPanel">'
			. '<div class="uk-card-header KernCardHead"><div><h2 class="uk-card-title uk-margin-remove">'
			. $this->_('Needs attention') . '</h2><p class="uk-text-meta uk-margin-small-top">'
			. $this->_('Ownership decisions, proposed changes, and conflicts waiting for review.')
			. '</p></div><span class="uk-badge">' . $total . '</span></div><div class="uk-card-body">';
		$pages = [];
		$users = [];
		$out .= '<ul class="uk-list uk-list-divider KernQueueList">';
		foreach ($items as ['kind' => $kind, 'row' => $row]) {
			$pageId = (int)$row['page_id'];
			$userId = (int)$row['user_id'];
			if (!isset($pages[$pageId])) $pages[$pageId] = $this->pages->get($pageId);
			if (!isset($users[$userId])) $users[$userId] = $this->users->get($userId);
			$page = $pages[$pageId];
			$user = $users[$userId];
			$pageTitle = $page->id ? ($page->getUnformatted('title') ?: $page->name) : $this->_('Missing Page');
			$url = $this->page->url . $kind . '/?id=' . (int)$row['id'];
			$kindLabel = $kind === 'claim' ? $this->_('Ownership claim') : $this->_('Page revision');
			$out .= '<li><div class="KernQueueItem"><div><span class="uk-label KernQueueType">'
				. $kindLabel . '</span><a class="uk-link-heading" href="' . $url . '"><strong>'
				. $this->h($pageTitle) . '</strong></a><div class="uk-text-small uk-text-muted">'
				. $this->h($user->id ? $user->name : '#' . $userId) . ' · ' . $this->dateTime((int)$row['created'])
				. '</div></div><div class="KernQueueDecision">' . $this->badge((string)$row['status'])
				. '<a class="uk-button uk-button-primary uk-button-small" href="' . $url . '">' . $this->_('Review')
				. '</a></div></div></li>';
		}
		$out .= '</ul>';
		$out .= '</div>';
		$links = '';
		if ($claims && $this->claims()->canAny('moderate_claims', $this->user)) {
			$links .= '<a class="uk-button uk-button-text" href="' . $this->page->url . 'claims/?status=pending">'
				. $this->_('View pending claims') . '</a>';
		}
		if ($revisions && $this->claims()->canAny('moderate_revisions', $this->user)) {
			$links .= '<a class="uk-button uk-button-text" href="' . $this->page->url . 'revisions/">'
				. $this->_('View revision queue') . '</a>';
		}
		if ($links !== '') $out .= '<div class="uk-card-footer KernDashboardLinks">' . $links . '</div>';
		return $out . '</section>';
	}
	private function dashboardActivityList(array $rows): string {
		$pages = [];
		$users = [];
		$out = '<ol class="uk-list uk-list-divider KernDashboardActivity">';
		foreach (array_slice($rows, 0, 6) as $row) {
			$pageId = (int)$row['page_id'];
			$actorId = (int)$row['actor_id'];
			if (!isset($pages[$pageId])) $pages[$pageId] = $this->pages->get($pageId);
			if (!isset($users[$actorId])) $users[$actorId] = $this->users->get($actorId);
			$page = $pages[$pageId];
			$user = $users[$actorId];
			$actor = $user->id ? $user->name : ($actorId ? '#' . $actorId : $this->_('System'));
			$out .= '<li><div class="KernDashboardActivityItem">' . $this->historyEventLabel((string)$row['event_type'])
				. '<div class="KernDashboardActivityContext">' . $this->pageLabel($page) . '<span class="uk-text-meta">'
				. $this->h($actor) . ' · ' . $this->dateTime((int)$row['created']) . '</span></div></div></li>';
		}
		return $out . '</ol>';
	}
	private function dashboardCounts(array $accessRules = []): array {
		$counts = [
			'pending_claims' => 0,
			'active_claims' => 0,
			'pending_revisions' => 0,
			'conflicts' => 0,
			'active_codes' => 0,
			'enabled_rules' => count(array_filter($accessRules, fn(array $rule): bool => (bool)$rule['enabled'])),
			'total_rules' => count($accessRules),
		];
		if ($this->claims()->canAny('moderate_claims', $this->user)) {
			$counts['pending_claims'] = count($this->filterRowsForAction(
				$this->service()->claims(['status' => 'pending'], 500),
				'moderate_claims'
			));
			$counts['active_claims'] = count($this->filterRowsForAction(
				$this->service()->claims(['status' => 'active'], 500),
				'moderate_claims'
			));
		}
		if ($this->claims()->canAny('moderate_revisions', $this->user)) {
			$counts['pending_revisions'] = count($this->filterRowsForAction(
				$this->service()->revisions(['status' => 'pending'], 500),
				'moderate_revisions'
			));
			$counts['conflicts'] = count($this->filterRowsForAction(
				$this->service()->revisions(['status' => 'conflict'], 500),
				'moderate_revisions'
			));
		}
		if ($this->claims()->canAny('issue_codes', $this->user)) {
			$counts['active_codes'] = count($this->filterRowsForAction(
				$this->service()->codes(['status' => 'active'], 500),
				'issue_codes'
			));
		}
		return $counts;
	}
}

