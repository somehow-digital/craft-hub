<?php

namespace SomehowDigital\Craft\Hub\handlers\activity;

use Craft;
use craft\base\PluginInterface;
use craft\elements\User;
use craft\events\ConfigEvent;
use craft\events\PluginEvent;
use craft\helpers\ArrayHelper;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\records\GqlToken as GqlTokenRecord;
use craft\services\Categories;
use craft\services\Entries;
use craft\services\Fields;
use craft\services\ImageTransforms;
use craft\services\Plugins;
use craft\services\ProjectConfig;
use craft\services\Sites;
use craft\services\Tags;
use craft\services\UserGroups;
use craft\services\Volumes;
use SomehowDigital\Craft\Hub\enums\ActivityAction;
use SomehowDigital\Craft\Hub\enums\ActivitySubject;
use yii\base\Event;
use yii\db\AfterSaveEvent;
use yii\db\BaseActiveRecord;

/**
 * What holds the content, the settings and plugins, as saved or as project config changes applied from the YAML files, recorded as a batch.
 */
class ConfigActivity extends ActivitySubscriber
{
	/** attributes of records Craft sets as they're used or saved */
	private const IGNORED_RECORD_ATTRIBUTES = ['id', 'uid', 'dateCreated', 'dateUpdated', 'lastUsed', 'dateLastUsed'];

	/** events of the things holding the content, as the service, the save and delete events, the event's property and the kind */
	private const CONFIG_EVENTS = [
		[Entries::class, Entries::EVENT_AFTER_SAVE_SECTION, Entries::EVENT_AFTER_DELETE_SECTION, 'section', ActivitySubject::Section],
		[Entries::class, Entries::EVENT_AFTER_SAVE_ENTRY_TYPE, Entries::EVENT_AFTER_DELETE_ENTRY_TYPE, 'entryType', ActivitySubject::EntryType],
		[Fields::class, Fields::EVENT_AFTER_SAVE_FIELD, Fields::EVENT_AFTER_DELETE_FIELD, 'field', ActivitySubject::Field],
		[Volumes::class, Volumes::EVENT_AFTER_SAVE_VOLUME, Volumes::EVENT_AFTER_DELETE_VOLUME, 'volume', ActivitySubject::Volume],
		[UserGroups::class, UserGroups::EVENT_AFTER_SAVE_USER_GROUP, UserGroups::EVENT_AFTER_DELETE_USER_GROUP, 'userGroup', ActivitySubject::UserGroup],
		[Sites::class, Sites::EVENT_AFTER_SAVE_SITE, Sites::EVENT_AFTER_DELETE_SITE, 'site', ActivitySubject::Site],
		[Sites::class, Sites::EVENT_AFTER_SAVE_SITE_GROUP, Sites::EVENT_AFTER_DELETE_SITE_GROUP, 'group', ActivitySubject::SiteGroup],
		[Categories::class, Categories::EVENT_AFTER_SAVE_GROUP, Categories::EVENT_AFTER_DELETE_GROUP, 'categoryGroup', ActivitySubject::CategoryGroup],
		[Tags::class, Tags::EVENT_AFTER_SAVE_GROUP, Tags::EVENT_AFTER_DELETE_GROUP, 'tagGroup', ActivitySubject::TagGroup],
		[ImageTransforms::class, ImageTransforms::EVENT_AFTER_SAVE_IMAGE_TRANSFORM, ImageTransforms::EVENT_AFTER_DELETE_IMAGE_TRANSFORM, 'imageTransform', ActivitySubject::ImageTransform],
	];

	/** project config items without events of their own, as the path holding them and their kind */
	private const CONFIG_ITEMS = [
		ProjectConfig::PATH_FS => ActivitySubject::Filesystem,
		ProjectConfig::PATH_ROUTES => ActivitySubject::Route,
		ProjectConfig::PATH_GRAPHQL_SCHEMAS => ActivitySubject::GqlSchema,
	];

