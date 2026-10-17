<?php

use craft\elements\User;
use SomehowDigital\Craft\Hub\controllers\SectionsController;
use SomehowDigital\Craft\Hub\controllers\LabelsController;
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

it('lists the labels with the number of items labeled with them', function () {
	$this->db()->respond('GROUP BY [[labelId]]', [['labelId' => 1, 'count' => 4]]);

	$variables = $this->runAction(LabelsController::class, 'index')->variables;

	expect(array_keys($variables['labels']))->toBe([1, 2])
		->and($variables['usage'])->toBe([1 => 4]);
});

it('edits a new label, or an existing one', function () {
	expect($this->runAction(LabelsController::class, 'edit')->variables['label']->id)->toBeNull()
		->and($this->runAction(LabelsController::class, 'edit', ['labelId' => 2])->variables['label']->name)->toBe('Review');
});

it('edits a label that failed to save as it was sent', function () {
	$label = new Label(['name' => '']);

	expect($this->runAction(LabelsController::class, 'edit', ['labelId' => 2, 'label' => $label])->variables['label'])->toBe($label);
});

it('edits no unknown label', function () {
	$this->runAction(LabelsController::class, 'edit', ['labelId' => 9]);
})->throws(NotFoundHttpException::class);

it('only lets users with the permission manage the labels', function (string $action) {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS]);
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

it('reorders nothing without IDs', function () {
	($this->post)('reorder', ['ids' => 'null']);

	expect($this->db()->queriesMatching('UPDATE'))->toBe([]);
});

it('only lets users with the permission change the labels', function (string $action) {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS]);
	($this->post)($action, ['id' => 1, 'ids' => '[]', 'name' => 'Keep']);
})->with(['save', 'delete', 'reorder'])->throws(ForbiddenHttpException::class);

it('labels an item for users with access, responding with its labels', function () {
	$this->actingAs(permissions: ['accessCp', 'accessCpWhenSystemIsOff', Hub::PERMISSION_ACCESS]);

	$response = ($this->post)('apply', ['list' => ColumnService::LIST_SECTIONS, 'id' => 1, 'labels' => ['2', '9']]);

	expect($response->data)->toBe(['labels' => [2]])
		->and($this->db()->queriesMatching('INSERT INTO {{%hub_labeled}}')[0])->toContain("(2, 'sections', 1");
});

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
