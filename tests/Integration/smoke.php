<?php

/**
 * Runs Hub's queries against the real database of an installed Craft project, as the other tests answer them with fakes:
 * php tests/Integration/smoke.php /path/to/project
 *
 * Every getter of Hub's services taking no arguments is called, every sort column and filter of the lists is queried, and
 * the condition limiting lists to what users may view is run. Exits with 1 on any database error.
 */

use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use SomehowDigital\Craft\Hub\Hub;
use SomehowDigital\Craft\Hub\models\EntryFilter;
use SomehowDigital\Craft\Hub\models\FileFilter;
use SomehowDigital\Craft\Hub\models\TrashedEntryFilter;
use SomehowDigital\Craft\Hub\models\TrashedFileFilter;
use SomehowDigital\Craft\Hub\models\UserFilter;
use yii\db\Exception as DbException;

$root = realpath($argv[1] ?? '') ?: exit("Usage: php smoke.php /path/to/project\n");

define('CRAFT_BASE_PATH', $root);
define('CRAFT_VENDOR_PATH', "$root/vendor");

require "$root/vendor/autoload.php";

if (class_exists(Dotenv\Dotenv::class) && is_file("$root/.env")) {
	Dotenv\Dotenv::createUnsafeImmutable($root)->safeLoad();
}

/** @var craft\console\Application $app */
$app = require "$root/vendor/craftcms/cms/bootstrap/console.php";

$hub = Hub::getInstance() ?? exit("Hub isn't installed.\n");
$failed = 0;
$ran = 0;

$check = function (string $name, callable $run) use (&$failed, &$ran): void {
	$ran++;

	try {
		$run();
	} catch (DbException $e) {
		$failed++;
		fwrite(STDERR, "FAILED $name: {$e->getMessage()}\n");
	}
};

foreach ($hub->getComponents() as $id => $definition) {
	$service = $hub->get($id);

	foreach ((new ReflectionClass($service))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
		if ($method->getDeclaringClass()->getName() === $service::class && preg_match('/^(get|count)/', $method->name) && $method->getNumberOfRequiredParameters() === 0) {
			$check("$id::{$method->name}", function () use ($service, $method) {
				try {
					$service->{$method->name}();
				} catch (DbException $e) {
					throw $e;
				} catch (Throwable) {
					// Getters that need a request or a user aren't what's checked here.
				}
			});
		}
	}
}

$entryFilter = new EntryFilter();
$entryFilter->search = 'a';
$entryFilter->drafts = true;
$entryFilter->myDrafts = true;
$entryFilter->usage = 'referenced';
$entryFilter->referencesMin = 0;
$entryFilter->staleMonths = 1;
$entryFilter->editors = [1];
$fileFilter = new FileFilter();
$fileFilter->search = 'a';
$fileFilter->extensions = ['jpg'];
$fileFilter->orientations = FileFilter::getOrientations();
$fileFilter->usage = 'used';
$fileFilter->referencesMin = 0;
$fileFilter->missingAlt = true;
$fileFilter->oversized = true;
$userFilter = new UserFilter();
$userFilter->search = 'a';
$userFilter->logins = UserFilter::getLogins();
$userFilter->twoFactor = UserFilter::TWO_FACTOR_WITHOUT;
$userFilter->entriesMin = 0;

foreach ([[$hub->entries, $entryFilter], [$hub->files, $fileFilter], [$hub->users, $userFilter]] as [$service, $filter]) {
	foreach ($service->getSortColumns() as $column => $direction) {
		$check($service::class . " sorted by $column", fn () => $service->getQuery(new $filter(), $column, $direction)->limit(1)->all());
	}

	$check($service::class . ' filtered', fn () => $service->getQuery($filter)->count());
}

foreach (array_keys($hub->trash->getEntrySortColumns()) as $column) {
	$check("trashed entries sorted by $column", fn () => $hub->trash->getEntryQuery(new TrashedEntryFilter(), $column)->limit(1)->all());
}

foreach (array_keys($hub->trash->getFileSortColumns()) as $column) {
	$check("trashed files sorted by $column", fn () => $hub->trash->getFileQuery(new TrashedFileFilter(), $column)->limit(1)->all());
}

$check('entries limited to viewable ones', fn () => Entry::find()->andWhere($hub->entries->sectionsCondition([1], [2], 1))->limit(1)->all());
$check('files limited to viewable ones', fn () => Asset::find()->andWhere(['or', ['assets.volumeId' => [1]], ['assets.volumeId' => [2], 'assets.uploaderId' => 1]])->limit(1)->all());
$check('labels condition', fn () => User::find()->andWhere($hub->labels->condition('users', ['1', 'none']))->limit(1)->all());
$check('reference counts', fn () => $hub->references->getReferenceCounts([1]));
$check('references of each', fn () => $hub->references->getReferencesOfEach([1], fn () => false));
$check('children of deleted entries', fn () => (fn () => $this->childrenOf([1], [1]))->call($hub->deletion));
$check('deleters', fn () => $hub->trash->getDeleters(SomehowDigital\Craft\Hub\enums\ActivitySubject::Entry, [1]));
$check('activity list', fn () => $hub->activity->getQuery(new SomehowDigital\Craft\Hub\models\ActivityFilter())->limit(1)->all());
$check('garbage collection', function () use ($hub) {
	$hub->labels->deleteOrphans();
	$hub->activity->deleteExpired();
	$hub->snapshots->deleteExpired();
	$hub->trash->deletePurgedFiles();
});

echo "$ran checks, $failed failed\n";
exit($failed > 0 ? 1 : 0);
