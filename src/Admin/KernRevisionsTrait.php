<?php namespace ProcessWire;

trait KernRevisionsTrait {

	public function executeRevisions(): string {
		$this->requireAny(['moderate_revisions']);
		$this->headline($this->_('Proposed revisions'));
		$status = $this->sanitizer->name((string)$this->input->get('status'));
		$query = trim($this->sanitizer->text((string)$this->input->get('q')));
		$statuses = [
			'' => $this->_('All'),
			'attention' => $this->_('Needs attention'),
			'pending' => $this->_('Pending'),
			'conflict' => $this->_('Conflict'),
			'failed' => $this->_('Failed'),
			'completed' => $this->_('Completed'),
			'approved' => $this->_('Approved'),
			'rejected' => $this->_('Rejected'),
		];
		if (!array_key_exists($status, $statuses)) $status = '';
		$allRows = $this->filterRowsForAction($this->service()->revisions([], 500), 'moderate_revisions');
		$counts = array_fill_keys(array_keys($statuses), 0);
		$counts[''] = count($allRows);
		foreach ($allRows as $row) {
			$rowStatus = (string)$row['status'];
			if (isset($counts[$rowStatus])) $counts[$rowStatus]++;
		}
		$counts['attention'] = $counts['pending'] + $counts['conflict'] + $counts['failed'];
		$completedCount = $counts['approved'] + $counts['rejected'];
		$counts['completed'] = $completedCount;
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
			$changes = $this->decode($row['changes_json']);
			if ($status === 'attention' && !in_array($row['status'], ['pending', 'conflict', 'failed'], true)) continue;
			if ($status === 'completed' && !in_array($row['status'], ['approved', 'rejected'], true)) continue;
			if ($status !== '' && !in_array($status, ['attention', 'completed'], true) && $row['status'] !== $status) continue;
			if ($query !== '') {
				$fieldTerms = [];
				foreach ($changes as $name => $change) {
					$fieldTerms[] = (string)$name;
					if (is_array($change) && isset($change['label'])) $fieldTerms[] = (string)$change['label'];
				}
				$haystack = implode(' ', [
					(string)$row['id'], (string)$row['status'], implode(' ', $fieldTerms),
					$page->id ? (string)$page->getUnformatted('title') : '',
					$page->id ? (string)$page->name : '',
					$page->id ? (string)$page->path : '',
					$user->id ? (string)$user->name : '',
					$user->id ? (string)$user->email : '',
				]);
				$matches = function_exists('mb_stripos') ? mb_stripos($haystack, $query) !== false : stripos($haystack, $query) !== false;
				if (!$matches) continue;
			}
			$viewRows[] = [
				'row' => $row,
				'page' => $page,
				'user' => $user,
				'changes' => $changes,
				'url' => $this->page->url . 'revision/?id=' . (int)$row['id'],
			];
		}
		if ($status === '') {
			usort($viewRows, static function(array $a, array $b): int {
				$priority = ['conflict' => 0, 'pending' => 1, 'failed' => 2, 'approved' => 3, 'rejected' => 4];
				$aPriority = $priority[(string)$a['row']['status']] ?? 5;
				$bPriority = $priority[(string)$b['row']['status']] ?? 5;
				return $aPriority === $bPriority
					? (int)$b['row']['created'] <=> (int)$a['row']['created']
					: $aPriority <=> $bPriority;
			});
		}
		$viewRows = array_slice($viewRows, 0, 200);
		$sectionTitle = $status === '' ? $this->_('All revisions') : $statuses[$status];
		$sectionDescription = match ($status) {
			'attention' => $this->_('Pending decisions, conflicts, and failed applications that require an operator.'),
			'completed' => $this->_('Approved and rejected proposals retained as a readable audit record.'),
			'pending' => $this->_('Compare each proposal with the current Page and approve or reject it.'),
			'conflict' => $this->_('The Page changed after submission. Resolve the conflict before applying a proposal.'),
			'approved' => $this->_('Proposals that were successfully applied to their Pages.'),
			'rejected' => $this->_('Proposals reviewed and declined by a moderator.'),
			'failed' => $this->_('Applications that did not complete and require inspection.'),
			default => $this->_('Open work appears first; completed revisions remain available for audit.'),
		};

