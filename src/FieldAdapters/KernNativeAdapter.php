<?php namespace ProcessWire;

final class KernNativeAdapter extends KernAbstractAdapter {

	public function key(): string {
		return 'native';
	}

	public function supports(Field $field): bool {
		return true;
	}

	public function exportValue(Page $page, Field $field, $value) {
		return $this->normalize($value);
	}

	public function importValue(Page $page, Field $field, $payload) {
		$typeName = $this->valueClassName($field->type);

		if ($typeName === 'FieldtypePage') {
			$ids = [];
			foreach (is_array($payload) ? $payload : [$payload] as $item) {
				$id = is_array($item) ? (int)($item['_page'] ?? 0) : (int)$item;
				if ($id > 0) $ids[] = $id;
			}
			$values = $this->wire('pages')->getById(array_values(array_unique($ids)));
			return (int)$field->get('derefAsPage') === 1 ? ($values->first() ?: new NullPage()) : $values;
		}

		if (in_array($typeName, ['FieldtypeFile', 'FieldtypeImage'], true)) {
			$current = $page->getUnformatted($field->name);
			$requested = [];
			$items = is_array($payload) && array_key_exists('_file', $payload) ? [$payload] : (is_array($payload) ? $payload : []);
			foreach ($items as $item) {
				$name = is_array($item) ? (string)($item['_file'] ?? '') : (string)$item;
				if ($name !== '') $requested[] = $name;
			}
			$currentNames = [];
			if ($current instanceof Pagefile) {
				$currentNames[] = (string)$current->basename;
			} else {
				foreach ($current ?: [] as $file) $currentNames[] = (string)$file->basename;
			}
			if ($requested !== $currentNames) {
				throw new WireException(
					"File field {$field->name} cannot add, remove, or reorder files without a staging upload adapter."
				);
			}
			return $current;
		}

		try {
			$value = $field->type->wakeupValue($page, $field, $payload);
		} catch (\Throwable $e) {
			$value = $payload;
		}
		return $field->type->sanitizeValue($page, $field, $value);
	}
}
