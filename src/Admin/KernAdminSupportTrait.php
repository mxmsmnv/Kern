<?php namespace ProcessWire;

trait KernAdminSupportTrait {

	private function claims(): Kern {
		/** @var Kern $module */
		$module = $this->modules->get('Kern');
		return $module;
	}
	private function service(): KernService {
		return $this->claims()->service();
	}
	private function requireAny(array $actions, bool $includeAccessAdministrator = false): void {
		if ($this->user->isSuperuser()) return;
		if ($includeAccessAdministrator && $this->user->hasPermission(Kern::PERM_ACCESS)) return;
		foreach ($actions as $action) {
			if ($this->claims()->canAny($action, $this->user)) return;
		}
		throw new WirePermissionException($this->_('You do not have access to this Kern section.'));
	}
	private function requirePageAction(Page $page, string $action): void {
		if (!$page->id || !$this->claims()->can($action, $page, $this->user)) {
			throw new WirePermissionException($this->_('You do not have access to this Page in Kern.'));
		}
	}
	private function filterRowsForAction(array $rows, string $action, bool $allowGlobal = false): array {
		return array_values(array_filter($rows, function(array $row) use ($action, $allowGlobal): bool {
			$pageId = (int)($row['page_id'] ?? 0);
			if (!$pageId) return $allowGlobal && ($this->user->isSuperuser() || $this->user->hasPermission(Kern::PERM_ACCESS));
			$page = $this->pages->get($pageId);
			return $page->id && $this->claims()->can($action, $page, $this->user);
		}));
	}
	private function emptyState(
		string $title,
		string $description,
		string $icon,
		?string $actionUrl = null,
		?string $actionLabel = null
	): string {
		return '<div class="KernEmptyState"><span class="KernEmptyIcon" uk-icon="icon: ' . $this->h($icon)
			. '; ratio: 1.35"></span><h3 class="uk-h4 uk-margin-small-top uk-margin-remove-bottom">'
			. $this->h($title) . '</h3><p class="uk-text-muted uk-margin-small-top">' . $this->h($description) . '</p>'
			. ($actionUrl && $actionLabel ? '<a class="uk-button uk-button-default uk-margin-small-top" href="'
				. $this->h($actionUrl) . '">' . $this->h($actionLabel) . '</a>' : '') . '</div>';
	}
	private function nav(): string {
		$base = $this->page->url;
		$links = [
			'' => [$base, $this->_('Dashboard')],
			'claims' => [$base . 'claims/', $this->_('Claims')],
			'revisions' => [$base . 'revisions/', $this->_('Revisions')],
			'codes' => [$base . 'codes/', $this->_('Access codes')],
			'history' => [$base . 'history/', $this->_('History')],
		];
		if ($this->user->isSuperuser() || $this->user->hasPermission(Kern::PERM_ACCESS)) {
			$links['access'] = [$base . 'access/', $this->_('Access rules')];
		}
		$segment = (string)$this->input->urlSegment1;
		if ($segment === 'claim') $segment = 'claims';
		if ($segment === 'revision') $segment = 'revisions';
		if ($segment === 'access-edit') $segment = 'access';
		$out = '<ul class="uk-subnav uk-subnav-pill KernTabs" aria-label="' . $this->_('Kern sections') . '">';
		foreach ($links as $key => [$url, $label]) {
			$out .= '<li' . ($segment === $key ? ' class="uk-active"' : '') . '><a href="' . $url . '"'
				. ($segment === $key ? ' aria-current="page"' : '') . '>'
				. $this->h($label) . '</a></li>';
		}
		return $out . '</ul>';
	}
	private function filterLinks(string $path, array $items, string $active, array $counts = [], array $query = []): string {
		$out = '<ul class="uk-subnav uk-subnav-divider KernFilters KernFilterNav" aria-label="' . $this->h($this->_('Filter by status')) . '">';
		foreach ($items as $value => $label) {
			$params = $query;
			if ($value !== '') $params['status'] = $value;
			$url = $this->page->url . $path . '/' . ($params ? '?' . http_build_query($params) : '');
			$out .= '<li' . ($value === $active ? ' class="uk-active"' : '') . '><a href="' . $url . '"'
				. ($value === $active ? ' aria-current="page"' : '') . '>'
				. $this->h($label) . (array_key_exists($value, $counts)
					? ' <span class="KernFilterCount">' . (int)$counts[$value] . '</span>'
					: '') . '</a></li>';
		}
		return $out . '</ul>';
	}
	private function definitionList(array $values): string {
		$out = '<dl class="KernDetails">';
		foreach ($values as $label => $value) $out .= '<dt>' . $this->h($label) . '</dt><dd>' . $value . '</dd>';
		return $out . '</dl>';
	}
	private function pageLabel(Page $page): string {
		if (!$page->id) return '<span class="uk-text-muted">' . $this->_('Missing Page') . '</span>';
		$url = $this->config->urls->admin . 'page/edit/?id=' . (int)$page->id;
		return '<a href="' . $url . '">' . $this->h($page->getUnformatted('title') ?: $page->name) . ' <small>#' . (int)$page->id . '</small></a>';
	}
	private function badge(string $status): string {
		$class = 'uk-label KernStatus';
		if (in_array($status, ['active', 'approved'], true)) $class .= ' uk-label-success';
		if (in_array($status, ['pending', 'exhausted', 'expired'], true)) $class .= ' uk-label-warning';
		if (in_array($status, ['rejected', 'revoked', 'failed', 'conflict'], true)) $class .= ' uk-label-danger';
		$label = match ($status) {
			'active' => $this->_('Active'),
			'approved' => $this->_('Approved'),
			'pending' => $this->_('Pending'),
			'rejected' => $this->_('Rejected'),
			'revoked' => $this->_('Revoked'),
			'failed' => $this->_('Failed'),
			'conflict' => $this->_('Conflict'),
			'exhausted' => $this->_('Exhausted'),
			'expired' => $this->_('Expired'),
			default => ucfirst($status),
		};
		return '<span class="' . $class . '" data-status="' . $this->h($status) . '">' . $this->h($label) . '</span>';
	}
	private function date(int $timestamp): string {
		return $timestamp ? $this->h(wireDate('Y-m-d H:i', $timestamp)) : '—';
	}
	private function dateTime(int $timestamp): string {
		if (!$timestamp) return '—';
		return '<time datetime="' . $this->h(wireDate('c', $timestamp)) . '" title="'
			. $this->h(wireDate('Y-m-d H:i', $timestamp)) . '">' . $this->h(wireDate('M j, Y · H:i', $timestamp)) . '</time>';
	}
	private function decode(?string $json): array {
		if (!$json) return [];
		$value = json_decode($json, true);
		return is_array($value) ? $value : [];
	}
	private function pretty($value): string {
		return $this->h((string)json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}
	private function h($value): string {
		return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
	private function assets(): string {
		$this->config->styles->add($this->config->urls->siteModules . 'Kern/assets/css/admin.css?v=' . Kern::VERSION);
		$this->config->scripts->add($this->config->urls->siteModules . 'Kern/assets/js/admin.js?v=' . Kern::VERSION);
		return '';
	}
}

