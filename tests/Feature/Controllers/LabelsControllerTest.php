<?php

declare(strict_types=1);

use craft\elements\User;
use craft\web\Session;
use SomehowDigital\Craft\Hub\controllers\LabelsController;
use SomehowDigital\Craft\Hub\controllers\SectionsController;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\enums\Permission;
use SomehowDigital\Craft\Hub\models\Label;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\LabelService;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

beforeEach(function (): void {
	$this->createContentModel();

	$this->db()->respond('FROM {{%hub_labels}}', [
		['id' => 1, 'name' => 'Keep', 'description' => null, 'icon' => 'thumbtack', 'color' => 'green', 'sortOrder' => 1, 'uid' => 'label-keep'],
		['id' => 2, 'name' => 'Review', 'description' => 'Needs a look', 'icon' => null, 'color' => null, 'sortOrder' => 2, 'uid' => 'label-review'],
	]);

});

it('switches labels on and off along with the labels, for admins only', function (): void {
	$admin = $this->runAction(LabelsController::class, 'index')->variables;

	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Permission::AccessData->value, Permission::ManageLabels->value]);
	$other = $this->runAction(LabelsController::class, 'index')->variables;

	expect($admin['settings'])->toBe($this->hub()->getSettings())
		->and($admin['readOnly'])->toBeFalse()
		->and($other)->not->toHaveKey('settings');
});

it('lists the labels with the lists they are used in, linking to them filtered by them', function (): void {
	$this->db()->respond('GROUP BY [[labelId]]', [
		['labelId' => 1, 'list' => ColumnService::LIST_SECTIONS, 'count' => '1'],
		['labelId' => 1, 'list' => ColumnService::LIST_ENTRIES, 'count' => '4'],
	]);

	$variables = $this->runAction(LabelsController::class, 'index')->variables;

	expect(array_keys(arrayAt($variables, 'labels')))->toBe([1, 2])
		->and(array_keys(arrayAt($variables, 'lists', 1)))->toBe([ColumnService::LIST_ENTRIES, ColumnService::LIST_SECTIONS])
		->and(at($variables, 'lists', 1, ColumnService::LIST_ENTRIES, 'count'))->toBe(4)
		->and(at($variables, 'lists', 1, ColumnService::LIST_ENTRIES, 'label'))->toBe('Entries')
		->and(at($variables, 'lists', 1, ColumnService::LIST_SECTIONS, 'url'))->toContain('hub/structure/sections')
		->and(at($variables, 'lists', 1, ColumnService::LIST_SECTIONS, 'url'))->toContain('label=1')
		->and(at($variables, 'lists', 2))->toBe([]);
});

it('edits a new label, or an existing one with the lists it is used in', function (): void {
	$this->db()->respond('GROUP BY [[labelId]]', [['labelId' => 2, 'list' => ColumnService::LIST_USER_GROUPS, 'count' => '2']]);

	$new = $this->runAction(LabelsController::class, 'edit')->variables;
	$existing = $this->runAction(LabelsController::class, 'edit', ['labelId' => 2])->variables;

	expect(at($new, 'label', 'id'))->toBeNull()
		->and($new['lists'])->toBe([])
		->and(at($existing, 'label', 'name'))->toBe('Review')
		->and(array_keys(arrayAt($existing, 'lists')))->toBe([ColumnService::LIST_USER_GROUPS])
		->and(at($existing, 'lists', ColumnService::LIST_USER_GROUPS, 'url'))->toContain('hub/data/users/groups');
});

it('edits a label that failed to save as it was sent', function (): void {
	$label = new Label(['name' => '']);

	expect(at($this->runAction(LabelsController::class, 'edit', ['labelId' => 2, 'label' => $label])->variables, 'label'))->toBe($label);
});

it('edits no unknown label', function (): void {
	$this->runAction(LabelsController::class, 'edit', ['labelId' => 9]);
})->throws(NotFoundHttpException::class);

it('only lets users with the permission manage the labels', function (string $action): void {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Permission::AccessData->value]);
	$this->runAction(LabelsController::class, $action);
})->with(['index', 'edit'])->throws(ForbiddenHttpException::class);

it('saves a new label, taking the blank color for none', function (): void {
	$response = $this->postTo(LabelsController::class, 'save', ['name' => 'Later', 'description' => 'For another day', 'icon' => 'clock', 'color' => '__blank__']);

	expect(at($response->data, 'label', 'name'))->toBe('Later')
		->and(at($response->data, 'label', 'color'))->toBeNull()
		->and($this->db()->queriesMatching('INSERT INTO {{%hub_labels}}')[0])->toContain("'Later', 'For another day', 'clock', NULL");
});

it('saves the changes to a label', function (): void {
	$this->postTo(LabelsController::class, 'save', ['labelId' => 2, 'name' => 'Check', 'color' => 'red']);

	expect($this->db()->queriesMatching('UPDATE {{%hub_labels}}')[0])->toContain("`name`='Check'")
		->and($this->db()->queriesMatching('UPDATE {{%hub_labels}}')[0])->toContain("`color`='red'");
});