		$out = '<div class="KernWorkspace KernRevisionsWorkspace pw-module-workspace">' . $this->nav();
		$out .= '<section class="KernRevisionsIntro"><div><span class="uk-text-meta">' . $this->_('Content moderation')
			. '</span><h2 class="uk-h3 uk-margin-small-top uk-margin-remove-bottom">' . $this->_('Review proposed Page changes')
			. '</h2><p class="uk-text-muted uk-margin-small-top">'
			. $this->_('Compare submitted values with the live Page, resolve conflicts, and retain every decision in the audit trail.')
			. '</p></div><a class="KernRevisionQueueState" data-state="' . ($counts['attention'] ? 'attention' : 'clear')
			. '" href="' . $this->page->url . 'revisions/?status=attention"><span class="KernRevisionQueueDot"></span><span><strong>'
			. ($counts['attention'] ? sprintf($this->_('%d revisions need attention'), $counts['attention']) : $this->_('Review queue is clear'))
			. '</strong><small>' . sprintf($this->_('%d conflicts · %d failed'), $counts['conflict'], $counts['failed'])
			. '</small></span></a></section>';
		$out .= '<div class="KernRevisionSummary" aria-label="' . $this->h($this->_('Revision summary')) . '">';
		foreach ([
			[$this->_('Pending'), $counts['pending'], $this->_('Needs a decision'), 'revisions/?status=pending'],
			[$this->_('Conflicts'), $counts['conflict'], $this->_('Needs resolution'), 'revisions/?status=conflict'],
			[$this->_('Completed'), $completedCount, $this->_('Approved or rejected'), 'revisions/?status=completed'],
			[$this->_('Recorded'), $counts[''], $this->_('Complete audit set'), 'revisions/'],
		] as [$label, $value, $note, $path]) {
			$out .= '<a href="' . $this->page->url . $path . '"><span>' . $this->h($label) . '</span><strong>'
				. (int)$value . '</strong><small>' . $this->h($note) . '</small></a>';
		}
		$out .= '</div><section class="KernRevisionPanel"><div class="KernWorkspaceHead"><div><span class="uk-text-meta">'
			. $this->_('Revision register') . '</span><h2 class="uk-h3 uk-margin-small-top uk-margin-remove-bottom">'
			. $this->h($sectionTitle) . '</h2><p class="uk-text-muted uk-margin-small-top">'
			. $this->h($sectionDescription) . '</p></div><div class="KernQueueSummary"><strong>' . count($viewRows) . '</strong><span>'
			. $this->_('revisions shown') . '</span></div></div>';
		$out .= '<form class="KernRevisionSearch" method="get" action="' . $this->page->url . 'revisions/">'
			. ($status !== '' ? '<input type="hidden" name="status" value="' . $this->h($status) . '">' : '')
			. '<label class="uk-form-label" for="KernRevisionSearch">' . $this->_('Search proposed revisions') . '</label><div>'
			. '<input class="uk-input" id="KernRevisionSearch" name="q" type="search" value="' . $this->h($query)
			. '" placeholder="' . $this->h($this->_('Page, account, email, changed field, or revision ID')) . '">'
			. '<button class="uk-button uk-button-primary" type="submit"><span uk-icon="icon: search"></span> '
			. $this->_('Search') . '</button>'
			. ($query !== '' ? '<a class="uk-button uk-button-default" href="' . $this->page->url . 'revisions/'
				. ($status !== '' ? '?status=' . rawurlencode($status) : '') . '">' . $this->_('Clear') . '</a>' : '')
			. '</div></form>'
			. $this->filterLinks('revisions', $statuses, $status, $counts, $query !== '' ? ['q' => $query] : []);
		if (!$viewRows) {
			$out .= $this->emptyState(
				$query !== '' ? $this->_('No revisions match this search') : ($status === '' ? $this->_('No revisions yet') : $this->_('No revisions with this status')),
				$query !== '' ? $this->_('Try a Page title, account, email address, changed field, or revision number.')
					: ($status === '' ? $this->_('Proposed Page changes will appear here when an account submits its first revision.')
						: $this->_('There are no accessible revisions matching the selected status.')),
				$status === '' ? 'file-edit' : 'check',
				$status === '' && $query === '' ? null : $this->page->url . 'revisions/',
				$status === '' && $query === '' ? null : $this->_('View all revisions')
			);
			return $this->assets() . $out . '</section></div>';
		}

