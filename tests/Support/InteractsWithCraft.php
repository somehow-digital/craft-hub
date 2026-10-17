<?php

namespace SomehowDigital\Craft\Hub\Tests\Support;

use Craft;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fs\Local;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Site;
use craft\models\Volume;
use craft\services\Categories;
use craft\services\Elements;
use craft\services\Entries;
use craft\services\Fields;
use craft\services\Globals;
use craft\services\Sites;
use craft\services\Tags;
use craft\services\Volumes;
use craft\web\User as UserComponent;
use Mockery;
use Mockery\MockInterface;

/**
 * Craft's services, partially mocked so they serve the sites, sections, entry types, fields and volumes a test creates,
 * and a user who may do anything unless the test says otherwise.
 */
trait InteractsWithCraft
{
	/** @var array<int,Site> */
	protected array $sites = [];

	/** @var int[]|null IDs of the sites the user can edit, all when null */
	protected ?array $editableSiteIds = null;

	/** @var array<int,Section> */
	protected array $sections = [];

	/** @var array<int,EntryType> */
	protected array $entryTypes = [];

	/** @var array<int,FieldInterface> */
	protected array $fields = [];

	/** @var FieldLayout[] layouts not owned by an entry type or volume, e.g. of users */
	protected array $layouts = [];

	/** @var array<int,Volume> */
	protected array $volumes = [];

	protected bool $isAdmin = true;

	/** @var string[]|null permissions of the user, all when null */
	protected ?array $permissions = null;

	protected bool $canSave = true;

	protected function mockCraftServices(): void
	{
		$this->site(1, 'en', primary: true);

		/** @var Sites&MockInterface $sites */
		$sites = Mockery::mock(Sites::class)->makePartial();
		$sites->shouldReceive('getAllSites')->andReturnUsing(fn () => array_values($this->sites))->byDefault();
		$sites->shouldReceive('getSiteById')->andReturnUsing(fn ($id) => $this->sites[$id] ?? null)->byDefault();
		$sites->shouldReceive('getAllSiteIds')->andReturnUsing(fn () => array_keys($this->sites))->byDefault();
		$sites->shouldReceive('getEditableSiteIds')->andReturnUsing(fn () => $this->editableSiteIds ?? array_keys($this->sites))->byDefault();
		$sites->shouldReceive('getPrimarySite')->andReturnUsing(fn () => array_values(array_filter($this->sites, fn (Site $site) => $site->primary))[0])->byDefault();
		$sites->shouldReceive('getCurrentSite')->andReturnUsing(fn () => array_values(array_filter($this->sites, fn (Site $site) => $site->primary))[0])->byDefault();
		Craft::$app->set('sites', $sites);

		/** @var Entries&MockInterface $entries */
		$entries = Mockery::mock(Entries::class)->makePartial();
		$entries->shouldReceive('getAllSections')->andReturnUsing(fn () => array_values($this->sections))->byDefault();
		$entries->shouldReceive('getAllEntryTypes')->andReturnUsing(fn () => array_values($this->entryTypes))->byDefault();
		$entries->shouldReceive('getSectionByHandle')->andReturnUsing(fn ($handle) => $this->findBy($this->sections, 'handle', $handle))->byDefault();
		$entries->shouldReceive('getSectionById')->andReturnUsing(fn ($id) => $this->sections[$id] ?? null)->byDefault();
		$entries->shouldReceive('getEntryTypeById')->andReturnUsing(fn ($id) => $this->entryTypes[$id] ?? null)->byDefault();
		$entries->shouldReceive('getEntryTypeByUid')->andReturnUsing(fn ($uid) => $this->findBy($this->entryTypes, 'uid', $uid))->byDefault();
		$entries->shouldReceive('getEntryTypeByHandle')->andReturnUsing(fn ($handle) => $this->findBy($this->entryTypes, 'handle', $handle))->byDefault();
		Craft::$app->set('entries', $entries);

		/** @var Fields&MockInterface $fields */
		$fields = Mockery::mock(Fields::class)->makePartial();
		$fields->shouldReceive('getAllFields')->andReturnUsing(fn () => array_values($this->fields))->byDefault();
		$fields->shouldReceive('getFieldById')->andReturnUsing(fn ($id) => $this->fields[$id] ?? null)->byDefault();
		$fields->shouldReceive('getFieldByUid')->andReturnUsing(fn ($uid) => $this->findBy($this->fields, 'uid', $uid))->byDefault();
		$fields->shouldReceive('getAllLayouts')->andReturnUsing(fn () => [
			...array_map(fn (EntryType $entryType) => $entryType->getFieldLayout(), array_values($this->entryTypes)),
			...array_map(fn (Volume $volume) => $volume->getFieldLayout(), array_values($this->volumes)),
			...$this->layouts,
		])->byDefault();
		Craft::$app->set('fields', $fields);

		/** @var Volumes&MockInterface $volumes */
		$volumes = Mockery::mock(Volumes::class)->makePartial();
		$volumes->shouldReceive('getAllVolumes')->andReturnUsing(fn () => array_values($this->volumes))->byDefault();
		$volumes->shouldReceive('getVolumeByHandle')->andReturnUsing(fn ($handle) => $this->findBy($this->volumes, 'handle', $handle))->byDefault();
		Craft::$app->set('volumes', $volumes);

		foreach (['categories' => [Categories::class, 'getAllGroups'], 'tags' => [Tags::class, 'getAllTagGroups'], 'globals' => [Globals::class, 'getAllSets']] as $id => [$class, $method]) {
			$service = Mockery::mock($class)->makePartial();
			$service->shouldReceive($method)->andReturn([])->byDefault();
			Craft::$app->set($id, $service);
		}

		/** @var Elements&MockInterface $elements */
		$elements = Mockery::mock(Elements::class)->makePartial();
		$elements->shouldReceive('canSave')->andReturnUsing(fn () => $this->canSave)->byDefault();
		Craft::$app->set('elements', $elements);

		/** @var UserComponent&MockInterface $user */
		$user = Mockery::mock(UserComponent::class)->makePartial();
		$user->shouldReceive('getIsAdmin')->andReturnUsing(fn () => $this->isAdmin)->byDefault();
		$user->shouldReceive('checkPermission')->andReturnUsing(fn (string $permission) => $this->permissions === null || in_array($permission, $this->permissions, true))->byDefault();
		$user->shouldReceive('getIdentity')->andReturn(null)->byDefault();
		Craft::$app->set('user', $user);
	}