	/** project config paths of the system's settings, by the name of their settings page */
	private const CONFIG_SETTINGS = [
		ProjectConfig::PATH_SYSTEM => 'General Settings',
		'email' => 'Email Settings',
		ProjectConfig::PATH_USERS => 'User Settings',
		ProjectConfig::PATH_ADDRESSES => 'Addresses',
	];

	/** settings within the paths of the system's settings that are recorded otherwise */
	private const IGNORED_SETTINGS = ['schemaVersion', 'groups'];

	/** events of the plugins, by the action they are */
	private const PLUGIN_EVENTS = [
		Plugins::EVENT_AFTER_INSTALL_PLUGIN => ActivityAction::Installed,
		Plugins::EVENT_AFTER_UNINSTALL_PLUGIN => ActivityAction::Uninstalled,
		Plugins::EVENT_AFTER_ENABLE_PLUGIN => ActivityAction::Enabled,
		Plugins::EVENT_AFTER_DISABLE_PLUGIN => ActivityAction::Disabled,
		Plugins::EVENT_AFTER_SAVE_PLUGIN_SETTINGS => ActivityAction::Updated,
	];

	/** project config events, by the action they are */
	private const PROJECT_CONFIG_EVENTS = [
		ProjectConfig::EVENT_ADD_ITEM => ActivityAction::Created,
		ProjectConfig::EVENT_UPDATE_ITEM => ActivityAction::Updated,
		ProjectConfig::EVENT_REMOVE_ITEM => ActivityAction::Deleted,
	];

	/** whether project config changes are applied from the YAML files, recorded as a batch */
	private bool $applying = false;

	/** @var array<string,true> things recorded while the project config is applied, which may change by several paths */
	private array $applied = [];

	public function register(): void
	{
		foreach (self::CONFIG_EVENTS as [$class, $save, $delete, $property, $subject]) {
			// Each event holds its model in a property of its own.
			Event::on($class, $save, fn (Event $event) => $this->config(ArrayHelper::getValue($event, 'isNew') ? ActivityAction::Created : ActivityAction::Updated, $subject, ArrayHelper::getValue($event, $property)));
			Event::on($class, $delete, fn (Event $event) => $this->config(ActivityAction::Deleted, $subject, ArrayHelper::getValue($event, $property)));
		}

		foreach (self::PROJECT_CONFIG_EVENTS as $name => $action) {
			Event::on(ProjectConfig::class, $name, fn (ConfigEvent $event) => $this->configChanged($action, $event));
		}

		Event::on(ProjectConfig::class, ProjectConfig::EVENT_AFTER_APPLY_CHANGES, fn () => $this->configApplied());

		Event::on(GqlTokenRecord::class, BaseActiveRecord::EVENT_AFTER_INSERT, fn (AfterSaveEvent $event) => $this->token(ActivityAction::Created, $event));
		Event::on(GqlTokenRecord::class, BaseActiveRecord::EVENT_AFTER_UPDATE, fn (AfterSaveEvent $event) => $this->token(ActivityAction::Updated, $event));
		Event::on(GqlTokenRecord::class, BaseActiveRecord::EVENT_AFTER_DELETE, fn (Event $event) => $this->token(ActivityAction::Deleted, $event));

		foreach (self::PLUGIN_EVENTS as $name => $action) {
			Event::on(Plugins::class, $name, fn (PluginEvent $event) => $this->plugin($action, $event->plugin));
		}
	}

	/**
	 * Changes of the things holding the content, along with the others, while they're applied from the project config files.
	 *
	 * @param object{id:int|null,name:string|null} $model
	 */
	private function config(ActivityAction $action, ActivitySubject $subject, object $model): void
	{
		$this->applying();

		$this->activity()->record($action, $subject, $model->id ?? null, $model->name ?? $model->handle ?? null);
	}

