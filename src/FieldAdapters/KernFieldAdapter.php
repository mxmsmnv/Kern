<?php namespace ProcessWire;

interface KernFieldAdapter {

	public function key(): string;

	public function supports(Field $field): bool;

	public function exportValue(Page $page, Field $field, $value);

	public function importValue(Page $page, Field $field, $payload);

	public function validatePayload(Page $page, Field $field, $payload): array;

	public function summarize(Field $field, $payload): string;
}
