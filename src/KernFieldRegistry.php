<?php namespace ProcessWire;

final class KernFieldRegistry extends Wire {

	/** @var KernFieldAdapter[] */
	private array $adapters = [];

	public function __construct() {
		parent::__construct();
		$repeater = new KernRepeaterAdapter();
		$this->register(new KernRapidAdapter());
		$this->register(new KernComboAdapter());
		$this->register(new KernTableAdapter());
		$this->register($repeater);
		$this->register(new KernNativeAdapter());
		$repeater->setRegistry($this);
	}

	public function register(KernFieldAdapter $adapter, bool $prepend = false): self {
		$wire = $this->wire();
		if ($adapter instanceof Wire && $wire instanceof ProcessWire) $adapter->setWire($wire);
		if ($prepend) {
			array_unshift($this->adapters, $adapter);
		} else {
			$this->adapters[] = $adapter;
		}
		return $this;
	}

	public function setWire(ProcessWire $wire) {
		parent::setWire($wire);
		foreach ($this->adapters as $adapter) {
			if ($adapter instanceof Wire) $adapter->setWire($wire);
		}
	}

	public function adapterFor(Field $field): KernFieldAdapter {
		foreach ($this->adapters as $adapter) {
			if ($adapter->supports($field)) return $adapter;
		}
		throw new WireException("No Kern adapter supports field {$field->name}.");
	}

	public function exportField(Page $page, Field $field): array {
		$adapter = $this->adapterFor($field);
		return [
			'adapter' => $adapter->key(),
			'fieldtype' => $field->type->className(),
			'value' => $adapter->exportValue($page, $field, $page->getUnformatted($field->name)),
		];
	}

	public function importField(Page $page, Field $field, $payload) {
		if (is_array($payload) && array_key_exists('value', $payload) && isset($payload['adapter'])) {
			$payload = $payload['value'];
		}
		return $this->adapterFor($field)->importValue($page, $field, $payload);
	}

	public function validateField(Page $page, Field $field, $payload): array {
		if (is_array($payload) && array_key_exists('value', $payload) && isset($payload['adapter'])) {
			$payload = $payload['value'];
		}
		return $this->adapterFor($field)->validatePayload($page, $field, $payload);
	}

	public function summarizeField(Field $field, $payload): string {
		if (is_array($payload) && array_key_exists('value', $payload) && isset($payload['adapter'])) {
			$payload = $payload['value'];
		}
		return $this->adapterFor($field)->summarize($field, $payload);
	}

	public function keys(): array {
		return array_map(static fn(KernFieldAdapter $adapter) => $adapter->key(), $this->adapters);
	}
}