	/**
	 * Project config changes without events of their own: the permissions of user groups, items like filesystems and
	 * routes, the system's settings, and the schema versions of Craft and plugins, which change as they're updated.
	 * Changes applied from the project config files may come in by any path within an item or settings, so they change
	 * its item, or the setting at its path.
	 */
	private function configChanged(ActivityAction $action, ConfigEvent $event): void
	{
		$applying = $this->applying();
		$path = $event->path;

		if (preg_match('/^users\.groups\.([^.]+)\.permissions$/', $path, $matches)) {
			$group = Craft::$app->getUserGroups()->getGroupByUid($matches[1]);
			$this->permissionsChanged(ActivitySubject::UserGroup, $group?->id, $group?->name, (array)($event->oldValue ?? []), (array)($event->newValue ?? []));

			return;
		}

		if (!$applying && $action === ActivityAction::Updated && $path === ProjectConfig::PATH_SCHEMA_VERSION) {
			$this->activity()->record(ActivityAction::Updated, ActivitySubject::System, null, 'Craft CMS', ['version' => Craft::$app->getVersion()]);

			return;
		}

		if (!$applying && $action === ActivityAction::Updated && preg_match('/^plugins\.([^.]+)\.schemaVersion$/', $path, $matches)) {
			$plugin = Craft::$app->getPlugins()->getPlugin($matches[1]);
			$this->activity()->record(ActivityAction::Updated, ActivitySubject::Plugin, null, $plugin->name ?? $matches[1], array_filter(['handle' => $matches[1], 'version' => $plugin?->getVersion()]));

			return;
		}

		foreach (self::CONFIG_ITEMS as $prefix => $subject) {
			if (str_starts_with($path, "$prefix.")) {
				$this->configItem($subject, $prefix, $path, $action, $event);

				return;
			}
		}

		if (str_starts_with($path, ProjectConfig::PATH_USER_GROUPS)) {
			return;
		}

		if ($applying && preg_match('/^plugins\.([^.]+)\.settings(\.|$)/', $path, $matches)) {
			$this->pluginSettingsApplied($matches[1]);

			return;
		}

		foreach (self::CONFIG_SETTINGS as $prefix => $title) {
			if ($path === $prefix || str_starts_with($path, "$prefix.")) {
				$this->settingsChanged($prefix, $title, $path, $event, $applying);

				return;
			}
		}
	}

	/**
	 * The system's settings changed, by the keys of the settings within them. While the project config is applied, they
	 * may change by several paths, so all that change are told by the first.
	 */
	private function settingsChanged(string $prefix, string $title, string $path, ConfigEvent $event, bool $applying): void
	{
		$projectConfig = Craft::$app->getProjectConfig();

		$keys = match (true) {
			$applying => $this->changedKeys($projectConfig->get($prefix), $projectConfig->get($prefix, true)),
			$path === $prefix => $this->changedKeys($event->oldValue, $event->newValue),
			default => [explode('.', substr($path, strlen($prefix) + 1))[0]],
		};
		$keys = array_values(array_diff($keys, self::IGNORED_SETTINGS));

		if ($keys !== [] && $this->once("settings:$prefix")) {
			$this->activity()->record(ActivityAction::Updated, ActivitySubject::Settings, null, Craft::t('app', $title), ['attributes' => $keys]);
		}
	}

	/**
	 * Settings of plugins are saved without an event of their own while the project config is applied.
	 */
	private function pluginSettingsApplied(string $handle): void
	{
		if ($this->once("plugin:$handle")) {
			$plugin = Craft::$app->getPlugins()->getPlugin($handle);
			$this->activity()->record(ActivityAction::Updated, ActivitySubject::Plugin, null, $plugin->name ?? $handle, ['handle' => $handle]);
		}
	}

