<?php namespace ProcessWire;

/**
 * ProcessWire admin dashboard for Kern.
 *
 * @version 100
 * @license MIT
 */

require_once __DIR__ . '/src/Admin/KernDashboardTrait.php';
require_once __DIR__ . '/src/Admin/KernClaimsTrait.php';
require_once __DIR__ . '/src/Admin/KernRevisionsTrait.php';
require_once __DIR__ . '/src/Admin/KernCodesTrait.php';
require_once __DIR__ . '/src/Admin/KernHistoryTrait.php';
require_once __DIR__ . '/src/Admin/KernAccessTrait.php';
require_once __DIR__ . '/src/Admin/KernAdminSupportTrait.php';

class ProcessKern extends Process {
	use KernDashboardTrait;
	use KernClaimsTrait;
	use KernRevisionsTrait;
	use KernCodesTrait;
	use KernHistoryTrait;
	use KernAccessTrait;
	use KernAdminSupportTrait;

	public const VERSION = 100;

	public static function getModuleInfo(): array {
		return [
			'title' => 'Kern',
			'version' => self::VERSION,
			'summary' => 'Moderate ownership claims and Page revisions, issue access codes, and inspect history.',
			'author' => 'Maxim Semenov',
			'license' => 'MIT',
			'hreflicense' => 'LICENSE',
			'icon' => 'shield',
			'singular' => true,
			'autoload' => false,
			'requires' => ['Kern>=1.0.0'],
			'permission' => 'page-view',
			'page' => [
				'name' => 'kern',
				'parent' => 'setup',
				'title' => 'Kern',
			],
		];
	}
}
