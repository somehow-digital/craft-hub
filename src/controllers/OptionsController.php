<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\Hub;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The options of the filters of the lists that are read from the database, which are only loaded as their dropdowns first
 * open, as most users never open most of them.
 */
class OptionsController extends BaseController
{
	/** pages of the lists offering each filter, which the user must be able to view; the users filter is of the activity log */
	private const PAGES = [
		'author' => Page::Entries,
		'editor' => Page::Entries,
		'kind' => Page::Assets,
		'extension' => Page::Assets,
		'uploader' => Page::Assets,
		'user' => null,
	];

	/**
	 * The `choices` of a filter, limited to what the list is, and to the sites in scope.
	 */
	public function actionIndex(string $filter): Response
	{
		if (!array_key_exists($filter, self::PAGES)) {
			throw new NotFoundHttpException('Filter not found.');
		}

		$hub = Hub::getInstance();
		$page = self::PAGES[$filter];

		if ($page !== null ? !$hub->access->canView($page) : !$hub->activity->isEnabled() || !$hub->activity->canView()) {
			throw new ForbiddenHttpException('User is not authorized to perform this action.');
		}

		$section = $this->scope('section');
		$type = $this->scope('type');
		$field = $this->scope('field');
		$volume = $this->scope('volume');

		return $this->renderTemplate('hub/_components/filter-options', [
			'name' => $filter,
			'options' => match ($filter) {
				'author' => $this->userOptions($hub->entries->getAuthors($section, $type, $field)),
				'editor' => $this->userOptions($hub->edits->getEditors($section, $type, $field)),
				'kind' => $this->kindOptions($hub->volumes->getKinds()),
				'extension' => $this->extensionOptions($volume),
				'uploader' => $this->userOptions($hub->files->getUploaders($volume)),
				'user' => $this->activityUserOptions($hub->activity->getUserOptions()),
			},
		]);
	}

	/**
	 * Options of the filter by file extensions, which are their own labels.
	 *
	 * @return array<string,string>
	 */
	private function extensionOptions(?int $volume): array
	{
		$extensions = array_map('strval', array_keys(Hub::getInstance()->files->getExtensions($volume)));

		return array_combine($extensions, $extensions);
	}

	/**
	 * ID the list is limited to by a query param, if any.
	 */
	private function scope(string $name): ?int
	{
		return (int)Craft::$app->getRequest()->getQueryParam($name) ?: null;
	}
}
