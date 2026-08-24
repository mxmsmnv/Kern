<?php namespace ProcessWire;

final class KernRapidAdapter extends KernAbstractAdapter {

	public function key(): string {
		return 'rapid';
	}

	public function supports(Field $field): bool {
		return $this->valueClassName($field->type) === 'FieldtypeRapid';
	}

	public function exportValue(Page $page, Field $field, $value) {
		$json = is_object($value) && method_exists($value, 'toJSON')
			? $value->toJSON()
			: (string)$field->type->sleepValue($page, $field, $value);
		$data = json_decode((string)$json, true);
		return is_array($data) ? $data : ['time' => 0, 'blocks' => [], 'version' => ''];
	}

	public function importValue(Page $page, Field $field, $payload) {
		if (!is_array($payload) || !isset($payload['blocks']) || !is_array($payload['blocks'])) {
			throw new WireException("Invalid Rapid payload for field {$field->name}.");
		}
		$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		return $field->type->sanitizeValue($page, $field, $json ?: '');
	}

	public function validatePayload(Page $page, Field $field, $payload): array {
		$errors = parent::validatePayload($page, $field, $payload);
		if (!is_array($payload) || !isset($payload['blocks']) || !is_array($payload['blocks'])) {
			$errors[] = 'Rapid payload must contain a blocks array.';
		}
		$allowed = array_filter((array)$field->get('allowedBlocks'));
		$alwaysAllowed = ['paragraph', 'layoutSection', 'gallery', 'imageSlideshow', 'columns'];
		if ($allowed && !empty($payload['blocks'])) {
			foreach ($payload['blocks'] as $block) {
				$type = (string)($block['type'] ?? '');
				if (!in_array($type, $allowed, true) && !in_array($type, $alwaysAllowed, true)) {
					$errors[] = "Rapid block type '$type' is not allowed for this field.";
				}
			}
		}
		return array_values(array_unique($errors));
	}

	public function summarize(Field $field, $payload): string {
		$count = is_array($payload['blocks'] ?? null) ? count($payload['blocks']) : 0;
		return sprintf($this->_n('%d Rapid block', '%d Rapid blocks', $count), $count);
	}
}
