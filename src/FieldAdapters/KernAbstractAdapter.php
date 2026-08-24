<?php namespace ProcessWire;

abstract class KernAbstractAdapter extends Wire implements KernFieldAdapter {

	protected function valueClassName($value): string {
		if (!is_object($value)) return '';
		if (method_exists($value, 'className')) return (string)$value->className();
		return (new \ReflectionClass($value))->getShortName();
	}

	protected function normalize($value, int $depth = 0) {
		if ($depth > 20) throw new WireException('Field value nesting exceeds the supported depth.');
		if ($value === null || is_scalar($value)) return $value;
		if ($value instanceof Page) return ['_page' => (int)$value->id];
		if ($value instanceof Pagefile) return ['_file' => (string)$value->basename];
		if ($value instanceof WireArray || $value instanceof \Traversable) {
			$out = [];
			foreach ($value as $key => $item) $out[$key] = $this->normalize($item, $depth + 1);
			return array_values($out);
		}
		if (is_array($value)) {
			$out = [];
			foreach ($value as $key => $item) $out[$key] = $this->normalize($item, $depth + 1);
			return $out;
		}
		if ($value instanceof WireData) return $this->normalize($value->getArray(), $depth + 1);
		if ($value instanceof \JsonSerializable) return $this->normalize($value->jsonSerialize(), $depth + 1);
		if (method_exists($value, 'getArray')) return $this->normalize($value->getArray(), $depth + 1);
		if (method_exists($value, '__toString')) return (string)$value;
		throw new WireException('Unsupported field value object: ' . get_class($value));
	}

	protected function jsonLength($payload): int {
		return strlen((string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	public function validatePayload(Page $page, Field $field, $payload): array {
		$max = (int)($this->wire('modules')->getConfig('Kern')['max_field_payload_bytes'] ?? 1048576);
		if ($this->jsonLength($payload) > $max) {
			return ['Field payload exceeds the configured size limit.'];
		}
		return [];
	}

	public function summarize(Field $field, $payload): string {
		if ($payload === null || $payload === '' || $payload === []) return $this->_('Empty');
		if (is_scalar($payload)) return mb_strimwidth((string)$payload, 0, 120, '…');
		if (is_array($payload)) return sprintf($this->_('%d structured values'), count($payload));
		return $this->_('Structured value');
	}
}
