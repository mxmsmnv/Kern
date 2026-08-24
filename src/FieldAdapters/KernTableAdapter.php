<?php namespace ProcessWire;

final class KernTableAdapter extends KernAbstractAdapter {

	public function key(): string {
		return 'profields-table';
	}

	public function supports(Field $field): bool {
		return $this->valueClassName($field->type) === 'FieldtypeTable';
	}

	public function exportValue(Page $page, Field $field, $value) {
		$rows = [];
		foreach ($value ?: [] as $row) {
			$data = method_exists($row, 'getArray') ? $row->getArray() : (array)$row;
			unset($data['_pw_page'], $data['_pw_field']);
			$rows[] = $this->normalize($data);
		}
		return $rows;
	}

	public function importValue(Page $page, Field $field, $payload) {
		if (!is_array($payload)) throw new WireException("Table field {$field->name} requires a rows array.");
		$rows = $field->type->getBlankValue($page, $field);
		foreach ($payload as $data) {
			if (!is_array($data)) continue;
			$row = $rows->makeBlankItem();
			foreach ($data as $name => $value) {
				if ($name === 'id') continue;
				$row->set((string)$name, $value);
			}
			$rows->add($row);
		}
		return $field->type->sanitizeValue($page, $field, $rows);
	}

	public function summarize(Field $field, $payload): string {
		$count = is_array($payload) ? count($payload) : 0;
		return sprintf($this->_n('%d table row', '%d table rows', $count), $count);
	}
}
