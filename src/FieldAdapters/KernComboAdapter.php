<?php namespace ProcessWire;

final class KernComboAdapter extends KernAbstractAdapter {

	public function key(): string {
		return 'profields-combo';
	}

	public function supports(Field $field): bool {
		return $this->valueClassName($field->type) === 'FieldtypeCombo';
	}

	public function exportValue(Page $page, Field $field, $value) {
		return $this->normalize($value);
	}

	public function importValue(Page $page, Field $field, $payload) {
		if (!is_array($payload)) throw new WireException("Combo field {$field->name} requires an object payload.");
		return $field->type->sanitizeValue($page, $field, $payload);
	}
}
