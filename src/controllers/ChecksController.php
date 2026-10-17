<?php

namespace SomehowDigital\Craft\Hub\controllers;

use Craft;
use craft\helpers\Json;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\enums\Severity;
use SomehowDigital\Craft\Hub\helpers\Ids;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\CheckDefinition;
use SomehowDigital\Craft\Hub\models\Settings;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The diagnostics tab of the settings page, managing the checks of the diagnostics tabs of each kind of pages, for users
 * who may manage them, each flagging the items a filter of its kind matches: Hub's default checks, each of one of Hub's
 * predefined filters, can be disabled, reordered and given another severity, custom ones, of any filter of their kind,
 * created, changed and deleted too. Checks saved, toggled and deleted are recorded in the activity log.
 */
class ChecksController extends BaseController
{
	public function beforeAction($action): bool
	{
		if (!parent::beforeAction($action)) {
			return false;
		}

		$this->requirePermission(Hub::PERMISSION_MANAGE_DIAGNOSTICS);

		return true;
	}

	/**
	 * The checks of each kind, along with whether diagnostics are enabled, for admins; settings that failed to save are
	 * passed back.
	 */
	public function actionIndex(?Settings $settings = null): Response
	{
		$diagnostics = Hub::getInstance()->diagnostics;

		$kinds = [];
		foreach (CheckKind::cases() as $kind) {
			$kinds[$kind->value] = ['kind' => $kind, 'checks' => array_values($diagnostics->getDefinitions($kind))];
		}

		return $this->renderTemplate('hub/pages/settings/diagnostics', [
			'kinds' => $kinds,
			'enabled' => Hub::getInstance()->settings->enableDiagnostics,
			...$this->settingsVariables($settings),
		]);
	}

	/**
	 * Form of a new custom check of a kind, or of the check with the ID; a check that failed to save is passed back to it.
	 */
	public function actionEdit(?int $checkId = null, ?string $kind = null, ?CheckDefinition $check = null): Response
	{
		if ($check === null && $checkId !== null) {
			$check = Hub::getInstance()->diagnostics->getDefinitionById($checkId) ?? throw new NotFoundHttpException('Check not found.');
		}

		$check ??= new CheckDefinition(['kind' => CheckKind::tryFrom((string)$kind) ?? throw new NotFoundHttpException('Kind not found.')]);

		return $this->renderTemplate('hub/pages/settings/check', [
			'check' => $check,
			'severities' => Severity::sorted(),
			'filters' => array_values(Hub::getInstance()->filters->getFilters($check->kind)),
		]);
	}

	public function actionSave(): ?Response
	{
		$this->requirePostRequest();

		$request = Craft::$app->getRequest();
		$diagnostics = Hub::getInstance()->diagnostics;
		$id = $request->getBodyParam('checkId');

		if ($id) {
			$check = $diagnostics->getDefinitionById((int)$id) ?? throw new NotFoundHttpException('Check not found.');
		} else {
			$kind = CheckKind::tryFrom((string)$request->getBodyParam('kind')) ?? throw new BadRequestHttpException('Unknown kind.');
			$check = new CheckDefinition(['kind' => $kind]);
		}

		$check->enabled = (bool)$request->getBodyParam('enabled', true);
		$check->severity = Severity::tryFrom((string)$request->getBodyParam('severity'));

		if (!$check->isDefault()) {
			$check->name = (string)$request->getBodyParam('name', '');
			$check->description = (string)$request->getBodyParam('description', '');
			$check->passedDescription = (string)$request->getBodyParam('passedDescription', '');
			$check->icon = (string)$request->getBodyParam('icon', '') ?: null;
			$check->severity ??= Severity::Notice;
			$check->filter = (string)$request->getBodyParam('filter', '');
		}

		$isNew = $check->id === null;

		if (!$diagnostics->saveDefinition($check)) {
			return $this->asModelFailure($check, Craft::t('hub', 'Couldn’t save the check.'), 'check');
		}

		$this->record($isNew ? ActivityAction::Created : ActivityAction::Updated, $check);

		// Back to the checks, unless saved to continue editing it.
		$redirect = $this->getPostedRedirectUrl($check) ?? $this->hubUrl('hub/manage/settings/diagnostics');

		return $this->asModelSuccess($check, Craft::t('hub', 'Check saved.'), 'check', redirect: $redirect);
	}

	/**
	 * Deletes a custom check, from the checks table or from its form, which is sent back to the checks.
	 */
	public function actionDelete(): ?Response
	{
		$this->requirePostRequest();

		$diagnostics = Hub::getInstance()->diagnostics;
		$check = $diagnostics->getDefinitionById((int)Craft::$app->getRequest()->getRequiredBodyParam('id')) ?? throw new NotFoundHttpException('Check not found.');

		if ($check->isDefault()) {
			throw new BadRequestHttpException('Default checks can’t be deleted.');
		}

		if ($diagnostics->deleteDefinitionById($check->id)) {
			$this->record(ActivityAction::Deleted, $check);
		}

		return $this->asSuccess(Craft::t('hub', 'Check deleted.'), redirect: $this->hubUrl('hub/manage/settings/diagnostics'));
	}

	/**
	 * Orders the checks of a kind by the JSON-encoded IDs sent by Craft's admin table.
	 */
	public function actionReorder(): Response
	{
		$this->requirePostRequest();
		$this->requireAcceptsJson();

		$ids = Json::decode((string)Craft::$app->getRequest()->getRequiredBodyParam('ids'));
		Hub::getInstance()->diagnostics->reorderDefinitions(Ids::positive($ids));

		return $this->asSuccess();
	}

	/**
	 * Enables or disables a check, from the checks table.
	 */
	public function actionToggle(): Response
	{
		$this->requirePostRequest();
		$this->requireAcceptsJson();

		$request = Craft::$app->getRequest();
		$diagnostics = Hub::getInstance()->diagnostics;
		$check = $diagnostics->getDefinitionById((int)$request->getRequiredBodyParam('id')) ?? throw new NotFoundHttpException('Check not found.');
		$check->enabled = (bool)$request->getRequiredBodyParam('enabled');

		if (!$diagnostics->saveDefinition($check)) {
			return $this->asFailure(Craft::t('hub', 'Couldn’t save the check.'));
		}

		$this->record($check->enabled ? ActivityAction::Enabled : ActivityAction::Disabled, $check);

		return $this->asSuccess($check->enabled ? Craft::t('hub', 'Check enabled.') : Craft::t('hub', 'Check disabled.'), ['enabled' => $check->enabled]);
	}

	/**
	 * Checks are recorded by their kind, as the same check may be offered for several.
	 */
	private function record(ActivityAction $action, CheckDefinition $check): void
	{
		Hub::getInstance()->activity->record($action, ActivitySubject::Check, $check->id, $check->name, ['kind' => $check->kind->label()]);
	}
}
