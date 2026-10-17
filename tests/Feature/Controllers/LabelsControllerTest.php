<?php

use craft\elements\User;
use craft\web\Session;
use SomehowDigital\Craft\Hub\controllers\LabelsController;
use SomehowDigital\Craft\Hub\controllers\SectionsController;
use SomehowDigital\Craft\Hub\enums\Page;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\Label;
use SomehowDigital\Craft\Hub\services\ColumnService;
use SomehowDigital\Craft\Hub\services\LabelService;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

beforeEach(function () {
	$this->createContentModel();

	$this->db()->respond('FROM {{%hub_labels}}', [
		['id' => 1, 'name' => 'Keep', 'icon' => 'thumbtack', 'color' => 'green', 'sortOrder' => 1, 'uid' => 'label-keep'],
		['id' => 2, 'name' => 'Review', 'icon' => null, 'color' => null, 'sortOrder' => 2, 'uid' => 'label-review'],
	]);

	// Posts the body params to an action of the controller as a signed-in user, asking for JSON.
	$this->post = function (string $action, array $body): ?Response {
		$this->request();
		$request = Craft::$app->getRequest();
		$request->enableCsrfValidation = false;
		$request->enableCookieValidation = false;
		$request->setBodyParams($body);
		$request->getHeaders()->set('Accept', 'application/json');
		$_SERVER['REQUEST_METHOD'] = 'POST';
		Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn(new User(['id' => 1, 'username' => 'admin', 'admin' => $this->isAdmin]));

		try {
			return Craft::createObject(LabelsController::class, ['labels', $this->hub()])->runAction($action);
		} finally {
			unset($_SERVER['REQUEST_METHOD']);
		}
	};
});

it('switches labels on and off along with the labels, for admins only', function () {
	$admin = $this->runAction(LabelsController::class, 'index')->variables;

	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS_DATA, Hub::PERMISSION_MANAGE_LABELS]);
	$other = $this->runAction(LabelsController::class, 'index')->variables;

	expect($admin['settings'])->toBe($this->hub()->getSettings())
		->and($admin['readOnly'])->toBeFalse()
		->and($other)->not->toHaveKey('settings');
});

it('lists the labels with the lists they are used in, linking to them filtered by them', function () {
	$this->db()->respond('GROUP BY [[labelId]]', [
		['labelId' => 1, 'list' => ColumnService::LIST_SECTIONS, 'count' => '1'],
		['labelId' => 1, 'list' => ColumnService::LIST_ENTRIES, 'count' => '4'],
	]);

	$variables = $this->runAction(LabelsController::class, 'index')->variables;

	expect(array_keys($variables['labels']))->toBe([1, 2])
		->and(array_keys($variables['lists'][1]))->toBe([ColumnService::LIST_ENTRIES, ColumnService::LIST_SECTIONS])
		->and($variables['lists'][1][ColumnService::LIST_ENTRIES]['count'])->toBe(4)
		->and($variables['lists'][1][ColumnService::LIST_ENTRIES]['label'])->toBe('Entries')
		->and($variables['lists'][1][ColumnService::LIST_SECTIONS]['url'])->toContain('hub/structure/sections')
		->and($variables['lists'][1][ColumnService::LIST_SECTIONS]['url'])->toContain('label=1')
		->and($variables['lists'][2])->toBe([]);
});

it('edits a new label, or an existing one with the lists it is used in', function () {
	$this->db()->respond('GROUP BY [[labelId]]', [['labelId' => 2, 'list' => ColumnService::LIST_USER_GROUPS, 'count' => '2']]);

	$new = $this->runAction(LabelsController::class, 'edit')->variables;
	$existing = $this->runAction(LabelsController::class, 'edit', ['labelId' => 2])->variables;

	expect($new['label']->id)->toBeNull()
		->and($new['lists'])->toBe([])
		->and($existing['label']->name)->toBe('Review')
		->and(array_keys($existing['lists']))->toBe([ColumnService::LIST_USER_GROUPS])
		->and($existing['lists'][ColumnService::LIST_USER_GROUPS]['url'])->toContain('hub/data/users/groups');
});

it('edits a label that failed to save as it was sent', function () {
	$label = new Label(['name' => '']);

	expect($this->runAction(LabelsController::class, 'edit', ['labelId' => 2, 'label' => $label])->variables['label'])->toBe($label);
});

it('edits no unknown label', function () {
	$this->runAction(LabelsController::class, 'edit', ['labelId' => 9]);
})->throws(NotFoundHttpException::class);

it('only lets users with the permission manage the labels', function (string $action) {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS_DATA]);
	$this->runAction(LabelsController::class, $action);
})->with(['index', 'edit'])->throws(ForbiddenHttpException::class);

