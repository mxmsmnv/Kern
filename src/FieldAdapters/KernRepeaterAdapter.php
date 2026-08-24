<?php namespace ProcessWire;

final class KernRepeaterAdapter extends KernAbstractAdapter {

	private ?KernFieldRegistry $registry = null;

	public function key(): string {
		return 'profields-repeater';
	}

	public function setRegistry(KernFieldRegistry $registry): void {
		$this->registry = $registry;
	}

	public function supports(Field $field): bool {
		return in_array($this->valueClassName($field->type), ['FieldtypeRepeater', 'FieldtypeRepeaterMatrix'], true);
	}

	public function exportValue(Page $page, Field $field, $value) {
		if (!$this->registry) throw new WireException('Repeater adapter registry is not initialized.');
		$items = [];
		foreach ($value ?: [] as $item) {
			if (!$item instanceof Page) continue;
			$values = [];
			foreach ($item->template->fields as $subField) {
				if ($this->isSystemField($subField)) continue;
				$values[$subField->name] = $this->registry->exportField($item, $subField);
			}
			$row = [
				'_id' => (int)$item->id,
				'_sort' => (int)$item->sort,
				'values' => $values,
			];
			if (method_exists($item, 'getMatrixType')) {
				$row['_type'] = (string)$item->getMatrixType();
			}
			$items[] = $row;
		}
		return $items;
	}

	public function importValue(Page $page, Field $field, $payload) {
		if (!$this->registry) throw new WireException('Repeater adapter registry is not initialized.');
		if (!is_array($payload)) throw new WireException("Repeater field {$field->name} requires an items array.");

		$current = $page->getUnformatted($field->name);
		$byId = [];
		foreach ($current ?: [] as $item) $byId[(int)$item->id] = $item;

		foreach ($payload as $row) {
			$id = (int)($row['_id'] ?? 0);
			if (!$id || !isset($byId[$id])) {
				throw new WireException(
					"Repeater field {$field->name} can edit existing items only; structural changes require a project adapter."
				);
			}
			$item = $byId[$id];
			if (isset($row['_type']) && method_exists($item, 'getMatrixType')) {
				if ((string)$item->getMatrixType() !== (string)$row['_type']) {
					throw new WireException("Changing a RepeaterMatrix item type is not allowed in a generic revision.");
				}
			}
			$item->of(false);
			foreach ((array)($row['values'] ?? []) as $name => $value) {
				$subField = $item->template->fields->get((string)$name);
				if (!$subField || $this->isSystemField($subField)) continue;
				$item->set($subField->name, $this->registry->importField($item, $subField, $value));
			}
			$item->save();
			unset($byId[$id]);
		}

		if ($byId) {
			throw new WireException(
				"Removing Repeater items from field {$field->name} requires a project adapter."
			);
		}
		return $current;
	}

	public function validatePayload(Page $page, Field $field, $payload): array {
		$errors = parent::validatePayload($page, $field, $payload);
		if (!is_array($payload)) return array_merge($errors, ['Repeater payload must be an array.']);
		$currentIds = [];
		foreach ($page->getUnformatted($field->name) ?: [] as $item) $currentIds[] = (int)$item->id;
		$payloadIds = array_map(static fn($row) => (int)($row['_id'] ?? 0), $payload);
		sort($currentIds);
		sort($payloadIds);
		if ($currentIds !== $payloadIds) {
			$errors[] = 'Generic Repeater revisions must preserve the existing item set.';
		}
		return $errors;
	}

	private function isSystemField(Field $field): bool {
		return $field->flags & Field::flagSystem
			|| in_array($field->name, ['id', 'name', 'status', 'sort', 'parent', 'template'], true);
	}

	public function summarize(Field $field, $payload): string {
		$count = is_array($payload) ? count($payload) : 0;
		return sprintf($this->_n('%d repeater item', '%d repeater items', $count), $count);
	}
}