it('fails to save an invalid label', function (): void {
	$response = $this->postTo(LabelsController::class, 'save', ['name' => ' ']);

	expect($response->getStatusCode())->toBe(400)
		->and(at($response->data, 'errors'))->toHaveKey('name')
		->and($this->db()->queriesMatching('INSERT INTO'))->toBe([]);
});

it('saves no unknown label', function (): void {
	$this->postTo(LabelsController::class, 'save', ['labelId' => 9, 'label' => 'Check']);
})->throws(NotFoundHttpException::class);

it('deletes and reorders the labels', function (): void {
	$this->postTo(LabelsController::class, 'delete', ['id' => 2]);
	$this->postTo(LabelsController::class, 'reorder', ['ids' => '["2","1"]']);

	expect($this->db()->queriesMatching('DELETE FROM {{%hub_labels}}')[0])->toContain('`id`=2')
		->and($this->db()->queriesMatching('UPDATE {{%hub_labels}}')[0])->toContain('`sortOrder`=1')
		->and($this->db()->queriesMatching('UPDATE {{%hub_labels}}')[0])->toContain('`id`=2');
});

it('removes a label from all items labeled with it, keeping it', function (): void {
	$this->db()->respond('DELETE FROM {{%hub_labeled}}', 3);

	$response = $this->postTo(LabelsController::class, 'clear', ['id' => 2]);

	expect(at($response->data, 'count'))->toBe(3)
		->and(at($response->data, 'message'))->toBe('Removed “Review” from 3 items.')
		->and($this->db()->queriesMatching('DELETE FROM {{%hub_labeled}}')[0])->toContain('`labelId`=2')
		->and($this->db()->queriesMatching('DELETE FROM {{%hub_labels}}'))->toBe([]);
});

it('clears no unknown label', function (): void {
	$this->postTo(LabelsController::class, 'clear', ['id' => 9]);
})->throws(NotFoundHttpException::class);

it('deletes a label from its form, going back to the labels', function (): void {
	$this->request();
	$request = webRequest();
	$request->enableCsrfValidation = false;
	$request->setBodyParams(['id' => 2]);
	$_SERVER['REQUEST_METHOD'] = 'POST';
	mocked(Craft::$app->getUser())->shouldReceive('getIdentity')->andReturn(new User(['id' => 1, 'username' => 'admin', 'admin' => true]));
	// Craft's session behavior would start a session to keep the notice in.
	$session = new class () extends Session {
		public ?string $notice = null;

		/**
		 * @param array<string,mixed> $settings
		 */
		public function setSuccess(string $message, array $settings = []): void
		{
			$this->notice = $message;
		}
	};
	Craft::$app->set('session', $session);

	try {
		Craft::createObject(LabelsController::class, ['labels', $this->hub()])->runAction('delete');
	} finally {
		unset($_SERVER['REQUEST_METHOD']);
	}

	expect(webResponse()->getHeaders()->get('Location'))->toContain('hub/manage/settings/labels')
		->and($session->notice)->toBe('Label deleted.')
		->and($this->db()->queriesMatching('DELETE FROM {{%hub_labels}}')[0])->toContain('`id`=2');
});

it('reorders nothing without IDs', function (): void {
	$this->postTo(LabelsController::class, 'reorder', ['ids' => 'null']);

	expect($this->db()->queriesMatching('UPDATE'))->toBe([]);
});

it('only lets users with the permission change the labels', function (string $action): void {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Permission::AccessData->value]);
	$this->postTo(LabelsController::class, $action, ['id' => 1, 'ids' => '[]', 'name' => 'Keep']);
})->with(['save', 'delete', 'clear', 'reorder'])->throws(ForbiddenHttpException::class);

it('labels an item for users who may label items, responding with its labels', function (): void {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Permission::AccessStructure->value, Page::Sections->viewPermission(), Page::Sections->labelPermission()]);

	$response = $this->postTo(LabelsController::class, 'apply', ['list' => ColumnService::LIST_SECTIONS, 'id' => 1, 'labels' => ['2', '9']]);

	expect($response->data)->toBe(['labels' => [2]])
		->and($this->db()->queriesMatching('INSERT INTO {{%hub_labeled}}')[0])->toContain("(2, 'sections', 1");
});

it('labels no items for users who may not label them or view their list', function (array $permissions, string $action): void {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Permission::AccessStructure->value, ...strings($permissions)]);
	$this->session['hub:bulk:selection:sections:hub/test'] = [1];

	$this->postTo(LabelsController::class, $action, ['list' => ColumnService::LIST_SECTIONS, 'id' => 1, 'path' => 'hub/test', 'labels' => ['2']]);
})->with([
	'without labeling' => [[Page::Sections->viewPermission(), Page::Sections->bulkEditPermission()]],
	'without the list' => [[Page::Sections->labelPermission(), Page::Sections->bulkEditPermission()]],
	'labeling another list' => [[Page::Sections->viewPermission(), Page::Sections->bulkEditPermission(), Page::Fields->labelPermission()]],
])->with(['apply', 'counts'])->throws(ForbiddenHttpException::class);