	/**
	 * @param string[]|null $permissions all when null
	 */
	protected function actingAs(bool $admin = false, ?array $permissions = null, bool $canSave = true): void
	{
		$this->isAdmin = $admin;
		$this->permissions = $permissions;
		$this->canSave = $canSave;
	}

	protected function site(int $id, string $handle, bool $primary = false): Site
	{
		return $this->sites[$id] = new Site([
			'id' => $id,
			'uid' => "site-$handle",
			'handle' => $handle,
			'name' => ucfirst($handle),
			'language' => 'en-US',
			'primary' => $primary,
			'hasUrls' => true,
			'baseUrl' => "https://example.test/$handle",
		]);
	}

	/**
	 * @param FieldInterface[] $fields custom fields of the layout
	 */
	protected function entryType(int $id, string $handle, array $fields = [], ?string $name = null): EntryType
	{
		$entryType = new EntryType([
			'id' => $id,
			'uid' => "entry-type-$handle",
			'handle' => $handle,
			'name' => $name ?? ucfirst($handle),
		]);
		$entryType->setFieldLayout($this->layout(Entry::class, "entry-type-$handle", $fields));

		return $this->entryTypes[$id] = $entryType;
	}

	/**
	 * @param EntryType[] $entryTypes
	 * @param array<int,bool> $urls site ID => whether entries have URLs on the site
	 */
	protected function section(int $id, string $handle, string $type = Section::TYPE_CHANNEL, array $entryTypes = [], array $urls = [1 => true], ?string $name = null): Section
	{
		$section = new Section([
			'id' => $id,
			'uid' => "section-$handle",
			'handle' => $handle,
			'name' => $name ?? ucfirst($handle),
			'type' => $type,
		]);
		$section->setEntryTypes($entryTypes);
		$section->setSiteSettings(array_map(fn (int $siteId, bool $hasUrls) => new Section_SiteSettings([
			'siteId' => $siteId,
			'hasUrls' => $hasUrls,
			'uriFormat' => $hasUrls ? "$handle/{slug}" : null,
		]), array_keys($urls), $urls));

		return $this->sections[$id] = $section;
	}

	/**
	 * @template T of FieldInterface
	 * @param class-string<T> $class
	 * @param array<string,mixed> $config
	 * @return T
	 */
	protected function field(string $class, int $id, string $handle, array $config = []): FieldInterface
	{
		/** @var T $field */
		$field = Craft::createObject([
			'class' => $class,
			'id' => $id,
			'uid' => "field-$handle",
			'handle' => $handle,
			'name' => $config['name'] ?? ucfirst($handle),
			...$config,
		]);

		return $this->fields[$id] = $field;
	}

	/**
	 * @param FieldInterface[] $fields custom fields of the layout
	 */
	protected function volume(int $id, string $handle, bool $hasUrls = true, array $fields = [], ?string $name = null): Volume
	{
		$volume = new Volume([
			'id' => $id,
			'uid' => "volume-$handle",
			'handle' => $handle,
			'name' => $name ?? ucfirst($handle),
		]);
		$volume->setFs(new Local(['handle' => "fs-$handle", 'name' => ucfirst($handle) . ' Files', 'hasUrls' => $hasUrls, 'url' => $hasUrls ? "https://example.test/$handle" : null]));
		$volume->setFieldLayout($this->layout(Asset::class, "volume-$handle", $fields));

		return $this->volumes[$id] = $volume;
	}

	/**
	 * @param class-string $type element type of the layout
	 * @param FieldInterface[] $fields custom fields placed in it, a field placed twice when listed twice
	 */
	protected function layout(string $type, string $uid, array $fields = []): FieldLayout
	{
		$layout = new FieldLayout(['type' => $type, 'uid' => "layout-$uid"]);
		$layout->setTabs([
			new FieldLayoutTab([
				'layout' => $layout,
				'name' => 'Content',
				'elements' => array_map(fn (FieldInterface $field) => new CustomField($field), $fields),
			]),
		]);

		return $layout;
	}

	/**
	 * @template T of object
	 * @param array<int,T> $items
	 * @return T|null
	 */
	private function findBy(array $items, string $property, mixed $value): ?object
	{
		foreach ($items as $item) {
			if ($item->$property === $value) {
				return $item;
			}
		}

		return null;
	}
}
