<?php namespace ProcessWire;

trait KernCodesTrait {

	public function executeCodes(): string {
		$this->requireAny(['issue_codes']);
		$this->headline($this->_('Quick-access codes'));
		$revealed = $this->session->getFor($this, 'revealedCode');
		if ($revealed) $this->session->removeFor($this, 'revealedCode');
		$form = $this->codeForm();
		if ($this->input->requestMethod('POST')) {
			$action = $this->sanitizer->name((string)$this->input->post('action'));
			if ($action === 'create' && $form->process()) {
				$pageValue = $this->formValue($form, 'page_id');
				$pageId = (int)(is_array($pageValue) ? reset($pageValue) : $pageValue);
				$page = $this->pages->get($pageId);
				if (!$page->id) {
					$form->getChildByName('page_id')->error($this->_('Select a valid Page.'));
				} else {
					try {
						$code = $this->claims()->generateAccessCode($page, [
							'expires_days' => (int)$this->formValue($form, 'expires_days'),
							'max_uses' => (int)$this->formValue($form, 'max_uses'),
							'label' => (string)$this->formValue($form, 'label'),
						], $this->user);
						$this->session->setFor($this, 'revealedCode', $code);
						$this->session->redirect($this->page->url . 'codes/');
					} catch (WireException $e) {
						$form->getChildByName('page_id')->error($e->getMessage());
					}
				}
			}
			if ($action === 'revoke') {
				$this->session->CSRF->validate();
				$this->service()->revokeCode((int)$this->input->post('id'), $this->user);
				$this->message($this->_('Access code revoked.'));
				$this->session->redirect($this->page->url . 'codes/');
			}
		}

		$statuses = [
			'' => $this->_('All'),
			'active' => $this->_('Active'),
			'closed' => $this->_('Closed'),
			'exhausted' => $this->_('Exhausted'),
			'expired' => $this->_('Expired'),
			'revoked' => $this->_('Revoked'),
		];
		$status = $this->sanitizer->name((string)$this->input->get('status'));
		$query = trim($this->sanitizer->text((string)$this->input->get('q')));
		if (!array_key_exists($status, $statuses)) $status = '';
		$allRows = $this->filterRowsForAction($this->service()->codes([], 500), 'issue_codes');
		$counts = array_fill_keys(array_keys($statuses), 0);
		$counts[''] = count($allRows);
		$remainingUses = 0;
		foreach ($allRows as $row) {
			$rowStatus = (string)$row['status'];
			if (isset($counts[$rowStatus])) $counts[$rowStatus]++;
			if ($rowStatus === 'active') $remainingUses += max(0, (int)$row['max_uses'] - (int)$row['uses']);
		}
		$counts['closed'] = $counts['exhausted'] + $counts['expired'] + $counts['revoked'];
		$pages = [];
		$creators = [];
		$viewRows = [];
		foreach ($allRows as $row) {
			$pageId = (int)$row['page_id'];
			$creatorId = (int)$row['created_by'];
			if (!isset($pages[$pageId])) $pages[$pageId] = $this->pages->get($pageId);
			if (!isset($creators[$creatorId])) $creators[$creatorId] = $this->users->get($creatorId);
			$page = $pages[$pageId];
			$creator = $creators[$creatorId];
			$meta = $this->decode($row['meta_json']);
			if ($status === 'closed' && !in_array($row['status'], ['exhausted', 'expired', 'revoked'], true)) continue;
			if ($status !== '' && $status !== 'closed' && $row['status'] !== $status) continue;
			if ($query !== '') {
				$haystack = implode(' ', [
					(string)$row['id'], (string)$row['status'], (string)$row['code_hint'], (string)($meta['label'] ?? ''),
					$page->id ? (string)$page->getUnformatted('title') : '',
					$page->id ? (string)$page->name : '',
					$page->id ? (string)$page->path : '',
					$creator->id ? (string)$creator->name : '',
					$creator->id ? (string)$creator->email : '',
				]);
				$matches = function_exists('mb_stripos') ? mb_stripos($haystack, $query) !== false : stripos($haystack, $query) !== false;
				if (!$matches) continue;
			}
			$viewRows[] = [
				'row' => $row,
				'page' => $page,
				'creator' => $creator,
				'meta' => $meta,
			];
		}
		if ($status === '') {
			usort($viewRows, static function(array $a, array $b): int {
				$priority = ['active' => 0, 'exhausted' => 1, 'expired' => 2, 'revoked' => 3];
				$aPriority = $priority[(string)$a['row']['status']] ?? 4;
				$bPriority = $priority[(string)$b['row']['status']] ?? 4;
				return $aPriority === $bPriority
					? (int)$b['row']['created'] <=> (int)$a['row']['created']
					: $aPriority <=> $bPriority;
			});
		}
		$viewRows = array_slice($viewRows, 0, 200);
		$sectionTitle = $status === '' ? $this->_('All issued codes') : sprintf($this->_('%s codes'), $statuses[$status]);
		$sectionDescription = match ($status) {
			'active' => $this->_('Codes that can still grant access. Revoke any code that should no longer be used.'),
			'closed' => $this->_('Exhausted, expired, and revoked codes retained for audit.'),
			'exhausted' => $this->_('Codes that reached their successful redemption limit.'),
			'expired' => $this->_('Codes that passed their configured expiry time.'),
			'revoked' => $this->_('Codes explicitly disabled before any remaining redemption could occur.'),
			default => $this->_('Active delegated access appears first; closed codes remain available for audit.'),
		};

		$out = '<div class="KernWorkspace KernCodesWorkspace pw-module-workspace">' . $this->nav();
		$out .= '<section class="KernCodesIntro"><div><span class="uk-text-meta">' . $this->_('Delegated access')
			. '</span><h2 class="uk-h3 uk-margin-small-top uk-margin-remove-bottom">' . $this->_('Issue controlled Page access')
			. '</h2><p class="uk-text-muted uk-margin-small-top">'
			. $this->_('Create short-lived codes, limit redemption capacity, and revoke unused access without disclosing stored secrets.')
			. '</p></div><a class="KernCodeQueueState" data-state="' . ($counts['active'] ? 'active' : 'idle')
			. '" href="' . $this->page->url . 'codes/?status=active"><span class="KernCodeQueueDot"></span><span><strong>'
			. ($counts['active'] ? sprintf($this->_('%d active access codes'), $counts['active']) : $this->_('No active access codes'))
			. '</strong><small>' . sprintf($this->_('%d redemptions available'), $remainingUses) . '</small></span></a></section>';
		if ($revealed) {
			$out .= '<section class="uk-alert-primary KernCodeReveal" uk-alert><div><h2 class="uk-h3 uk-margin-remove">'
				. $this->_('Access code created') . '</h2><p class="uk-margin-small-top">'
				. $this->_('Copy and share it through a secure channel now. Kern stores only a hash, so the full code cannot be shown again.')
				. '</p></div><code>' . $this->h($revealed['code']) . '</code></section>';
		}
		$out .= '<div class="KernCodeSummary" aria-label="' . $this->h($this->_('Access code summary')) . '">';
		foreach ([
			[$this->_('Active'), $counts['active'], $this->_('Can be redeemed'), 'codes/?status=active'],
			[$this->_('Capacity'), $remainingUses, $this->_('Redemptions left'), 'codes/?status=active'],
			[$this->_('Closed'), $counts['closed'], $this->_('Unavailable codes'), 'codes/?status=closed'],
			[$this->_('Recorded'), $counts[''], $this->_('Complete audit set'), 'codes/'],
		] as [$label, $value, $note, $path]) {
			$out .= '<a href="' . $this->page->url . $path . '"><span>' . $this->h($label) . '</span><strong>'
				. (int)$value . '</strong><small>' . $this->h($note) . '</small></a>';
		}
		$out .= '</div><div class="KernCodesLayout" uk-grid><aside class="uk-width-1-3@l KernCodeFormAside"><section class="uk-card uk-card-default uk-card-small KernCodeFormCard"><div class="uk-card-header"><span class="uk-text-meta">'
			. $this->_('Secure handoff') . '</span><h2 class="uk-card-title uk-margin-small-top uk-margin-remove-bottom">'
			. $this->_('Create access code') . '</h2><p class="uk-text-meta uk-margin-small-top">'
			. $this->_('Choose a Page and limit how long and how often the code can be redeemed.')
			. '</p></div><div class="uk-card-body">' . $form->render() . '</div></section></aside><div class="uk-width-expand@l KernCodesMain"><div class="KernWorkspaceHead"><div><span class="uk-text-meta">'
			. $this->_('Access code register') . '</span><h2 class="uk-h3 uk-margin-small-top uk-margin-remove-bottom">'
			. $this->h($sectionTitle) . '</h2><p class="uk-text-muted uk-margin-small-top">'
			. $this->h($sectionDescription) . ' ' . $this->_('Only the final four characters remain visible after creation.')
			. '</p></div><div class="KernQueueSummary"><strong>' . count($viewRows) . '</strong><span>'
			. $this->_('codes shown') . '</span></div></div>'
			. '<form class="KernCodeSearch" method="get" action="' . $this->page->url . 'codes/">'
			. ($status !== '' ? '<input type="hidden" name="status" value="' . $this->h($status) . '">' : '')
			. '<label class="uk-form-label" for="KernCodeSearch">' . $this->_('Search issued codes') . '</label><div>'
			. '<input class="uk-input" id="KernCodeSearch" name="q" type="search" value="' . $this->h($query)
			. '" placeholder="' . $this->h($this->_('Label, Page, creator, hint, or code ID')) . '">'
			. '<button class="uk-button uk-button-primary" type="submit"><span uk-icon="icon: search"></span> '
			. $this->_('Search') . '</button>'
			. ($query !== '' ? '<a class="uk-button uk-button-default" href="' . $this->page->url . 'codes/'
				. ($status !== '' ? '?status=' . rawurlencode($status) : '') . '">' . $this->_('Clear') . '</a>' : '')
			. '</div></form>'
			. $this->filterLinks('codes', $statuses, $status, $counts, $query !== '' ? ['q' => $query] : []);
		if (!$viewRows) {
			$out .= $this->emptyState(
				$query !== '' ? $this->_('No codes match this search') : ($status === '' ? $this->_('No access codes yet') : $this->_('No codes with this status')),
				$query !== '' ? $this->_('Try a label, Page title, creator, code hint, or code number.')
					: ($status === '' ? $this->_('Create the first code to delegate Page access.') : $this->_('There are no accessible codes matching the selected status.')),
				$status === '' ? 'lock' : 'check',
				$status === '' && $query === '' ? null : $this->page->url . 'codes/',
				$status === '' && $query === '' ? null : $this->_('View all codes')
			);
		} else {
			$out .= '<div class="uk-overflow-auto KernTablePanel KernCodeTable"><table class="AdminDataTable AdminDataList uk-table uk-table-divider uk-table-hover uk-table-middle uk-table-small"><thead><tr><th>'
				. $this->_('Code') . '</th><th>' . $this->_('Page') . '</th><th>' . $this->_('Hint') . '</th><th>'
				. $this->_('Usage') . '</th><th>' . $this->_('Expires') . '</th><th>' . $this->_('Status')
				. '</th><th class="uk-text-right">' . $this->_('Action') . '</th></tr></thead><tbody>';
			foreach ($viewRows as ['row' => $row, 'page' => $page, 'creator' => $creator, 'meta' => $meta]) {
				$label = trim((string)($meta['label'] ?? ''));
				$out .= '<tr><td><strong>' . $this->h($label !== '' ? $label : sprintf($this->_('Code #%d'), (int)$row['id']))
					. '</strong><div class="uk-text-small uk-text-muted">' . $this->dateTime((int)$row['created']) . ' · '
					. $this->h($creator->id ? $creator->name : '#' . (int)$row['created_by']) . '</div></td><td>'
					. $this->pageLabel($page) . '</td><td><code>' . $this->h($row['code_hint']) . '</code></td><td>'
					. $this->codeUsage($row) . '</td><td>' . ((int)$row['expires'] ? $this->dateTime((int)$row['expires']) : $this->_('Never'))
					. '</td><td>' . $this->badge($row['status']) . '</td><td class="uk-text-right">'
					. ($row['status'] === 'active' ? $this->revokeCodeControl((int)$row['id']) : '<span class="uk-text-muted">—</span>') . '</td></tr>';
			}
			$out .= '</tbody></table></div><div class="KernCodeCards">';
			foreach ($viewRows as ['row' => $row, 'page' => $page, 'creator' => $creator, 'meta' => $meta]) {
				$label = trim((string)($meta['label'] ?? ''));
				$out .= '<article class="uk-card uk-card-default uk-card-small KernCodeCard"><div class="uk-card-header KernCodeCardHead"><div><span class="uk-text-meta">'
					. sprintf($this->_('Code #%d'), (int)$row['id']) . '</span><h3 class="uk-card-title uk-margin-small-top uk-margin-remove-bottom">'
					. ($label !== '' ? $this->h($label) : $this->pageLabel($page)) . '</h3></div>' . $this->badge($row['status'])
					. '</div><div class="uk-card-body"><dl class="KernCodeMeta"><div><dt>' . $this->_('Page') . '</dt><dd>'
					. $this->pageLabel($page) . '</dd></div><div><dt>' . $this->_('Hint') . '</dt><dd><code>'
					. $this->h($row['code_hint']) . '</code></dd></div><div><dt>' . $this->_('Usage') . '</dt><dd>'
					. $this->codeUsage($row) . '</dd></div><div><dt>' . $this->_('Expires') . '</dt><dd>'
					. ((int)$row['expires'] ? $this->dateTime((int)$row['expires']) : $this->_('Never'))
					. '</dd></div></dl><p class="uk-text-small uk-text-muted uk-margin-remove-bottom">' . $this->_('Created') . ' '
					. $this->dateTime((int)$row['created']) . ' · ' . $this->h($creator->id ? $creator->name : '#' . (int)$row['created_by'])
					. '</p></div>' . ($row['status'] === 'active' ? '<div class="uk-card-footer">' . $this->revokeCodeControl((int)$row['id'], true) . '</div>' : '') . '</article>';
			}
			$out .= '</div>';
		}
		$out .= '</div></div></div>';
		return $this->assets() . $out;
	}
	private function codeForm(): InputfieldForm {
		/** @var InputfieldForm $form */
		$form = $this->modules->get('InputfieldForm');
		$form->attr('action', './');
		$form->attr('id', 'KernCodeCreateForm');
		$form->addClass('KernCodeCreateForm');

		/** @var InputfieldMarkup $field */
		$field = $this->modules->get('InputfieldMarkup');
		$field->attr('name', 'code_one_time_notice');
		$field->skipLabel = Inputfield::skipLabelHeader;
		$field->markupText = '<div class="uk-alert-primary KernCodeSafetyNotice" uk-alert><strong>'
			. $this->_('The full code is shown only once') . '</strong><p>'
			. $this->_('Have a secure delivery channel ready and copy the code before leaving the result screen. Kern stores only a hash.')
			. '</p></div>';
		$form->add($field);

		/** @var InputfieldHidden $field */
		$field = $this->modules->get('InputfieldHidden');
		$field->attr('name', 'action');
		$field->val('create');
		$form->add($field);

		/** @var InputfieldPageAutocomplete $field */
		$field = $this->modules->get('InputfieldPageAutocomplete');
		$field->attr('name', 'page_id');
		$field->label = $this->_('Page');
		$field->description = $this->_('Search by Page title or name. Only Pages allowed by the current Kern policy appear.');
		$field->notes = $this->claims()->accessRules(true)
			? $this->_('Page availability currently follows enabled Access rules.')
			: $this->_('No enabled Access rules exist, so Page availability currently follows the legacy module defaults.');
		$field->icon = 'file-o';
		$field->required = true;
		$field->prependMarkup = '<label class="KernVisuallyHidden" for="Inputfield_page_id_input">'
			. $this->h($this->_('Search Pages')) . '</label>';
		$field->searchFields = 'title name';
		$field->labelFieldFormat = '{title} — {path}';
		$field->findPagesSelector = $this->codePageSelector();
		$field->maxSelectedItems = 1;
		$field->useList = false;
		$form->add($field);

		/** @var InputfieldInteger $field */
		$field = $this->modules->get('InputfieldInteger');
		$field->attr('name', 'expires_days');
		$field->label = $this->_('Valid for (days)');
		$field->description = $this->_('Days after generation. Keep the access window as short as practical.');
		$field->icon = 'calendar';
		$field->required = true;
		$field->min = 1;
		$field->max = 3650;
		$field->columnWidth = 50;
		$field->val($this->claims()->codeExpiresDays());
		$form->add($field);

		/** @var InputfieldInteger $field */
		$field = $this->modules->get('InputfieldInteger');
		$field->attr('name', 'max_uses');
		$field->label = $this->_('Redemption limit');
		$field->description = $this->_('Maximum successful redemptions. Use 1 for one recipient.');
		$field->icon = 'repeat';
		$field->required = true;
		$field->min = 1;
		$field->max = 1000;
		$field->columnWidth = 50;
		$field->val(1);
		$form->add($field);

		/** @var InputfieldText $field */
		$field = $this->modules->get('InputfieldText');
		$field->attr('name', 'label');
		$field->label = $this->_('Label for your records');
		$field->description = $this->_('Optional. Identify the recipient, team, or purpose in Issued codes.');
		$field->notes = $this->_('The label is never included in the generated code.');
		$field->icon = 'tag';
		$field->maxlength = 200;
		$form->add($field);

		/** @var InputfieldSubmit $field */
		$field = $this->modules->get('InputfieldSubmit');
		$field->attr('name', 'submit_generate_code');
		$field->val($this->_('Generate access code'));
		$field->icon = 'lock';
		$form->add($field);
		return $form;
	}
	private function codePageSelector(): string {
		$rules = $this->claims()->accessRules(true);
		$templateIds = [];
		$allTemplates = false;
		if ($rules) {
			foreach ($rules as $rule) {
				if (empty($rule['templates'])) {
					$allTemplates = true;
					break;
				}
				foreach ((array)$rule['templates'] as $id) $templateIds[(int)$id] = true;
			}
		} else {
			foreach ((array)$this->claims()->claimable_templates as $id) $templateIds[(int)$id] = true;
			$allTemplates = !$templateIds;
		}
		$adminRootPageId = (int)$this->config->adminRootPageID;
		$selector = 'include=all, status<trash, template!=admin';
		if ($adminRootPageId > 0) {
			$selector .= ', id!=' . $adminRootPageId . ', has_parent!=' . $adminRootPageId;
		}
		if (!$allTemplates && $templateIds) $selector .= ', template=' . implode('|', array_keys($templateIds));
		return $selector;
	}
	private function codeUsage(array $row): string {
		$uses = max(0, (int)$row['uses']);
		$maximum = max(1, (int)$row['max_uses']);
		return '<span class="KernCodeUsage"><span>' . $uses . ' / ' . $maximum . '</span><progress class="uk-progress" value="'
			. min($uses, $maximum) . '" max="' . $maximum . '"></progress></span>';
	}
	private function revokeCodeControl(int $id, bool $fullWidth = false): string {
		return '<details class="KernRevokeControl' . ($fullWidth ? ' is-full' : '') . '"><summary class="uk-button uk-button-default uk-button-small">'
			. $this->_('Revoke') . '</summary><div class="KernRevokeConfirm"><p>'
			. $this->_('This code will stop working immediately.') . '</p><form method="post">'
			. $this->session->CSRF->renderInput() . '<input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="'
			. $id . '"><button class="uk-button uk-button-danger uk-button-small" type="submit">'
			. $this->_('Confirm revoke') . '</button></form></div></details>';
	}
}