it('saves a new label, taking the blank color for none', function () {
	$response = ($this->post)('save', ['name' => 'Later', 'icon' => 'clock', 'color' => '__blank__']);

	expect($response->data['label']['name'])->toBe('Later')
		->and($response->data['label']['color'])->toBeNull()
		->and($this->db()->queriesMatching('INSERT INTO {{%hub_labels}}')[0])->toContain("'Later', 'clock', NULL");
});

it('saves the changes to a label', function () {
	($this->post)('save', ['labelId' => 2, 'name' => 'Check', 'color' => 'red']);

	expect($this->db()->queriesMatching('UPDATE {{%hub_labels}}')[0])->toContain("`name`='Check'")
		->and($this->db()->queriesMatching('UPDATE {{%hub_labels}}')[0])->toContain("`color`='red'");
});

it('fails to save an invalid label', function () {
	$response = ($this->post)('save', ['name' => ' ']);

	expect($response->getStatusCode())->toBe(400)
		->and($response->data['errors'])->toHaveKey('name')
		->and($this->db()->queriesMatching('INSERT INTO'))->toBe([]);
});

it('saves no unknown label', function () {
	($this->post)('save', ['labelId' => 9, 'label' => 'Check']);
})->throws(NotFoundHttpException::class);

it('deletes and reorders the labels', function () {
	($this->post)('delete', ['id' => 2]);
	($this->post)('reorder', ['ids' => '["2","1"]']);

	expect($this->db()->queriesMatching('DELETE FROM {{%hub_labels}}')[0])->toContain('`id`=2')
		->and($this->db()->queriesMatching('UPDATE {{%hub_labels}}')[0])->toContain('`sortOrder`=1')
		->and($this->db()->queriesMatching('UPDATE {{%hub_labels}}')[0])->toContain('`id`=2');
});

it('removes a label from all items labeled with it, keeping it', function () {
	$this->db()->respond('DELETE FROM {{%hub_labeled}}', 3);

	$response = ($this->post)('clear', ['id' => 2]);

	expect($response->data['count'])->toBe(3)
		->and($response->data['message'])->toBe('Removed “Review” from 3 items.')
		->and($this->db()->queriesMatching('DELETE FROM {{%hub_labeled}}')[0])->toContain('`labelId`=2')
		->and($this->db()->queriesMatching('DELETE FROM {{%hub_labels}}'))->toBe([]);
});

it('clears no unknown label', function () {
	($this->post)('clear', ['id' => 9]);
})->throws(NotFoundHttpException::class);

it('deletes a label from its form, going back to the labels', function () {
	$this->request();
	$request = Craft::$app->getRequest();
	$request->enableCsrfValidation = false;
	$request->setBodyParams(['id' => 2]);
	$_SERVER['REQUEST_METHOD'] = 'POST';
	Craft::$app->getUser()->shouldReceive('getIdentity')->andReturn(new User(['id' => 1, 'username' => 'admin', 'admin' => true]));
	// Craft's session behavior would start a session to keep the notice in.
	$session = new class() extends Session {
		public ?string $notice = null;

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

	expect(Craft::$app->getResponse()->getHeaders()->get('Location'))->toContain('hub/manage/settings/labels')
		->and($session->notice)->toBe('Label deleted.')
		->and($this->db()->queriesMatching('DELETE FROM {{%hub_labels}}')[0])->toContain('`id`=2');
});

it('reorders nothing without IDs', function () {
	($this->post)('reorder', ['ids' => 'null']);

	expect($this->db()->queriesMatching('UPDATE'))->toBe([]);
});

it('only lets users with the permission change the labels', function (string $action) {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS_DATA]);
	($this->post)($action, ['id' => 1, 'ids' => '[]', 'name' => 'Keep']);
})->with(['save', 'delete', 'clear', 'reorder'])->throws(ForbiddenHttpException::class);

it('labels an item for users who may label items, responding with its labels', function () {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS_STRUCTURE, Page::Sections->viewPermission(), Page::Sections->labelPermission()]);

	$response = ($this->post)('apply', ['list' => ColumnService::LIST_SECTIONS, 'id' => 1, 'labels' => ['2', '9']]);

	expect($response->data)->toBe(['labels' => [2]])
		->and($this->db()->queriesMatching('INSERT INTO {{%hub_labeled}}')[0])->toContain("(2, 'sections', 1");
});

