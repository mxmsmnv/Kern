<?php namespace ProcessWire;

trait KernAccessTrait {

	public function executeAccess(): string {
		$this->requireAccessAdministrator();
		if ($this->input->requestMethod('POST')) {
			$this->session->CSRF->validate();
			if ($this->sanitizer->name((string)$this->input->post('action')) === 'delete') {
				$this->claims()->deleteAccessRule((int)$this->input->post('id'), $this->user);
				$this->message($this->_('Access rule deleted.'));
				$this->session->redirect($this->page->url . 'access/');
			}
		}

		$this->headline($this->_('Access rules'));
		$status = $this->sanitizer->name((string)$this->input->get('status'));
		$query = trim($this->sanitizer->text((string)$this->input->get('q')));
		$statuses = ['' => $this->_('All'), 'enabled' => $this->_('Enabled'), 'disabled' => $this->_('Disabled')];
		if (!array_key_exists($status, $statuses)) $status = '';
		$allRules = $this->claims()->accessRules();
		$counts = ['' => count($allRules), 'enabled' => 0, 'disabled' => 0];
		$protected = 0;
		foreach ($allRules as $rule) {
			$counts[$rule['enabled'] ? 'enabled' : 'disabled']++;
			if ($rule['denies'] || $rule['denied_fields']) $protected++;
		}
		$rules = $status === '' ? $allRules : array_values(array_filter(
			$allRules,
			fn(array $rule): bool => $status === 'enabled' ? (bool)$rule['enabled'] : !(bool)$rule['enabled']
		));
		if ($query !== '') {
			$rules = array_values(array_filter($rules, function(array $rule) use ($query): bool {
				$haystack = implode(' ', [
					(string)$rule['id'], (string)$rule['name'], (string)$rule['priority'],
					$rule['enabled'] ? 'enabled' : 'disabled',
					$this->templateNames($rule['templates']),
					$this->audienceLabel($rule),
					(string)json_encode([
						$rule['fields'], $rule['denied_fields'], $rule['grants'], $rule['denies'], $rule['settings'],
					], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
				]);
				return function_exists('mb_stripos') ? mb_stripos($haystack, $query) !== false : stripos($haystack, $query) !== false;
			}));
		}
		usort($rules, static function(array $a, array $b): int {
			if ((bool)$a['enabled'] !== (bool)$b['enabled']) return (bool)$a['enabled'] ? -1 : 1;
			$priority = (int)$b['priority'] <=> (int)$a['priority'];
			return $priority !== 0 ? $priority : strcasecmp((string)$a['name'], (string)$b['name']);
		});
		$sectionTitle = $status === '' ? $this->_('All policy rules') : sprintf($this->_('%s rules'), $statuses[$status]);
		$sectionDescription = match ($status) {
			'enabled' => $this->_('Rules currently participating in access-policy evaluation.'),
			'disabled' => $this->_('Inactive rules retained for review or later reuse.'),
			default => $this->_('Enabled rules lead the register, followed by disabled policy records.'),
		};

		$out = '<div class="KernWorkspace KernAccessWorkspace pw-module-workspace">' . $this->nav();
		$out .= '<section class="KernAccessIntro"><div><span class="uk-text-meta">' . $this->_('Delegated access policy')
			. '</span><h2 class="uk-h3 uk-margin-small-top uk-margin-remove-bottom">' . $this->_('Control who can change what')
			. '</h2><p class="uk-text-muted uk-margin-small-top">'
			. $this->_('Scope Pages and fields, select audiences, and grant or deny actions through explicit rules.')
			. '</p></div><div class="KernAccessIntroAside"><div class="KernAccessQueueState" data-state="'
			. ($counts['enabled'] ? 'active' : 'attention') . '"><span class="KernAccessQueueDot"></span><span><strong>'
			. ($counts['enabled'] ? sprintf($this->_('%d enabled access rules'), $counts['enabled']) : $this->_('Explicit policy not configured'))
			. '</strong><small>' . ($allRules
				? sprintf($this->_('%d rules recorded'), $counts[''])
				: $this->_('Module defaults remain active'))
			. '</small></span></div><a class="uk-button uk-button-primary" href="'
			. $this->page->url . 'access-edit/"><span uk-icon="icon: plus"></span> ' . $this->_('Add access rule')
			. '</a></div></section>';
		$out .= '<div class="uk-alert-primary KernPolicyNotice" uk-alert><span uk-icon="icon: shield"></span><div><strong>'
			. $this->_('Deny always wins') . '</strong><p>'
			. $this->_('Rules are evaluated by priority. Matching grants accumulate, but a matching deny always overrides them.')
			. '</p></div></div>';
		if ($allRules) {
			$out .= '<div class="KernAccessSummary" aria-label="' . $this->h($this->_('Access policy summary')) . '">';
			foreach ([
				[$this->_('Enabled'), $counts['enabled'], $this->_('Evaluated now')],
				[$this->_('Protected'), $protected, $this->_('Rules with denies')],
				[$this->_('Disabled'), $counts['disabled'], $this->_('Not evaluated')],
				[$this->_('Recorded'), $counts[''], $this->_('Complete policy set')],
			] as [$label, $value, $note]) {
				$out .= '<div><span>' . $this->h($label) . '</span><strong>' . (int)$value
					. '</strong><small>' . $this->h($note) . '</small></div>';
			}
			$out .= '</div>';
		}
		$out .= '<section class="KernAccessPanel"><div class="KernWorkspaceHead"><div><span class="uk-text-meta">'
			. $this->_('Policy register') . '</span><h2 class="uk-h3 uk-margin-small-top uk-margin-remove-bottom">'
			. $this->h($sectionTitle) . '</h2><p class="uk-text-muted uk-margin-small-top">'
			. $this->h($sectionDescription) . ' ' . $this->_('Higher priority is evaluated first; it never overrides a deny.')
			. '</p></div><div class="KernQueueSummary"><strong>' . count($rules) . '</strong><span>'
			. $this->_('rules shown') . '</span></div></div>';
		if ($allRules) {
			$out .= '<form class="KernAccessSearch" method="get" action="' . $this->page->url . 'access/">'
				. ($status !== '' ? '<input type="hidden" name="status" value="' . $this->h($status) . '">' : '')
				. '<label class="uk-form-label" for="KernAccessSearch">' . $this->_('Search access rules') . '</label><div>'
				. '<input class="uk-input" id="KernAccessSearch" name="q" type="search" value="' . $this->h($query)
				. '" placeholder="' . $this->h($this->_('Name, Page type, audience, field, action, or ID')) . '">'
				. '<button class="uk-button uk-button-primary" type="submit"><span uk-icon="icon: search"></span> '
				. $this->_('Search') . '</button>'
				. ($query !== '' ? '<a class="uk-button uk-button-default" href="' . $this->page->url . 'access/'
					. ($status !== '' ? '?status=' . rawurlencode($status) : '') . '">' . $this->_('Clear') . '</a>' : '')
				. '</div></form>' . $this->filterLinks('access', $statuses, $status, $counts, $query !== '' ? ['q' => $query] : []);
		}
		if (!$rules) {
			$out .= $this->emptyState(
				$query !== '' ? $this->_('No rules match this search') : ($allRules ? $this->_('No rules with this status') : $this->_('Create the first explicit access rule')),
				$query !== '' ? $this->_('Try a rule name, Page type, audience, field, action, or rule ID.')
					: ($allRules ? $this->_('Choose another status to review the configured policy.')
						: $this->_('Module defaults remain active until a rule defines Page scope, audience, fields, and allowed actions.')),
				$allRules ? 'check' : 'shield',
				$allRules ? $this->page->url . 'access/' : $this->page->url . 'access-edit/',
				$allRules ? $this->_('View all rules') : $this->_('Create access rule')
			);
			return $this->assets() . $out . '</section></div>';
		}

		$out .= '<div class="uk-overflow-auto KernTablePanel KernAccessTable"><table class="AdminDataTable AdminDataList uk-table uk-table-divider uk-table-hover uk-table-middle uk-table-small"><thead><tr><th>'
			. $this->_('Rule') . '</th><th>' . $this->_('Scope') . '</th><th>' . $this->_('Audience')
			. '</th><th>' . $this->_('Permissions') . '</th><th>' . $this->_('Priority') . '</th><th>'
			. $this->_('Status') . '</th><th class="uk-text-right">' . $this->_('Actions') . '</th></tr></thead><tbody>';
		foreach ($rules as $rule) {
			$url = $this->page->url . 'access-edit/?id=' . (int)$rule['id'];
			$out .= '<tr' . (!$rule['enabled'] ? ' class="uk-text-muted"' : '') . '><td><a href="' . $url . '"><strong>'
				. $this->h($rule['name']) . '</strong></a><div class="uk-text-meta">#' . (int)$rule['id'] . '</div></td><td>'
				. $this->accessScope($rule) . '</td><td>' . $this->h($this->audienceLabel($rule)) . '</td><td>'
				. $this->accessPermissions($rule) . '</td><td><strong>' . (int)$rule['priority'] . '</strong></td><td>'
				. $this->accessRuleStatus((bool)$rule['enabled']) . '</td><td class="uk-text-right"><div class="KernAccessActions">'
				. '<a class="uk-button uk-button-default uk-button-small" href="' . $url . '">' . $this->_('Edit') . '</a>'
				. $this->accessRuleDeleteControl((int)$rule['id']) . '</div></td></tr>';
		}
		$out .= '</tbody></table></div><div class="KernAccessCards">';
		foreach ($rules as $rule) {
			$url = $this->page->url . 'access-edit/?id=' . (int)$rule['id'];
			$out .= '<article class="uk-card uk-card-default uk-card-small KernAccessCard"><div class="uk-card-header KernAccessCardHead"><div><span class="uk-text-meta">'
				. sprintf($this->_('Priority %d'), (int)$rule['priority']) . '</span><h3 class="uk-card-title uk-margin-small-top uk-margin-remove-bottom">'
				. $this->h($rule['name']) . '</h3></div>' . $this->accessRuleStatus((bool)$rule['enabled'])
				. '</div><div class="uk-card-body"><dl class="KernAccessMeta"><div><dt>' . $this->_('Scope') . '</dt><dd>'
				. $this->accessScope($rule) . '</dd></div><div><dt>' . $this->_('Audience') . '</dt><dd>'
				. $this->h($this->audienceLabel($rule)) . '</dd></div><div><dt>' . $this->_('Permissions') . '</dt><dd>'
				. $this->accessPermissions($rule) . '</dd></div></dl></div><div class="uk-card-footer KernAccessCardActions">'
				. '<a class="uk-button uk-button-default uk-button-small" href="' . $url . '">' . $this->_('Edit rule') . '</a>'
				. $this->accessRuleDeleteControl((int)$rule['id'], true) . '</div></article>';
		}
		return $this->assets() . $out . '</div></section></div>';
	}
	public function executeAccessEdit(): string {
		$this->requireAccessAdministrator();
		$id = (int)$this->input->get('id');
		$rule = $id ? $this->claims()->accessPolicy()->rule($id) : null;
		if ($id && !$rule) throw new Wire404Exception();
		$rule = $rule ?: [
			'id' => 0, 'name' => '', 'enabled' => true, 'priority' => 0,
			'templates' => [], 'fields' => [], 'denied_fields' => [], 'roles' => [], 'users' => [],
			'audiences' => ['claimants'], 'grants' => ['edit'], 'denies' => [],
			'settings' => ['claim_moderation' => 'inherit', 'revision_moderation' => 'inherit', 'code_expires_days' => 0],
		];
		$form = $this->accessRuleForm($rule);
		if ($this->input->requestMethod('POST') && $form->process()) {
			$saved = $this->claims()->saveAccessRule([
				'id' => (int)$this->formValue($form, 'id'),
				'name' => (string)$this->formValue($form, 'name'),
				'enabled' => (bool)$this->formValue($form, 'enabled'),
				'priority' => (int)$this->formValue($form, 'priority'),
				'templates' => (array)$this->formValue($form, 'templates'),
				'fields' => (array)$this->formValue($form, 'fields'),
				'denied_fields' => (array)$this->formValue($form, 'denied_fields'),
				'roles' => (array)$this->formValue($form, 'roles'),
				'users' => (array)$this->formValue($form, 'users'),
				'audiences' => (array)$this->formValue($form, 'audiences'),
				'grants' => (array)$this->formValue($form, 'grants'),
				'denies' => (array)$this->formValue($form, 'denies'),
				'settings' => [
					'claim_moderation' => (string)$this->formValue($form, 'claim_moderation'),
					'revision_moderation' => (string)$this->formValue($form, 'revision_moderation'),
					'code_expires_days' => (int)$this->formValue($form, 'code_expires_days'),
				],
			], $this->user);
			$this->message($this->_('Access rule saved.'));
			$this->session->redirect($this->page->url . 'access-edit/?id=' . (int)$saved['id']);
		}

		$this->headline($rule['id'] ? $this->_('Edit access rule') : $this->_('Create access rule'));
		$out = '<div class="KernWorkspace pw-module-workspace KernAccessEditWorkspace">' . $this->nav();
		$out .= '<section class="KernAccessEditIntro"><div><span class="uk-text-meta">'
			. ($rule['id'] ? sprintf($this->_('Access rule #%d'), (int)$rule['id']) : $this->_('New policy rule'))
			. '</span><h2 class="uk-h3 uk-margin-small-top uk-margin-remove-bottom">'
			. ($rule['id'] ? $this->_('Update delegated access') : $this->_('Build an explicit access policy'))
			. '</h2><p class="uk-text-muted uk-margin-small-top">'
			. $this->_('Define the Page scope, audience, actions, and optional workflow behavior in five ordered sections.')
			. '</p></div><div class="KernWorkspaceActions"><a class="uk-button uk-button-default" href="'
			. $this->page->url . 'access/"><span uk-icon="icon: arrow-left"></span> '
			. $this->_('Back to access rules') . '</a></div></section>';
		$out .= $this->accessRuleSummary($rule) . '<div class="KernAccessRuleNotices">';
		if (!$rule['templates'] && !$rule['fields']) {
			$out .= '<div class="uk-alert-warning KernAccessRuleBreadth" uk-alert><span uk-icon="icon: warning"></span><div><strong>'
				. $this->_('Broad Page scope') . '</strong><p>'
				. $this->_('No templates or allowed fields are selected, so this rule can match every non-system Page and every safe field. Narrow the scope unless that is intentional.')
				. '</p></div></div>';
		}
		$out .= '<div class="uk-alert-primary KernAccessRuleGuidance" uk-alert><span uk-icon="icon: shield"></span><div><strong>'
			. $this->_('How matching works') . '</strong><p>'
			. $this->_('Scope and audience decide when the rule applies. Matching grants accumulate; denied fields and actions always win, regardless of priority.')
			. '</p></div></div></div><section class="KernAccessRuleBuilder"><div class="KernWorkspaceHead"><div><span class="uk-text-meta">'
			. $this->_('Rule builder') . '</span><h2 class="uk-h3 uk-margin-small-top uk-margin-remove-bottom">'
			. $this->_('Configure access rule') . '</h2><p class="uk-text-muted uk-margin-small-top">'
			. $this->_('Complete the required sections, review optional workflow overrides, then save the rule.')
			. '</p></div></div>' . $form->render() . '</section></div>';
		return $this->assets() . $out;
	}
	private function accessRuleForm(array $rule): InputfieldForm {
		/** @var InputfieldForm $form */
		$form = $this->modules->get('InputfieldForm');
		$form->attr('action', './' . ($rule['id'] ? '?id=' . (int)$rule['id'] : ''));
		$form->addClass('InputfieldFormConfirm KernAccessRuleForm');

		$sections = [];
		foreach ([
			'identity' => [$this->_('1. Rule essentials'), $this->_('Name the rule, choose whether it is active, and set its evaluation priority.'), 'sliders', Inputfield::collapsedNo],
			'scope' => [$this->_('2. Page and field scope'), $this->_('Limit which Page templates and editable fields this rule can reach.'), 'sitemap', Inputfield::collapsedNo],
			'audience' => [$this->_('3. Audience'), $this->_('The rule applies when any selected role, user, or built-in audience matches.'), 'users', Inputfield::collapsedNo],
			'permissions' => [$this->_('4. Actions'), $this->_('Grant only the capabilities this audience needs. Explicit denies always win.'), 'lock', Inputfield::collapsedNo],
			'workflow' => [$this->_('5. Workflow overrides'), $this->_('Optional rule-specific moderation and access-code settings. Leave defaults to inherit global configuration.'), 'cogs', Inputfield::collapsedYes],
		] as $name => [$label, $description, $icon, $collapsed]) {
			/** @var InputfieldFieldset $section */
			$section = $this->modules->get('InputfieldFieldset');
			$section->attr('name', 'kern_access_' . $name);
			$section->label = $label;
			$section->description = $description;
			$section->icon = $icon;
			$section->collapsed = $collapsed;
			$form->add($section);
			$sections[$name] = $section;
		}

		/** @var InputfieldHidden $field */
		$field = $this->modules->get('InputfieldHidden');
		$field->attr('name', 'id');
		$field->val((int)$rule['id']);
		$form->add($field);

		/** @var InputfieldText $field */
		$field = $this->modules->get('InputfieldText');
		$field->attr('name', 'name');
		$field->label = $this->_('Rule name');
		$field->description = $this->_('Use a name that explains the audience and purpose.');
		$field->attr('placeholder', $this->_('Example: Product owners — catalog updates'));
		$field->required = true;
		$field->maxlength = 255;
		$field->columnWidth = 60;
		$field->val((string)$rule['name']);
		$sections['identity']->add($field);

		/** @var InputfieldInteger $field */
		$field = $this->modules->get('InputfieldInteger');
		$field->attr('name', 'priority');
		$field->label = $this->_('Priority');
		$field->description = $this->_('Higher numbers are evaluated first. Start at 0; priority never overrides a deny.');
		$field->min = -32768;
		$field->max = 32767;
		$field->columnWidth = 20;
		$field->val((int)$rule['priority']);
		$sections['identity']->add($field);

		/** @var InputfieldCheckbox $field */
		$field = $this->modules->get('InputfieldCheckbox');
		$field->attr('name', 'enabled');
		$field->label = $this->_('Enabled');
		$field->description = $this->_('Disabled rules remain saved but are not evaluated.');
		$field->columnWidth = 20;
		$field->val(1);
		if ($rule['enabled']) $field->attr('checked', 'checked');
		$sections['identity']->add($field);

		$settings = (array)$rule['settings'];
		$templateOptions = [];
		foreach ($this->templates as $template) {
			if ($template->flags & Template::flagSystem) continue;
			$templateOptions[(int)$template->id] = (string)($template->label ?: $template->name) . ' (' . $template->name . ')';
		}
		$fieldOptions = [];
		foreach ($this->fields as $field) {
			if (in_array($field->name, $this->claims()->alwaysExcludedFields(), true)) continue;
			$fieldOptions[$field->name] = (string)($field->label ?: $field->name) . ' (' . $field->name . ')';
		}
		$roleOptions = [];
		foreach ($this->roles as $role) {
			if ($role->name === 'guest') continue;
			$roleOptions[(int)$role->id] = (string)($role->title ?: $role->name);
		}
		$userOptions = [];
		foreach ($this->users->find('include=all, sort=name') as $account) {
			if ($account->isGuest()) continue;
			$label = (string)$account->name;
			if ($account->email) $label .= ' — ' . (string)$account->email;
			$userOptions[(int)$account->id] = $label;
		}
		$actionOptions = [
			'request_claim' => $this->_('Request ownership/delegation'),
			'edit' => $this->_('Submit field changes'),
			'auto_approve' => $this->_('Apply submitted changes without moderation'),
			'issue_codes' => $this->_('Issue and revoke quick-access codes'),
			'moderate_claims' => $this->_('Moderate claims'),
			'moderate_revisions' => $this->_('Moderate revisions'),
			'view_history' => $this->_('View audit history'),
		];

		foreach ([
			'templates' => ['scope', $this->_('Page templates'), $templateOptions, $rule['templates'], $this->_('Select the Page types this rule may reach. Empty means every non-system template.'), 'sitemap', 100],
			'fields' => ['scope', $this->_('Allowed fields'), $fieldOptions, $rule['fields'], $this->_('Select editable fields, or leave empty for every safe field except explicit denies.'), 'check-square-o', 50],
			'denied_fields' => ['scope', $this->_('Denied fields'), $fieldOptions, $rule['denied_fields'], $this->_('Denied fields always override allowed fields.'), 'ban', 50],
			'roles' => ['audience', $this->_('Roles / groups'), $roleOptions, $rule['roles'], $this->_('Any matching role activates this rule.'), 'users', 50],
			'users' => ['audience', $this->_('Specific users'), $userOptions, $rule['users'], $this->_('Any selected user activates this rule.'), 'user', 50],
			'audiences' => ['audience', $this->_('Built-in audiences'), [
				'authenticated' => $this->_('Every authenticated user'),
				'claimants' => $this->_('Users with an active claim/access code for the Page'),
			], $rule['audiences'], $this->_('Use roles and users when the rule must not apply broadly.'), 'id-card-o', 100],
			'grants' => ['permissions', $this->_('Allow actions'), $actionOptions, $rule['grants'], $this->_('Select only actions this audience should be able to perform.'), 'unlock-alt', 50],
			'denies' => ['permissions', $this->_('Deny actions'), $actionOptions, $rule['denies'], $this->_('Deny always wins when multiple rules match.'), 'lock', 50],
		] as $name => [$sectionName, $label, $options, $value, $description, $icon, $width]) {
			$input = $this->asmSelect($name, $label, $options, (array)$value, $description, $icon);
			$input->columnWidth = $width;
			$sections[$sectionName]->add($input);
		}

		foreach ([
			'claim_moderation' => $this->_('Claim moderation'),
			'revision_moderation' => $this->_('Revision moderation'),
		] as $name => $label) {
			/** @var InputfieldSelect $field */
			$field = $this->modules->get('InputfieldSelect');
			$field->attr('name', $name);
			$field->label = $label;
			$field->columnWidth = 33;
			foreach (['inherit' => $this->_('Use global setting'), 'required' => $this->_('Required'), 'automatic' => $this->_('Automatic approval')] as $value => $option) {
				$field->addOption($value, $option);
			}
			$field->val((string)($settings[$name] ?? 'inherit'));
			$sections['workflow']->add($field);
		}

		/** @var InputfieldInteger $field */
		$field = $this->modules->get('InputfieldInteger');
		$field->attr('name', 'code_expires_days');
		$field->label = $this->_('Access-code lifetime (days)');
		$field->description = $this->_('0 uses the global setting.');
		$field->min = 0;
		$field->max = 3650;
		$field->columnWidth = 34;
		$field->val((int)($settings['code_expires_days'] ?? 0));
		$sections['workflow']->add($field);

		/** @var InputfieldSubmit $field */
		$field = $this->modules->get('InputfieldSubmit');
		$field->attr('name', 'submit_save_rule');
		$field->val($this->_('Save access rule'));
		$field->icon = 'save';
		$field->appendMarkup = ' <a class="uk-button uk-button-default" href="' . $this->page->url . 'access/">'
			. $this->_('Back') . '</a>';
		$form->add($field);
		return $form;
	}
	private function asmSelect(
		string $name,
		string $label,
		array $options,
		array $value,
		string $description = '',
		string $icon = ''
	): InputfieldAsmSelect {
		/** @var InputfieldAsmSelect $field */
		$field = $this->modules->get('InputfieldAsmSelect');
		$field->attr('name', $name);
		$field->label = $label;
		$field->description = $description;
		$field->icon = $icon;
		$field->sortable = false;
		foreach ($options as $optionValue => $optionLabel) $field->addOption($optionValue, $optionLabel);
		$field->val($value);
		return $field;
	}
	private function formValue(InputfieldForm $form, string $name) {
		$field = $form->getChildByName($name);
		return $field ? $field->val() : null;
	}
	private function templateNames(array $ids): string {
		if (!$ids) return $this->_('All non-system templates');
		$names = [];
		foreach ($ids as $id) {
			$template = $this->templates->get((int)$id);
			$names[] = $template->id ? (string)$template->name : '#' . (int)$id;
		}
		return implode(', ', $names);
	}
	private function audienceLabel(array $rule): string {
		$parts = [];
		foreach ($rule['audiences'] as $audience) {
			$parts[] = $audience === 'authenticated' ? $this->_('Authenticated users') : $this->_('Active claimants');
		}
		if ($rule['roles']) {
			$names = [];
			foreach ($rule['roles'] as $id) {
				$role = $this->roles->get((int)$id);
				$names[] = $role->id ? (string)($role->title ?: $role->name) : '#' . (int)$id;
			}
			$parts[] = $this->_('Roles:') . ' ' . implode(', ', $names);
		}
		if ($rule['users']) {
			$names = [];
			foreach ($rule['users'] as $id) {
				$user = $this->users->get((int)$id);
				$names[] = $user->id ? (string)$user->name : '#' . (int)$id;
			}
			$parts[] = $this->_('Users:') . ' ' . implode(', ', $names);
		}
		return $parts ? implode('; ', $parts) : $this->_('Nobody');
	}
	private function accessRuleSummary(array $rule): string {
		$scope = $rule['templates']
			? sprintf($this->_('%d Page types'), count($rule['templates']))
			: $this->_('All Page types');
		$fields = $this->accessFieldScopeLabel($rule);
		$grants = sprintf($this->_('%d allowed actions'), count($rule['grants']));
		$denies = $rule['denies']
			? sprintf($this->_('%d explicit denies'), count($rule['denies']))
			: $this->_('No action denies');
		$out = '<div class="KernAccessRuleSummary" aria-label="' . $this->h($this->_('Current rule summary')) . '">';
		foreach ([
			[$this->_('Page scope'), $scope, $fields],
			[$this->_('Audience'), $this->audienceLabel($rule), sprintf($this->_('%d direct roles or users'), count($rule['roles']) + count($rule['users']))],
			[$this->_('Actions'), $grants, $denies],
			[$this->_('Evaluation'), $rule['enabled'] ? $this->_('Enabled') : $this->_('Disabled'), sprintf($this->_('Priority %d'), (int)$rule['priority'])],
		] as [$label, $value, $note]) {
			$out .= '<div><span>' . $this->h($label) . '</span><strong>' . $this->h($value)
				. '</strong><small>' . $this->h($note) . '</small></div>';
		}
		return $out . '</div>';
	}
	private function accessScope(array $rule): string {
		$fields = $this->accessFieldScopeLabel($rule);
		return '<span class="KernAccessScope"><strong>' . $this->h($this->templateNames($rule['templates']))
			. '</strong><span class="uk-text-meta">' . $this->h($fields) . '</span></span>';
	}
	private function accessFieldScopeLabel(array $rule): string {
		$canEdit = in_array('edit', $rule['grants'], true) && !in_array('edit', $rule['denies'], true);
		if (!$canEdit) return $this->_('Fields not applicable');
		$fields = $rule['fields']
			? sprintf($this->_('%d allowed fields'), count($rule['fields']))
			: $this->_('All safe fields');
		if ($rule['denied_fields']) $fields .= ' · ' . sprintf($this->_('%d denied'), count($rule['denied_fields']));
		return $fields;
	}
	private function accessPermissions(array $rule): string {
		$labels = [
			'request_claim' => $this->_('Request ownership'),
			'edit' => $this->_('Submit changes'),
			'auto_approve' => $this->_('Auto-approve'),
			'issue_codes' => $this->_('Issue codes'),
			'moderate_claims' => $this->_('Moderate claims'),
			'moderate_revisions' => $this->_('Moderate revisions'),
			'view_history' => $this->_('View history'),
		];
		$out = '<span class="KernPermissionList">';
		foreach ($rule['grants'] as $action) $out .= '<span class="uk-label KernPermissionAllow">+ ' . $this->h($labels[$action] ?? $action) . '</span>';
		foreach ($rule['denies'] as $action) $out .= '<span class="uk-label uk-label-danger KernPermissionDeny">− ' . $this->h($labels[$action] ?? $action) . '</span>';
		return $out . (!$rule['grants'] && !$rule['denies'] ? '<span class="uk-text-muted">' . $this->_('No actions') . '</span>' : '') . '</span>';
	}
	private function accessRuleStatus(bool $enabled): string {
		return '<span class="uk-label KernStatus ' . ($enabled ? 'uk-label-success' : '') . '" data-status="'
			. ($enabled ? 'enabled' : 'disabled') . '">' . ($enabled ? $this->_('Enabled') : $this->_('Disabled')) . '</span>';
	}
	private function accessRuleDeleteControl(int $id, bool $fullWidth = false): string {
		return '<details class="KernDeleteControl' . ($fullWidth ? ' is-full' : '') . '"><summary class="uk-button uk-button-default uk-button-small">'
			. $this->_('Delete') . '</summary><div class="KernDeleteConfirm"><p>'
			. $this->_('This rule will be removed permanently. Its audit history will remain.') . '</p><form method="post">'
			. $this->session->CSRF->renderInput() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="'
			. $id . '"><button class="uk-button uk-button-danger uk-button-small" type="submit">'
			. $this->_('Confirm delete') . '</button></form></div></details>';
	}
	private function requireAccessAdministrator(): void {
		if (!$this->user->isSuperuser() && !$this->user->hasPermission(Kern::PERM_ACCESS)) {
			throw new WirePermissionException($this->_('You may not manage Kern access rules.'));
		}
	}
}