	/**
	 * An item of the project config, by its key within the path holding the items, named by its config.
	 */
	private function configItem(ActivitySubject $subject, string $prefix, string $path, ActivityAction $action, ConfigEvent $event): void
	{
		[$key] = explode('.', substr($path, strlen($prefix) + 1));
		$itemPath = "$prefix.$key";

		// Changes within an item are updates of it.
		if ($path !== $itemPath) {
			$action = ActivityAction::Updated;
			$config = Craft::$app->getProjectConfig()->get($itemPath);
		} else {
			$config = $event->newValue ?? $event->oldValue;
		}

		if (!$this->once($itemPath)) {
			return;
		}

		$config = is_array($config) ? $config : [];
		$id = $subject === ActivitySubject::GqlSchema ? Craft::$app->getGql()->getSchemaByUid($key)?->id : null;

		$this->activity()->record($action, $subject, $id, $subject === ActivitySubject::Route ? $this->routeTitle($config) : (is_string($config['name'] ?? null) ? $config['name'] : $key));
	}

	/**
	 * Routes are named by their URI, as given in their settings, with the names of their tokens.
	 *
	 * @param array<string,mixed> $config
	 */
	private function routeTitle(array $config): ?string
	{
		$parts = array_map(fn (mixed $part) => is_array($part) ? '<' . ($part[0] ?? '') . '>' : (string)$part, (array)($config['uriParts'] ?? []));

		return $parts !== [] ? implode('', $parts) : null;
	}

	/**
	 * Keys of the settings that changed between two configs.
	 *
	 * @return string[]
	 */
	private function changedKeys(mixed $old, mixed $new): array
	{
		$old = is_array($old) ? $old : [];
		$new = is_array($new) ? $new : [];

		return array_values(array_filter(
			array_unique([...array_keys($old), ...array_keys($new)]),
			fn (string|int $key) => ProjectConfigHelper::encodeValueAsString($old[$key] ?? null) !== ProjectConfigHelper::encodeValueAsString($new[$key] ?? null),
		));
	}

	/**
	 * GraphQL tokens, named by their name, which are saved again as they're used.
	 */
	private function token(ActivityAction $action, Event $event): void
	{
		$record = $event->sender;

		if (!$record instanceof GqlTokenRecord) {
			return;
		}

		$changes = $event instanceof AfterSaveEvent ? array_values(array_diff(array_keys($event->changedAttributes), self::IGNORED_RECORD_ATTRIBUTES)) : [];

		if ($action === ActivityAction::Updated && $changes === []) {
			return;
		}

		$this->applying();

		$this->activity()->record($action, ActivitySubject::GqlToken, $action !== ActivityAction::Deleted ? (int)$record->id : null, $record->name, $action === ActivityAction::Updated ? ['attributes' => $changes] : []);
	}

	private function plugin(ActivityAction $action, PluginInterface $plugin): void
	{
		$this->applying();

		$this->activity()->record($action, ActivitySubject::Plugin, null, $plugin->name ?? $plugin->handle, ['handle' => $plugin->handle]);
	}

	/**
	 * Begins the batch of the project config changes applied from the YAML files, as soon as they're applied.
	 *
	 * @return bool whether they're applied
	 */
	private function applying(): bool
	{
		if (!Craft::$app->getProjectConfig()->getIsApplyingExternalChanges()) {
			return false;
		}

		if (!$this->applying) {
			$this->applying = true;
			$this->activity()->beginBatch(ActivityAction::Applied, ActivitySubject::ProjectConfig, Craft::t('app', 'Project Config'));
		}

		return true;
	}

	private function configApplied(): void
	{
		if ($this->applying) {
			$this->applying = false;
			$this->applied = [];
			$this->activity()->endBatch();
		}
	}

	/**
	 * Whether something is recorded for the first time while the project config is applied, as it may change by several
	 * paths then; always otherwise.
	 */
	private function once(string $key): bool
	{
		if (!$this->applying) {
			return true;
		}

		if (isset($this->applied[$key])) {
			return false;
		}

		return $this->applied[$key] = true;
	}
}