it('unlabels an item without labels', function (): void {
	$this->db()->respond('FROM {{%hub_labeled}}', [['itemId' => 1, 'labelId' => 1]]);

	expect($this->postTo(LabelsController::class, 'apply', ['list' => ColumnService::LIST_SECTIONS, 'id' => 1])->data)->toBe(['labels' => []])
		->and($this->db()->queriesMatching('DELETE FROM {{%hub_labeled}}'))->toHaveCount(1);
});

it('labels no items of unknown lists', function (): void {
	$this->postTo(LabelsController::class, 'apply', ['list' => 'bogus', 'id' => 1]);
})->throws(BadRequestHttpException::class);

it('labels no unknown items', function (): void {
	$this->postTo(LabelsController::class, 'apply', ['list' => ColumnService::LIST_SECTIONS, 'id' => 99]);
})->throws(NotFoundHttpException::class);

it('counts the selected items labeled with each label', function (): void {
	$this->session['hub:bulk:selection:sections:hub/test'] = [1, 2, 3];
	$this->db()->respond('COUNT(*)', [['labelId' => 1, 'count' => '3'], ['labelId' => 2, 'count' => '1']]);

	$data = $this->postTo(LabelsController::class, 'counts', ['list' => ColumnService::LIST_SECTIONS, 'path' => 'hub/test'])->data;

	expect(at($data, 'total'))->toBe(3)
		->and(propertiesOf(at($data, 'counts')))->toBe([1 => 3, 2 => 1]);
});

it('counts no labels of unknown lists', function (): void {
	$this->postTo(LabelsController::class, 'counts', ['list' => 'bogus', 'path' => 'hub/test']);
})->throws(BadRequestHttpException::class);

it('shows the labels of the items of a list and filters it by them', function (): void {
	$this->db()->respond('FROM {{%hub_labeled}}', [['itemId' => 2, 'labelId' => 2]]);

	$variables = $this->runAction(SectionsController::class, 'index', query: ['label' => '2,bogus'])->variables;

	expect(at($variables, 'list', 'filter', 'labels'))->toBe(['2'])
		->and(array_keys(arrayAt($variables, 'list', 'sections')))->toBe([2])
		->and(at($variables, 'list', 'labelList'))->toBe(ColumnService::LIST_SECTIONS)
		->and(array_keys(arrayAt($variables, 'list', 'itemLabels')))->toBe([2])
		->and(array_keys(arrayAt($variables, 'list', 'labelOptions')))->toBe([1, 2, LabelService::LABEL_NONE])
		->and(at($variables, 'list', 'labelOptions', 2))->toBe(['label' => 'Review', 'title' => 'Needs a look', 'icon' => 'tag', 'color' => 'gray'])
		->and(at($variables, 'list', 'canManageLabels'))->toBeTrue();
});

it('offers no labels to filter by while there are none', function (): void {
	$this->db()->respond('FROM {{%hub_labels}}', []);

	$variables = $this->runAction(SectionsController::class, 'index', query: ['label' => 'none'])->variables;

	expect(at($variables, 'list', 'filter', 'labels'))->toBe([])
		->and(at($variables, 'list', 'labelOptions'))->toBe([])
		->and(at($variables, 'list', 'itemLabels'))->toBe([]);
});

it('shows no labels and filters by none while labels are disabled', function (): void {
	$this->hub()->settings->enableLabels = false;
	$this->db()->respond('FROM {{%hub_labeled}}', [['itemId' => 2, 'labelId' => 2]]);

	try {
		$variables = $this->runAction(SectionsController::class, 'index', query: ['label' => '2'])->variables;
	} finally {
		$this->hub()->settings->enableLabels = true;
	}

	expect(at($variables, 'list', 'filter', 'labels'))->toBe([])
		->and(at($variables, 'list', 'labels'))->toBe([])
		->and(at($variables, 'list', 'labelOptions'))->toBe([])
		->and(at($variables, 'list', 'itemLabels'))->toBe([])
		->and(at($variables, 'list', 'canManageLabels'))->toBeFalse();
});

it('labels no items and counts no labels while labels are disabled', function (string $action): void {
	$this->hub()->settings->enableLabels = false;

	try {
		$this->postTo(LabelsController::class, $action, ['list' => ColumnService::LIST_SECTIONS, 'id' => 1, 'path' => 'hub/test']);
	} finally {
		$this->hub()->settings->enableLabels = true;
	}
})->with(['apply', 'counts'])->throws(ForbiddenHttpException::class);

it('still manages the labels while labels are disabled', function (): void {
	$this->hub()->settings->enableLabels = false;

	try {
		$variables = $this->runAction(LabelsController::class, 'index')->variables;
	} finally {
		$this->hub()->settings->enableLabels = true;
	}

	expect(array_keys(arrayAt($variables, 'labels')))->toBe([1, 2])
		->and($variables['enabled'])->toBeFalse();
});
