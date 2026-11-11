<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\bulk;

use Craft;
use craft\base\ElementInterface;
use craft\services\Elements;
use SomehowDigital\Craft\Hub\services\AccessService;
use SomehowDigital\Craft\Hub\services\BulkService;

/**
 * Restores trashed elements like Craft's restore action, as far as the user may save them. Files whose file wasn't kept
 * can't be restored, see Asset::beforeRestore().
 *
 * @internal
 */
final class Restore extends BaseRestore
{
	public function __construct(
		string $list,
		AccessService $access,
		private readonly BulkService $bulk,
		private readonly Elements $craftElements,
	) {
		parent::__construct($list, $access);
	}

	public function definition(): array
	{
		return ['label' => Craft::t('app', 'Restore'), 'icon' => 'trash-arrow-up', 'unless' => 'unrestorable'];
	}

	/**
	 * @return list<int>
	 */
	public function apply(array $ids, array $params): array
	{
		$service = $this->craftElements;

		return $this->succeeded(
			$this->bulk->getElements($this->list, $ids),
			fn(ElementInterface $element): bool => $service->canSave($element) && $service->restoreElement($element),
		);
	}
}
