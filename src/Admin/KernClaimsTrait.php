<?php namespace ProcessWire;

trait KernClaimsTrait {

	public function executeClaims(): string {
		$this->requireAny(['moderate_claims']);
		$this->headline($this->_('Ownership claims'));
		$status = $this->sanitizer->name((string)$this->input->get('status'));
		$query = trim($this->sanitizer->text((string)$this->input->get('q')));
		$statuses = [
			'' => $this->_('All'),
			'pending' => $this->_('Pending'),
			'active' => $this->_('Active'),
			'closed' => $this->_('Closed'),
			'rejected' => $this->_('Rejected'),
			'revoked' => $this->_('Revoked'),
		];
		if (!array_key_exists($status, $statuses)) $status = '';
		$allRows = $this->filterRowsForAction($this->service()->claims([], 500), 'moderate_claims');
		$counts = array_fill_keys(array_keys($statuses), 0);
		$counts[''] = count($allRows);
		foreach ($allRows as $row) {
			$rowStatus = (string)$row['status'];
			if (isset($counts[$rowStatus])) $counts[$rowStatus]++;
		}
		$counts['closed'] = $counts['rejected'] + $counts['revoked'];
		$pages = [];
		$users = [];
		$viewRows = [];
		foreach ($allRows as $row) {
			$pageId = (int)$row['page_id'];
			$userId = (int)$row['user_id'];
			if (!isset($pages[$pageId])) $pages[$pageId] = $this->pages->get($pageId);
			if (!isset($users[$userId])) $users[$userId] = $this->users->get($userId);
			$page = $pages[$pageId];
			$user = $users[$userId];
			if ($status === 'closed' && !in_array($row['status'], ['rejected', 'revoked'], true)) continue;
			if ($status !== '' && $status !== 'closed' && $row['status'] !== $status) continue;
			if ($query !== '') {
				$haystack = implode(' ', [
					(string)$row['id'], (string)$row['source'], (string)$row['status'],
					$page->id ? (string)$page->getUnformatted('title') : '',
					$page->id ? (string)$page->name : '',
					$page->id ? (string)$page->path : '',
					$user->id ? (string)$user->name : '',
					$user->id ? (string)$user->email : '',
				]);
				$matches = function_exists('mb_stripos') ? mb_stripos($haystack, $query) !== false : stripos($haystack, $query) !== false;
				if (!$matches) continue;
			}
			$viewRows[] = ['row' => $row, 'page' => $page, 'user' => $user];
		}
		if ($status === '') {
			usort($viewRows, static function(array $a, array $b): int {
				$priority = ['pending' => 0, 'active' => 1, 'rejected' => 2, 'revoked' => 2];
				$aPriority = $priority[(string)$a['row']['status']] ?? 3;
				$bPriority = $priority[(string)$b['row']['status']] ?? 3;
				return $aPriority === $bPriority
					? (int)$b['row']['created'] <=> (int)$a['row']['created']
					: $aPriority <=> $bPriority;
			});
		}
		$viewRows = array_slice($viewRows, 0, 200);
		$closedCount = $counts['closed'];
		$sectionTitle = $status === '' ? $this->_('All claims') : sprintf($this->_('%s claims'), $statuses[$status]);
		$sectionDescription = match ($status) {
			'pending' => $this->_('Review each request and decide whether this account should control the Page.'),
			'active' => $this->_('Accounts that currently hold delegated access to a Page.'),
			'closed' => $this->_('Rejected and revoked requests retained for audit without granting access.'),
			'rejected' => $this->_('Requests that were reviewed and declined.'),
			'revoked' => $this->_('Ownerships that no longer grant access but remain in the audit record.'),
			default => $this->_('Pending requests need a decision; active and closed ownerships remain available for audit.'),
		};

		$out = '<div class="KernWorkspace KernClaimsWorkspace pw-module-workspace">' . $this->nav();
		$out .= '<section class="KernClaimsIntro"><div><span class="uk-text-meta">' . $this->_('Ownership governance')
			. '</span><h2 class="uk-h3 uk-margin-small-top uk-margin-remove-bottom">' . $this->_('Review delegated ownership')
			. '</h2><p class="uk-text-muted uk-margin-small-top">'
			. $this->_('Decide who may maintain a Page, verify how access was granted, and keep closed decisions available for audit.')
			. '</p></div><a class="KernClaimQueueState" data-state="' . ($counts['pending'] ? 'attention' : 'clear')
			. '" href="' . $this->page->url . 'claims/?status=pending"><span class="KernClaimQueueDot"></span><span><strong>'
			. ($counts['pending'] ? sprintf($this->_('%d decisions waiting'), $counts['pending']) : $this->_('Review queue is clear'))
			. '</strong><small>' . sprintf($this->_('%d active ownerships'), $counts['active']) . '</small></span></a></section>';
		$out .= '<div class="KernClaimSummary" aria-label="' . $this->h($this->_('Claim summary')) . '">';
		foreach ([
			[$this->_('Pending'), $counts['pending'], $this->_('Needs a decision'), 'claims/?status=pending'],
			[$this->_('Active'), $counts['active'], $this->_('Access is granted'), 'claims/?status=active'],
			[$this->_('Closed'), $closedCount, $this->_('Rejected or revoked'), 'claims/?status=closed'],
			[$this->_('Recorded'), $counts[''], $this->_('Complete audit set'), 'claims/'],
		] as [$label, $value, $note, $path]) {
			$out .= '<a href="' . $this->page->url . $path . '"><span>' . $this->h($label) . '</span><strong>'
				. (int)$value . '</strong><small>' . $this->h($note) . '</small></a>';
		}
		$out .= '</div><section class="KernClaimPanel"><div class="KernWorkspaceHead"><div><span class="uk-text-meta">'
			. $this->_('Ownership register') . '</span><h2 class="uk-h3 uk-margin-small-top uk-margin-remove-bottom">'
			. $this->h($sectionTitle) . '</h2><p class="uk-text-muted uk-margin-small-top">'
			. $this->h($sectionDescription) . '</p></div><div class="KernQueueSummary"><strong>'
			. count($viewRows) . '</strong><span>' . $this->_('claims shown') . '</span></div></div>'
			. '<form class="KernClaimSearch" method="get" action="' . $this->page->url . 'claims/">'
			. ($status !== '' ? '<input type="hidden" name="status" value="' . $this->h($status) . '">' : '')
			. '<label class="uk-form-label" for="KernClaimSearch">' . $this->_('Search ownership claims') . '</label><div>'
			. '<input class="uk-input" id="KernClaimSearch" name="q" type="search" value="' . $this->h($query)
			. '" placeholder="' . $this->h($this->_('Page, requester, email, source, or claim ID')) . '">'
			. '<button class="uk-button uk-button-primary" type="submit"><span uk-icon="icon: search"></span> '
			. $this->_('Search') . '</button>'
			. ($query !== '' ? '<a class="uk-button uk-button-default" href="' . $this->page->url . 'claims/'
				. ($status !== '' ? '?status=' . rawurlencode($status) : '') . '">' . $this->_('Clear') . '</a>' : '')
			. '</div></form>'
			. $this->filterLinks('claims', $statuses, $status, $counts, $query !== '' ? ['q' => $query] : []);
		if (!$viewRows) {
			$out .= $this->emptyState(
				$query !== '' ? $this->_('No claims match this search') : ($status === '' ? $this->_('No ownership claims yet') : $this->_('No claims with this status')),
				$query !== ''
					? $this->_('Try a Page title, requester name, email address, access source, or claim number.')
					: ($status === '' ? $this->_('Ownership requests and access-code activations will appear here.')
						: $this->_('There are no accessible claims matching the selected status.')),
				$status === '' ? 'users' : 'check',
				$status === '' && $query === '' ? null : $this->page->url . 'claims/',
				$status === '' && $query === '' ? null : $this->_('View all claims')
			);
			return $this->assets() . $out . '</section></div>';
		}

		$out .= '<div class="uk-overflow-auto KernTablePanel KernClaimTable"><table class="AdminDataTable AdminDataList uk-table uk-table-divider uk-table-hover uk-table-middle uk-table-small">'
			. '<thead><tr><th>' . $this->_('Page') . '</th><th>' . $this->_('Requester') . '</th><th>'
			. $this->_('Access source') . '</th><th>' . $this->_('Recorded') . '</th><th>' . $this->_('Status')
			. '</th><th class="uk-text-right">' . $this->_('Action') . '</th></tr></thead><tbody>';
		foreach ($viewRows as ['row' => $row, 'page' => $page, 'user' => $user]) {
			$url = $this->page->url . 'claim/?id=' . (int)$row['id'];
			$action = $row['status'] === 'pending' ? $this->_('Review claim') : $this->_('View claim');
			$out .= '<tr><td>' . $this->pageLabel($page) . '<a class="uk-text-small uk-text-muted KernClaimId" href="' . $url . '">'
				. sprintf($this->_('Claim #%d'), (int)$row['id']) . '</a></td><td>'
				. $this->h($user->id ? $user->name : '#' . (int)$row['user_id']) . '</td><td>'
				. $this->claimSource((string)$row['source']) . '</td><td>' . $this->dateTime((int)$row['created'])
				. '</td><td>' . $this->badge((string)$row['status'])
				. '</td><td class="uk-text-right"><a class="uk-button uk-button-small '
				. ($row['status'] === 'pending' ? 'uk-button-primary' : 'uk-button-default') . '" href="' . $url . '">'
				. $action . '</a></td></tr>';
		}
		$out .= '</tbody></table></div><div class="KernClaimCards">';
		foreach ($viewRows as ['row' => $row, 'page' => $page, 'user' => $user]) {
			$url = $this->page->url . 'claim/?id=' . (int)$row['id'];
			$action = $row['status'] === 'pending' ? $this->_('Review claim') : $this->_('View claim');
			$out .= '<article class="uk-card uk-card-default uk-card-small KernClaimCard"><div class="uk-card-header KernClaimCardHead"><div>'
				. '<span class="uk-text-meta">' . sprintf($this->_('Claim #%d'), (int)$row['id']) . '</span><h3 class="uk-card-title uk-margin-small-top uk-margin-remove-bottom">'
				. $this->pageLabel($page) . '</h3></div>' . $this->badge((string)$row['status'])
				. '</div><div class="uk-card-body"><dl class="KernClaimMeta"><div><dt>' . $this->_('Requester') . '</dt><dd>'
				. $this->h($user->id ? $user->name : '#' . (int)$row['user_id']) . '</dd></div><div><dt>'
				. $this->_('Source') . '</dt><dd>' . $this->claimSource((string)$row['source']) . '</dd></div><div><dt>'
				. $this->_('Recorded') . '</dt><dd>' . $this->dateTime((int)$row['created'])
				. '</dd></div></dl></div><div class="uk-card-footer"><a class="uk-button uk-button-small '
				. ($row['status'] === 'pending' ? 'uk-button-primary' : 'uk-button-default') . ' uk-width-1-1" href="'
				. $url . '">' . $action . '</a></div></article>';
		}
		return $this->assets() . $out . '</div></section></div>';
	}
	public function executeClaim(): string {
		$id = (int)$this->input->get('id');
		$claim = $this->service()->claimById($id);
		if (!$claim) throw new Wire404Exception();
		$this->requirePageAction($this->pages->get((int)$claim['page_id']), 'moderate_claims');
		if ($this->input->requestMethod('POST')) {
			$this->session->CSRF->validate();
			$decision = $this->sanitizer->name((string)$this->input->post('decision'));
			$allowedDecisions = match ((string)$claim['status']) {
				'pending' => ['approve', 'reject'],
				'active' => ['revoke'],
				default => [],
			};
			if (!in_array($decision, $allowedDecisions, true)) {
				throw new WireException($this->_('This ownership claim cannot receive that decision in its current state.'));
			}
			$note = (string)$this->input->post('note');
			$this->service()->moderateClaim($id, $decision, $this->user, $note);
			$this->message($this->_('Claim updated.'));
			$this->session->redirect($this->page->url . 'claim/?id=' . $id);
		}
		$page = $this->pages->get((int)$claim['page_id']);
		$user = $this->users->get((int)$claim['user_id']);
		$reviewer = $this->users->get((int)($claim['reviewed_by'] ?? 0));
		$history = $this->service()->history(['claim_id' => $id], 100);
		$status = (string)$claim['status'];
		$isPending = $status === 'pending';
		$isActive = $status === 'active';
		$stateDescription = match ($status) {
			'pending' => $this->_('Confirm whether this account should receive managed access to the Page.'),
			'active' => $this->_('This account can submit Page changes through the governed Kern workflow.'),
			'rejected' => $this->_('This ownership request was reviewed and declined.'),
			'revoked' => $this->_('Managed access has been revoked; the immutable activity record remains available.'),
			default => $this->_('Inspect the ownership state and its recorded activity.'),
		};
		$milestoneLabel = $isPending ? $this->_('Requested') : ($isActive ? $this->_('Active since') : $this->_('Reviewed'));
		$milestone = $isPending ? (int)$claim['created'] : (int)($claim['reviewed'] ?: $claim['created']);
		$this->headline(sprintf($this->_('Claim #%d'), $id));
		$out = '<div class="KernWorkspace pw-module-workspace">' . $this->nav();
		$out .= '<section class="KernClaimDetailIntro"><div><span class="uk-text-meta">'
			. ($isPending ? $this->_('Ownership review') : $this->_('Ownership record'))
			. '</span><h2 class="uk-margin-small-top uk-margin-remove-bottom">' . $this->pageLabel($page)
			. '</h2><p class="uk-text-muted uk-margin-small-top uk-margin-remove-bottom">' . $stateDescription
			. '</p></div><div class="KernClaimDetailActions"><div class="KernClaimState">'
			. $this->badge($status) . '<span>' . $this->claimSource((string)$claim['source'])
			. '</span></div><a class="uk-button uk-button-default" href="' . $this->page->url
			. 'claims/"><span uk-icon="icon: arrow-left; ratio: .8"></span> ' . $this->_('All claims') . '</a></div></section>';
		$out .= '<dl class="KernClaimDetailSummary"><div><dt>' . $this->_('Status') . '</dt><dd>'
			. $this->badge($status) . '</dd></div><div><dt>' . $this->_('Account') . '</dt><dd><strong>'
			. $this->h($user->id ? $user->name : '#' . $claim['user_id']) . '</strong></dd></div><div><dt>'
			. $this->_('Access source') . '</dt><dd>' . $this->claimSource((string)$claim['source']) . '</dd></div><div><dt>'
			. $milestoneLabel . '</dt><dd>' . $this->dateTime($milestone) . '</dd></div></dl>';
		$requestNote = trim((string)($claim['request_note'] ?? ''));
		$reviewNote = trim((string)($claim['review_note'] ?? ''));
		if ($requestNote !== '' || $reviewNote !== '') {
			$out .= '<section class="KernClaimContext" aria-label="' . $this->_('Claim notes') . '">';
			if ($requestNote !== '') {
				$out .= '<div><span class="uk-text-meta">' . $this->_('Requester note') . '</span><p>'
					. nl2br($this->h($requestNote)) . '</p></div>';
			}
			if ($reviewNote !== '') {
				$out .= '<div><span class="uk-text-meta">' . $this->_('Moderator note') . '</span><p>'
					. nl2br($this->h($reviewNote)) . '</p></div>';
			}
			$out .= '</section>';
		}
		$out .= '<div class="KernClaimDetailLayout" uk-grid><div class="uk-width-expand@l"><section class="uk-card uk-card-default uk-card-small KernClaimActivity" id="KernClaimActivity">'
			. '<div class="uk-card-header KernSectionHead"><div><span class="uk-text-meta">' . $this->_('Audit trail')
			. '</span><h2 class="uk-card-title uk-margin-small-top uk-margin-remove-bottom">' . $this->_('Ownership activity')
			. '</h2><p class="uk-text-muted uk-margin-small-top uk-margin-remove-bottom">'
			. $this->_('Access, moderation, and submitted Page changes associated with this claim.')
			. '</p></div><span class="KernSectionCount">' . sprintf($this->_('%d events'), count($history))
			. '</span></div><div class="uk-card-body">' . $this->historyTable($history, false)
			. '</div></section></div><aside class="uk-width-1-3@l KernClaimDetailAside">'
			. $this->claimModerationPanel($claim, $reviewer, count($history)) . '</aside></div></div>';
		return $this->assets() . $out;
	}
	private function claimModerationPanel(array $claim, User $reviewer, int $eventCount): string {
		$status = (string)$claim['status'];
		if ($status === 'pending') {
			return '<form method="post" class="uk-card uk-card-default uk-card-small KernClaimDecisionForm">'
				. $this->session->CSRF->renderInput() . '<div class="uk-card-header"><span class="uk-text-meta">'
				. $this->_('Decision required') . '</span><h2 class="uk-card-title uk-margin-small-top uk-margin-remove-bottom">'
				. $this->_('Review ownership') . '</h2><p class="uk-text-muted uk-margin-small-top uk-margin-remove-bottom">'
				. $this->_('Approval grants managed Page access immediately. Rejection closes this request without changing the Page.')
				. '</p></div><div class="uk-card-body"><label for="KernClaimNote">' . $this->_('Moderator note')
				. '<small>' . $this->_('Add context that should remain in the immutable claim record.') . '</small></label>'
				. '<textarea class="uk-textarea" id="KernClaimNote" name="note" rows="4"></textarea>'
				. '<div class="KernClaimDecisionActions"><button class="uk-button uk-button-primary" type="submit" name="decision" value="approve">'
				. '<span uk-icon="icon: check; ratio: .8"></span> ' . $this->_('Approve access')
				. '</button><button class="uk-button uk-button-default" type="submit" name="decision" value="reject">'
				. $this->_('Reject request') . '</button></div></div></form>';
		}
		if ($status === 'active') {
			return '<section class="uk-card uk-card-default uk-card-small KernClaimDecisionCard"><div class="uk-card-body"><span class="uk-text-meta">'
				. $this->_('Current access') . '</span><div class="KernClaimDecisionState"><span class="KernClaimDecisionIcon" uk-icon="icon: check; ratio: .9"></span><div><h2 class="uk-card-title uk-margin-remove">'
				. $this->_('Ownership active') . '</h2><div class="uk-margin-small-top">' . $this->badge($status)
				. '</div></div></div><p class="uk-text-muted uk-margin-small-top">'
				. $this->_('The account may submit governed Page revisions. Revocation stops future managed access and preserves all recorded activity.')
				. '</p><details class="KernClaimRevoke"><summary class="uk-button uk-button-default uk-width-1-1">'
				. $this->_('Revoke ownership') . '</summary><form method="post" class="KernClaimRevokeConfirm">'
				. $this->session->CSRF->renderInput() . '<strong>' . $this->_('Confirm access removal') . '</strong><p>'
				. $this->_('This takes effect immediately. Existing revisions and the audit trail are retained.')
				. '</p><label for="KernClaimRevokeNote">' . $this->_('Moderator note') . '<small>'
				. $this->_('Explain why access is being revoked.') . '</small></label><textarea class="uk-textarea" id="KernClaimRevokeNote" name="note" rows="3"></textarea>'
				. '<button class="uk-button uk-button-danger uk-width-1-1" type="submit" name="decision" value="revoke">'
				. $this->_('Confirm revoke') . '</button></form></details></div></section>';
		}
		$reviewerName = $reviewer->id ? (string)$reviewer->name : $this->_('System');
		return '<section class="uk-card uk-card-default uk-card-small KernClaimDecisionCard"><div class="uk-card-body"><span class="uk-text-meta">'
			. $this->_('Decision') . '</span><div class="KernClaimDecisionState"><span class="KernClaimDecisionIcon" uk-icon="icon: ban; ratio: .9"></span><div><h2 class="uk-card-title uk-margin-remove">'
			. $this->_('Ownership closed') . '</h2><div class="uk-margin-small-top">' . $this->badge($status)
			. '</div></div></div><p class="uk-text-muted uk-margin-small-top">'
			. sprintf($this->_('Reviewed by %s. No further moderation action is available for this record.'), $this->h($reviewerName))
			. '</p><a class="uk-button uk-button-default uk-width-1-1" href="#KernClaimActivity">'
			. sprintf($this->_('View %d recorded events'), $eventCount) . '</a></div></section>';
	}
	private function claimSource(string $source): string {
		[$label, $icon] = match ($source) {
			'code' => [$this->_('Access code'), 'lock'],
			'request' => [$this->_('Direct request'), 'mail'],
			default => [ucfirst(str_replace('_', ' ', $source)), 'link'],
		};
		return '<span class="KernClaimSource"><span uk-icon="icon: ' . $icon . '; ratio: .75"></span> '
			. $this->h($label) . '</span>';
	}
}

