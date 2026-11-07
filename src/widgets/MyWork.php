<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\widgets;

use Craft;
use craft\base\Widget;
use SomehowDigital\Craft\Hub\Hub;

/**
 * Dashboard widget summing up the "My Work" page of the user, linking to it.
 */
class MyWork extends Widget
{
	public static function displayName(): string
	{
		return Craft::t('hub', 'My Work');
	}

	public static function isSelectable(): bool
	{
		return Hub::getInstance()->work->canView();
	}

	public static function icon(): ?string
	{
		return 'list-check';
	}

	public function getBodyHtml(): ?string
	{
		$work = Hub::getInstance()->work;

		if (!$work->canView()) {
			return null;
		}

		return Craft::$app->getView()->renderTemplate('hub/_components/work-widget', ['sections' => $work->getSections()]);
	}
}