it('labels no items for users who may not label them or view their list', function (array $permissions, string $action) {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS_STRUCTURE, ...$permissions]);
	$this->session['hub:bulk:selection:sections:hub/test'] = [1];

	($this->post)($action, ['list' => ColumnService::LIST_SECTIONS, 'id' => 1, 'path' => 'hub/test', 'labels' => ['2']]);
})->with([
	'without labeling' => [[Page::Sections->viewPermission(), Page::Sections->bulkEditPermission()]],
	'without the list' => [[Page::Sections->labelPermission(), Page::Sections->bulkEditPermission()]],
	'labeling another list' => [[Page::Sections->viewPermission(), Page::Sections->bulkEditPermission(), Page::Fields->labelPermission()]],
])->with(['apply', 'counts'])->throws(ForbiddenHttpException::class);

it('unlabels an item without labels', function () {
	$this->db()->respond('FROM {{%hub_labeled}}', [['itemId' => 1, 'labelId' => 1]]);

	expect(($this->post)('apply', ['list' => ColumnService::LIST_SECTIONS, 'id' => 1])->data)->toBe(['labels' => []])
		->and($this->db()->queriesMatching('DELETE FROM {{%hub_labeled}}'))->toHaveCount(1);
});

it('labels no items of unknown lists', function () {
	($this->post)('apply', ['list' => 'bogus', 'id' => 1]);
})->throws(BadRequestHttpException::class);

it('labels no unknown items', function () {
	($this->post)('apply', ['list' => ColumnService::LIST_SECTIONS, 'id' => 99]);
})->throws(NotFoundHttpException::class);

it('counts the selected items labeled with each label', function () {
	$this->session['hub:bulk:selection:sections:hub/test'] = [1, 2, 3];
	$this->db()->respond('COUNT(*)', [['labelId' => 1, 'count' => '3'], ['labelId' => 2, 'count' => '1']]);

	$data = ($this->post)('counts', ['list' => ColumnService::LIST_SECTIONS, 'path' => 'hub/test'])->data;

	expect($data['total'])->toBe(3)
		->and((array)$data['counts'])->toBe([1 => 3, 2 => 1]);
});

it('counts no labels of unknown lists', function () {
	($this->post)('counts', ['list' => 'bogus', 'path' => 'hub/test']);
})->throws(BadRequestHttpException::class);

it('shows the labels of the items of a list and filters it by them', function () {
	$this->db()->respond('FROM {{%hub_labeled}}', [['itemId' => 2, 'labelId' => 2]]);

	$variables = $this->runAction(SectionsController::class, 'index', query: ['label' => '2,bogus'])->variables;

	expect($variables['filter']->labels)->toBe(['2'])
		->and(array_keys($variables['sections']))->toBe([2])
		->and($variables['labelList'])->toBe(ColumnService::LIST_SECTIONS)
		->and(array_keys($variables['itemLabels']))->toBe([2])
		->and(array_keys($variables['labelOptions']))->toBe([1, 2, LabelService::LABEL_NONE])
		->and($variables['labelOptions'][2])->toBe(['label' => 'Review', 'icon' => 'tag', 'color' => 'gray'])
		->and($variables['canManageLabels'])->toBeTrue();
});

it('offers no labels to filter by while there are none', function () {
	$this->db()->respond('FROM {{%hub_labels}}', []);

	$variables = $this->runAction(SectionsController::class, 'index', query: ['label' => 'none'])->variables;

	expect($variables['filter']->labels)->toBe([])
		->and($variables['labelOptions'])->toBe([])
		->and($variables['itemLabels'])->toBe([]);
});

it('shows no labels and filters by none while labels are disabled', function () {
	$this->hub()->getSettings()->enableLabels = false;
	$this->db()->respond('FROM {{%hub_labeled}}', [['itemId' => 2, 'labelId' => 2]]);

	try {
		$variables = $this->runAction(SectionsController::class, 'index', query: ['label' => '2'])->variables;
	} finally {
		$this->hub()->getSettings()->enableLabels = true;
	}

	expect($variables['filter']->labels)->toBe([])
		->and($variables['labels'])->toBe([])
		->and($variables['labelOptions'])->toBe([])
		->and($variables['itemLabels'])->toBe([])
		->and($variables['canManageLabels'])->toBeFalse();
});

it('labels no items and counts no labels while labels are disabled', function (string $action) {
	$this->hub()->getSettings()->enableLabels = false;

	try {
		($this->post)($action, ['list' => ColumnService::LIST_SECTIONS, 'id' => 1, 'path' => 'hub/test']);
	} finally {
		$this->hub()->getSettings()->enableLabels = true;
	}
})->with(['apply', 'counts'])->throws(ForbiddenHttpException::class);

it('still manages the labels while labels are disabled', function () {
	$this->hub()->getSettings()->enableLabels = false;

	try {
		$variables = $this->runAction(LabelsController::class, 'index')->variables;
	} finally {
		$this->hub()->getSettings()->enableLabels = true;
	}

	expect(array_keys($variables['labels']))->toBe([1, 2])
		->and($variables['enabled'])->toBeFalse();
});