		$out .= '<div class="uk-overflow-auto KernTablePanel KernRevisionTable"><table class="AdminDataTable AdminDataList uk-table uk-table-divider uk-table-hover uk-table-middle uk-table-small">';
		$out .= '<thead><tr><th class="uk-table-shrink">' . $this->_('Revision') . '</th><th>' . $this->_('Page')
			. '</th><th>' . $this->_('Account') . '</th><th>' . $this->_('Changes') . '</th><th>'
			. $this->_('Status') . '</th><th>' . $this->_('Submitted') . '</th><th class="uk-text-right">'
			. $this->_('Action') . '</th></tr></thead><tbody>';
		foreach ($viewRows as ['row' => $row, 'page' => $page, 'user' => $user, 'changes' => $changes, 'url' => $url]) {
			$isDecision = in_array($row['status'], ['pending', 'conflict'], true);
			$action = $row['status'] === 'failed' ? $this->_('Inspect failure') : ($isDecision ? $this->_('Review revision') : $this->_('View revision'));
			$out .= '<tr><td><a class="uk-link-heading" href="' . $url . '"><strong>#' . (int)$row['id'] . '</strong></a></td><td>'
				. $this->pageLabel($page) . '</td><td>' . $this->h($user->id ? $user->name : '#' . $row['user_id'])
				. '</td><td>' . $this->revisionFields($changes) . '</td><td>' . $this->badge($row['status'])
				. '</td><td>' . $this->dateTime((int)$row['created']) . '</td><td class="uk-text-right"><a class="uk-button '
				. ($isDecision ? 'uk-button-primary' : 'uk-button-default') . ' uk-button-small" href="'
				. $url . '">' . $action . '</a></td></tr>';
		}
		$out .= '</tbody></table></div><div class="KernRevisionCards">';
		foreach ($viewRows as ['row' => $row, 'page' => $page, 'user' => $user, 'changes' => $changes, 'url' => $url]) {
			$isDecision = in_array($row['status'], ['pending', 'conflict'], true);
			$action = $row['status'] === 'failed' ? $this->_('Inspect failure') : ($isDecision ? $this->_('Review revision') : $this->_('View revision'));
			$out .= '<article class="uk-card uk-card-default uk-card-small KernRevisionCard"><div class="uk-card-header KernRevisionCardHead"><div><span class="uk-text-meta">'
				. sprintf($this->_('Revision #%d'), (int)$row['id']) . '</span><h3 class="uk-card-title uk-margin-small-top uk-margin-remove-bottom">'
				. $this->pageLabel($page) . '</h3></div>' . $this->badge($row['status']) . '</div><div class="uk-card-body">'
				. '<dl class="KernRevisionMeta"><div><dt>' . $this->_('Account') . '</dt><dd>'
				. $this->h($user->id ? $user->name : '#' . $row['user_id']) . '</dd></div><div><dt>'
				. $this->_('Submitted') . '</dt><dd>' . $this->dateTime((int)$row['created']) . '</dd></div></dl><div class="KernRevisionChanges"><span class="uk-text-meta">'
				. $this->_('Changed fields') . '</span>' . $this->revisionFields($changes) . '</div></div><div class="uk-card-footer"><a class="uk-button '
				. ($isDecision ? 'uk-button-primary' : 'uk-button-default') . ' uk-width-1-1" href="'
				. $url . '">' . $action . '</a></div></article>';
		}
		$out .= '</div></section></div>';
		return $this->assets() . $out;
	}
	public function executeRevision(): string {
		$id = (int)$this->input->get('id');
		$revision = $this->service()->revisionById($id);
		if (!$revision) throw new Wire404Exception();
		$this->requirePageAction($this->pages->get((int)$revision['page_id']), 'moderate_revisions');
		if ($this->input->requestMethod('POST')) {
			$this->session->CSRF->validate();
			$decision = $this->sanitizer->name((string)$this->input->post('decision'));
			$note = (string)$this->input->post('note');
			if ($decision === 'approve') {
				$this->service()->approveRevision($id, $this->user, $note, false);
			} elseif ($decision === 'force') {
				$this->service()->approveRevision($id, $this->user, $note, true);
			} elseif ($decision === 'reject') {
				$this->service()->rejectRevision($id, $this->user, $note);
			} else {
				throw new WireException('Unknown moderation decision.');
			}
			$this->message($this->_('Revision updated.'));
			$this->session->redirect($this->page->url . 'revision/?id=' . $id);
		}
		$page = $this->pages->get((int)$revision['page_id']);
		$user = $this->users->get((int)$revision['user_id']);
		$changes = $this->decode($revision['changes_json']);
		$history = $this->service()->history(['revision_id' => $id], 100);
		$this->headline(sprintf($this->_('Revision #%d'), $id));
		$fieldCount = count($changes);
		$isAwaitingDecision = in_array((string)$revision['status'], ['pending', 'conflict'], true);
		$out = '<div class="KernWorkspace pw-module-workspace">' . $this->nav();
		$out .= '<section class="KernRevisionDetailIntro"><div><span class="uk-text-meta">'
			. ($isAwaitingDecision ? $this->_('Moderation workspace') : $this->_('Revision record'))
			. '</span><h2 class="uk-margin-small-top uk-margin-remove-bottom">' . $this->pageLabel($page)
			. '</h2><p class="uk-text-muted uk-margin-small-top uk-margin-remove-bottom">'
			. ($isAwaitingDecision
				? $this->_('Review every proposed value before approving or rejecting this Page update.')
				: $this->_('Inspect the submitted values and the immutable decision trail for this completed revision.'))
			. '</p></div><div class="KernRevisionDetailActions"><div class="KernRevisionState">'
			. $this->badge((string)$revision['status']) . '<span>'
			. sprintf($this->_('%d fields changed'), $fieldCount) . '</span></div><a class="uk-button uk-button-default" href="'
			. $this->page->url . 'revisions/"><span uk-icon="icon: arrow-left; ratio: .8"></span> '
			. $this->_('All revisions') . '</a></div></section>';
		$out .= '<dl class="KernRevisionDetailSummary"><div><dt>' . $this->_('Status') . '</dt><dd>'
			. $this->badge((string)$revision['status']) . '</dd></div><div><dt>' . $this->_('Account') . '</dt><dd><strong>'
			. $this->h($user->id ? $user->name : '#' . $revision['user_id']) . '</strong></dd></div><div><dt>'
			. $this->_('Submitted') . '</dt><dd>' . $this->dateTime((int)$revision['created']) . '</dd></div><div><dt>'
			. $this->_('Activity') . '</dt><dd><strong>' . count($history) . '</strong> '
			. $this->_('recorded events') . '</dd></div></dl>';
		if ((string)$revision['note'] !== '' || (string)$revision['error_message'] !== '') {
			$out .= '<section class="KernRevisionContext" aria-label="' . $this->_('Revision context') . '">';
			if ((string)$revision['note'] !== '') {
				$out .= '<div class="KernRevisionNote"><span class="uk-text-meta">' . $this->_('Submitter note')
					. '</span><p>' . nl2br($this->h($revision['note'])) . '</p></div>';
			}
			if ((string)$revision['error_message'] !== '') {
				$out .= '<div class="uk-alert-danger KernConflictNotice" uk-alert><h3 class="uk-h4">'
					. $this->_('Conflict or apply error') . '</h3><p>' . nl2br($this->h($revision['error_message'])) . '</p></div>';
			}
			$out .= '</section>';
		}
		$out .= '<div class="KernReviewLayout" uk-grid><div class="uk-width-expand@l KernReviewMain"><section class="KernChangesSection" id="KernProposedChanges">'
			. '<div class="KernSectionHead"><div><span class="uk-text-meta">' . $this->_('Field review')
			. '</span><h2 class="uk-h3 uk-margin-small-top uk-margin-remove-bottom">' . $this->_('Current and proposed values')
			. '</h2><p class="uk-text-muted uk-margin-small-top">' . $this->_('The highlighted pane is the value submitted by the account.')
			. '</p></div><span class="KernSectionCount">' . sprintf($this->_('%d changes'), $fieldCount) . '</span></div>';
		$changeIndex = 0;
		foreach ($changes as $name => $change) {
			$changeIndex++;
			$change = is_array($change) ? $change : [];
			$beforeSummary = (string)($change['summary_before'] ?? '');
			$afterSummary = (string)($change['summary_after'] ?? '');
			$before = is_array($change['before'] ?? null) ? $change['before'] : ['value' => null];
			$after = is_array($change['after'] ?? null) ? $change['after'] : ['value' => null];
			$out .= '<details class="uk-card uk-card-default uk-card-small KernChange" open><summary class="uk-card-header"><span class="KernChangeTitle"><span class="KernChangeNumber">'
				. $changeIndex . '</span><span><strong>' . $this->h($change['label'] ?? $name) . '</strong><code>'
				. $this->h($name) . '</code></span></span><span class="KernChangeToggle" aria-hidden="true"></span></summary>'
				. '<div class="uk-card-body"><div class="KernCompare"><section class="KernComparePane KernCompareBefore"><span class="KernCompareLabel">'
				. $this->_('Current Page value') . '</span>' . $this->revisionValue($before, $beforeSummary)
				. $this->rawValue($before['value'] ?? null) . '</section><section class="KernComparePane KernCompareProposed"><span class="KernCompareLabel">'
				. $this->_('Submitted replacement') . '</span>' . $this->revisionValue($after, $afterSummary)
				. $this->rawValue($after['value'] ?? null) . '</section></div></div></details>';
		}
		$out .= '</section><details class="uk-card uk-card-default uk-card-small KernHistoryCard" id="KernRevisionActivity"><summary class="uk-card-header KernRevisionHistorySummary"><span><strong>'
			. $this->_('Revision activity') . '</strong><small>' . $this->_('Immutable submission and decision events')
			. '</small></span><span class="KernSectionCount">' . sprintf($this->_('%d events'), count($history))
			. '</span></summary><div class="uk-card-body">' . $this->historyTable($history, false) . '</div></details></div><aside class="uk-width-1-3@l KernReviewAside">';
		if ($isAwaitingDecision) {
			$out .= $this->revisionModerationForm($revision, $fieldCount);
		} else {
			$out .= '<section class="uk-card uk-card-default uk-card-small KernDecisionCard"><div class="uk-card-body"><span class="uk-text-meta">'
				. $this->_('Decision') . '</span><div class="KernDecisionState"><span class="KernDecisionStateIcon" uk-icon="icon: check; ratio: .9"></span><div><h2 class="uk-card-title uk-margin-remove">'
				. $this->_('Review complete') . '</h2><div class="uk-margin-small-top">' . $this->badge((string)$revision['status'])
				. '</div></div></div><p class="uk-text-muted uk-margin-small-top">'
				. $this->_('No action is required. The activity log records how this revision was resolved.')
				. '</p><a class="uk-button uk-button-default uk-width-1-1" href="#KernRevisionActivity" data-kern-open-details="KernRevisionActivity">'
				. $this->_('View decision trail') . '</a></div></section>';
		}
		$out .= '</aside></div></div>';
		return $this->assets() . $out;
	}
	private function revisionModerationForm(array $revision, int $fieldCount): string {
		$isConflict = (string)$revision['status'] === 'conflict';
		$submitterNote = trim((string)($revision['note'] ?? ''));
		$out = '<form method="post" class="uk-card uk-card-default uk-card-small KernReviewForm">'
			. $this->session->CSRF->renderInput() . '<div class="uk-card-header"><h2 class="uk-card-title uk-margin-remove">'
			. $this->_('Review decision') . '</h2><p class="uk-text-meta uk-margin-small-top">'
			. ($isConflict
				? $this->_('Re-check the revision after reviewing every conflict.')
				: sprintf($this->_('Approval applies %d changed fields to the live Page.'), $fieldCount))
			. '</p></div><div class="uk-card-body"><div class="uk-alert-warning KernDecisionImpact" id="KernDecisionImpact" uk-alert><strong>'
			. $this->_('Approval publishes immediately') . '</strong><p>'
			. sprintf($this->_('Review all %d fields before applying this proposal.'), $fieldCount)
			. ' <a href="#KernProposedChanges">' . $this->_('Review proposed changes') . '</a></p></div>';
		if ($submitterNote !== '') {
			$out .= '<div class="KernDecisionSubmitterNote"><span class="uk-text-meta">' . $this->_('Submitter note')
				. '</span><p>' . nl2br($this->h($submitterNote)) . '</p></div>';
		}
		$out .= '<label for="KernNote">' . $this->_('Moderator note')
			. '<small>' . $this->_('Optional, but recommended when rejecting or overriding a conflict.') . '</small></label>'
			. '<textarea class="uk-textarea" id="KernNote" name="note" rows="4"></textarea><div class="KernDecisionActions">'
			. '<button class="uk-button uk-button-primary" type="submit" name="decision" value="approve" aria-describedby="KernDecisionImpact"><span uk-icon="icon: check; ratio: .8"></span> '
			. ($isConflict ? $this->_('Re-check and apply') : $this->_('Approve and apply')) . '</button>'
			. '<button class="uk-button uk-button-default" type="submit" name="decision" value="reject"><span uk-icon="icon: close; ratio: .8"></span> '
			. $this->_('Reject revision') . '</button></div>';
		if ($isConflict) {
			$out .= '<details class="KernForceDecision"><summary>' . $this->_('Conflict override') . '</summary><div class="uk-alert-danger" uk-alert><p>'
				. $this->_('Force apply overwrites the conflicting live values. Use it only after comparing every field above.')
				. '</p><button class="uk-button uk-button-danger uk-width-1-1" type="submit" name="decision" value="force">'
				. $this->_('Force apply conflicting values') . '</button></div></details>';
		}
		return $out . '</div></form>';
	}
	private function revisionValue(array $descriptor, string $summary): string {
		$value = $descriptor['value'] ?? null;
		if ($value === null || $value === '' || $value === []) {
			return '<div class="KernCompareValue is-empty">' . $this->_('Empty') . '</div>';
		}
		$fieldtype = (string)($descriptor['fieldtype'] ?? '');
		$adapter = (string)($descriptor['adapter'] ?? '');
		if ($fieldtype === 'FieldtypePage') return $this->revisionPageReferences($value);
		if ($adapter === 'profields-table' && is_array($value)) return $this->revisionTableRows($value);
		if (is_scalar($value)) return '<div class="KernCompareValue">' . $this->revisionScalar($value) . '</div>';
		return '<div class="KernCompareValue KernCompareSummary">'
			. $this->h($summary !== '' ? $summary : $this->_('Structured value')) . '</div>';
	}
	private function revisionPageReferences($value): string {
		$items = is_array($value) && array_key_exists('_page', $value) ? [$value] : (is_array($value) ? $value : [$value]);
		$out = '<ul class="KernReferenceList">';
		$rendered = 0;
		foreach (array_slice($items, 0, 50) as $item) {
			$id = is_array($item) ? (int)($item['_page'] ?? 0) : (int)$item;
			if ($id < 1) continue;
			$page = $this->pages->get($id);
			$out .= '<li>';
			if ($page->id) {
				$url = $this->config->urls->admin . 'page/edit/?id=' . $id;
				$out .= '<a href="' . $url . '"><strong>' . $this->h($page->getUnformatted('title') ?: $page->name)
					. '</strong></a><span>' . $this->h($page->path) . ' · #' . $id . '</span>';
			} else {
				$out .= '<strong>' . sprintf($this->_('Missing Page #%d'), $id) . '</strong>';
			}
			$out .= '</li>';
			$rendered++;
		}
		if (!$rendered) return '<div class="KernCompareValue is-empty">' . $this->_('Empty') . '</div>';
		if (count($items) > 50) $out .= '<li class="uk-text-muted">' . sprintf($this->_('%d more references'), count($items) - 50) . '</li>';
		return $out . '</ul>';
	}
	private function revisionTableRows(array $rows): string {
		if (!$rows) return '<div class="KernCompareValue is-empty">' . $this->_('No rows') . '</div>';
		$out = '<div class="KernStructuredRows">';
		foreach (array_slice($rows, 0, 20) as $index => $row) {
			if (!is_array($row)) continue;
			$out .= '<article class="KernStructuredRow"><strong class="KernStructuredRowTitle">'
				. sprintf($this->_('Row %d'), $index + 1) . '</strong><dl>';
			$columns = 0;
			foreach ($row as $name => $cell) {
				if ((string)$name === 'id' || str_starts_with((string)$name, '_') || ++$columns > 12) continue;
				$label = ucfirst(str_replace(['_', '-'], ' ', (string)$name));
				$out .= '<div><dt>' . $this->h($label) . '</dt><dd>'
					. (is_scalar($cell) ? $this->revisionScalar($cell, true) : $this->h($this->_('Structured value')))
					. '</dd></div>';
			}
			$out .= '</dl></article>';
		}
		if (count($rows) > 20) $out .= '<p class="uk-text-muted uk-text-small">' . sprintf($this->_('%d more rows are available in the raw value.'), count($rows) - 20) . '</p>';
		return $out . '</div>';
	}
	private function revisionScalar($value, bool $compact = false): string {
		if (is_bool($value)) return $value ? $this->_('Yes') : $this->_('No');
		$text = (string)$value;
		if (filter_var($text, FILTER_VALIDATE_URL) && in_array(parse_url($text, PHP_URL_SCHEME), ['http', 'https'], true)) {
			return '<a href="' . $this->h($text) . '" target="_blank" rel="noopener noreferrer">' . $this->h($text)
				. ' <span uk-icon="icon: link-external; ratio: .7"></span></a>';
		}
		$text = preg_replace('~</(?:p|div|li|h[1-6])>~i', "\n", $text) ?? $text;
		$text = preg_replace('~<br\s*/?>~i', "\n", $text) ?? $text;
		$text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = trim(preg_replace("/[ \t]+/", ' ', preg_replace("/\n{3,}/", "\n\n", $text) ?? $text) ?? $text);
		if ($compact) $text = mb_strimwidth($text, 0, 240, '…');
		return nl2br($this->h($text));
	}
	private function rawValue($value): string {
		return '<details class="KernRawValue"><summary>' . $this->_('View raw value') . '</summary><pre>'
			. $this->pretty($value) . '</pre></details>';
	}
	private function revisionFields(array $changes): string {
		if (!$changes) return '<span class="uk-text-muted">' . $this->h($this->_('No field data')) . '</span>';
		$out = '<span class="KernFieldList">';
		foreach ($changes as $name => $change) {
			$label = is_array($change) && !empty($change['label']) ? (string)$change['label'] : (string)$name;
			$out .= '<span class="uk-label KernFieldLabel" title="' . $this->h($name) . '">' . $this->h($label) . '</span>';
		}
		return $out . '</span>';
	}
}

