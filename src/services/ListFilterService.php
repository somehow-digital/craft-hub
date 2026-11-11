<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\services;

use craft\elements\User;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\Volume;
use craft\services\Entries;
use craft\services\Volumes;
use SomehowDigital\Craft\Hub\enums\CheckKind;
use SomehowDigital\Craft\Hub\enums\DataKind;
use SomehowDigital\Craft\Hub\enums\SectionType;
use SomehowDigital\Craft\Hub\enums\UsageKind;
use SomehowDigital\Craft\Hub\models\EntryFilter;
use SomehowDigital\Craft\Hub\models\EntryStatistics;
use SomehowDigital\Craft\Hub\models\EntryTypeFilter;
use SomehowDigital\Craft\Hub\models\FieldFilter;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\FileFilter;
use SomehowDigital\Craft\Hub\models\FilterParams;
use SomehowDigital\Craft\Hub\models\SavedFilter;
use SomehowDigital\Craft\Hub\models\SectionFilter;
use SomehowDigital\Craft\Hub\models\UserFilter;
use SomehowDigital\Craft\Hub\models\VolumeFilter;
use yii\base\Component;

/**
 * The filters of Hub's lists, set by the query params of their URLs.
 *
 * @internal
 */
final class ListFilterService extends Component
{
	/**
	 * @param array<string,mixed> $config
	 */
	public function __construct(
		private readonly LabelService $labels,
		private readonly FilterService $filters,
		private readonly StructureService $structure,
		private readonly EntryService $entries,
		private readonly VolumeService $volumes,
		private readonly UserService $users,
		private readonly Entries $craftEntries,
		private readonly Volumes $craftVolumes,
		array $config = [],
	) {
		parent::__construct($config);
	}

	/**
	 * The filters of the list of a kind, set by its query params, as they appear in its URL or are kept by a saved filter;
	 * missing and invalid ones don't filter.
	 *
	 * @param array<string,mixed> $query
	 */
	public function createListFilter(CheckKind $kind, array $query): EntryFilter|FileFilter|UserFilter|EntryTypeFilter|SectionFilter|FieldFilter|VolumeFilter
	{
		$params = new FilterParams($query, date_default_timezone_get());
		$filter = match ($kind) {
			CheckKind::Entries => $this->entryFilter($params),
			CheckKind::Assets => $this->fileFilter($params),
			CheckKind::Users => $this->userFilter($params),
			CheckKind::EntryTypes => $this->entryTypeFilter($params),
			CheckKind::Sections => $this->sectionFilter($params),
			CheckKind::Fields => $this->fieldFilter($params),
			CheckKind::Volumes => $this->volumeFilter($params),
		};

		$uid = $params->string(FilterService::PARAM);
		$filter->search = $params->search();
		$filter->labels = $params->list('label', $this->labels->getFilterValues());
		$filter->savedFilter = $uid !== null && $this->filters->getCustomFilter($kind, $uid) instanceof SavedFilter ? $uid : null;

		return $filter;
	}

	private function entryFilter(FilterParams $params): EntryFilter
	{
		$entries = $this->craftEntries;
		$nested = array_filter($this->structure->getFields(), fn(FieldInfo $info): bool => $info->isNested);

		$filter = new EntryFilter();
		$filter->sections = $params->list('section', array_map(fn(Section $section): string => $section->handle ?? '', $entries->getAllSections()));
		$filter->types = $params->list('type', array_map(fn(EntryType $type): string => $type->handle ?? '', $entries->getAllEntryTypes()));
		$filter->origin = $params->choice('origin', [EntryService::ORIGIN_SECTION, EntryService::ORIGIN_NESTED]);
		$filter->fields = $params->list('field', array_map(fn(FieldInfo $info): string => (string) $info->field->handle, $nested));
		$filter->statuses = $params->list('status', $this->entries->getStatuses());
		$filter->authors = $params->users('author');
		$filter->editors = $params->users('editor');
		$filter->usage = $params->choice('usage', [EntryService::USAGE_REFERENCED, EntryService::USAGE_UNREFERENCED]);
		$filter->referencesMin = $params->int('referencesMin');
		$filter->referencesMax = $params->int('referencesMax');
		$filter->postedFrom = $params->date('postedFrom');
		$filter->postedTo = $params->date('postedTo');
		$filter->updatedFrom = $params->date('updatedFrom');
		$filter->updatedTo = $params->date('updatedTo');
		$filter->staleMonths = $params->flag('stale') ? $this->filters->getThreshold(CheckKind::Entries, 'stale') : null;
		$filter->drafts = $params->flag('drafts');
		$filter->myDrafts = $params->flag('myDrafts');
		$filter->comingUp = $params->flag('comingUp');

		return $filter;
	}

	private function fileFilter(FilterParams $params): FileFilter
	{
		$kinds = array_map(strval(...), array_keys($this->volumes->getAllKinds()));

		$filter = new FileFilter();
		$filter->volumes = $params->list('volume', array_map(fn(Volume $volume): string => (string) $volume->handle, $this->craftVolumes->getAllVolumes()));
		$filter->kinds = $params->list('kind', $kinds);
		$filter->extensions = array_values(array_filter(array_map(mb_strtolower(...), $params->list('extension')), fn(string $extension): bool => preg_match('/^[a-z0-9]+$/', $extension) === 1));
		$filter->orientations = $params->list('orientation', FileFilter::getOrientations());
		$filter->uploaders = $params->users('uploader');
		$filter->usage = $params->choice('usage', [FileService::USAGE_USED, FileService::USAGE_UNUSED]);
		$filter->referencesMin = $params->int('referencesMin');
		$filter->referencesMax = $params->int('referencesMax');
		$filter->sizeMin = $params->number('sizeMin');
		$filter->sizeMax = $params->number('sizeMax');
		$filter->widthMin = $params->int('widthMin');
		$filter->widthMax = $params->int('widthMax');
		$filter->heightMin = $params->int('heightMin');
		$filter->heightMax = $params->int('heightMax');
		$filter->uploadedFrom = $params->date('uploadedFrom');
		$filter->uploadedTo = $params->date('uploadedTo');
		$filter->missingAlt = $params->flag('alt');
		$filter->oversized = $params->flag('oversized');

		return $filter;
	}

	private function userFilter(FilterParams $params): UserFilter
	{
		$users = $this->users;
		$groups = array_map(fn(array $info): string => (string) $info['group']->handle, $users->getGroups());

		$filter = new UserFilter();
		$filter->groups = $params->list('group', $groups !== [] ? [...$groups, UserFilter::GROUP_NONE] : []);
		$filter->statuses = $params->list('status', array_keys(User::statuses()));
		$filter->admin = $params->flag('admin');
		$filter->logins = $params->list('login', UserFilter::getLogins());
		$filter->loginFrom = $params->date('loginFrom');
		$filter->loginTo = $params->date('loginTo');
		$filter->createdFrom = $params->date('createdFrom');
		$filter->createdTo = $params->date('createdTo');
		$filter->twoFactor = $params->choice('twoFactor', [UserFilter::TWO_FACTOR_WITH, UserFilter::TWO_FACTOR_WITHOUT]);
		$filter->unverifiedEmail = $params->flag('unverified');
		$filter->entriesMin = $params->int('entriesMin');
		$filter->entriesMax = $params->int('entriesMax');
		$filter->assetsMin = $params->int('assetsMin');
		$filter->assetsMax = $params->int('assetsMax');

		return $filter;
	}

	private function entryTypeFilter(FilterParams $params): EntryTypeFilter
	{
		$filter = new EntryTypeFilter();
		$filter->kinds = $params->list('kind', array_map(fn(UsageKind $kind) => $kind->value, UsageKind::listed()));
		$filter->entriesMin = $params->int('entriesMin');
		$filter->entriesMax = $params->int('entriesMax');
		$filter->relationsMin = $params->int('relationsMin');
		$filter->relationsMax = $params->int('relationsMax');
		$filter->activities = $params->list('activity', EntryStatistics::getActivities());

		return $filter;
	}

	private function sectionFilter(FilterParams $params): SectionFilter
	{
		$filter = new SectionFilter();
		$filter->types = $params->list('type', array_map(fn(SectionType $type) => $type->value, SectionType::cases()));
		$filter->urls = $params->choice('urls', [ListService::URLS_YES, ListService::URLS_NO]);
		$filter->entriesMin = $params->int('entriesMin');
		$filter->entriesMax = $params->int('entriesMax');
		$filter->referencesMin = $params->int('referencesMin');
		$filter->referencesMax = $params->int('referencesMax');
		$filter->activities = $params->list('activity', EntryStatistics::getActivities());
		$filter->empty = $params->flag('empty');

		return $filter;
	}

	private function fieldFilter(FilterParams $params): FieldFilter
	{
		// Field types and layout owners are filtered by their lowercased names.
		$types = [];
		$owners = [];
		foreach ($this->structure->getFields() as $info) {
			$types[] = mb_strtolower($info->getTypeName());
			array_push($owners, ...array_map(mb_strtolower(...), $info->getOwnerTypes()));
		}

		$filter = new FieldFilter();
		$filter->types = $params->list('type', array_values(array_unique($types)));
		$filter->kinds = $params->list('kind', array_map(fn(DataKind $kind) => $kind->value, DataKind::cases()));
		$filter->owners = $params->list('owner', array_values(array_unique($owners)));
		$filter->layoutsMin = $params->int('layoutsMin');
		$filter->layoutsMax = $params->int('layoutsMax');
		$filter->unused = $params->flag('unused');
		$filter->empty = $params->flag('empty');

		return $filter;
	}

	private function volumeFilter(FilterParams $params): VolumeFilter
	{
		$filter = new VolumeFilter();
		$filter->kinds = $params->list('kind', array_map(strval(...), array_keys($this->volumes->getKinds())));
		$filter->urls = $params->choice('urls', [ListService::URLS_YES, ListService::URLS_NO]);
		$filter->assetsMin = $params->int('assetsMin');
		$filter->assetsMax = $params->int('assetsMax');
		$filter->sizeMin = $params->number('sizeMin');
		$filter->sizeMax = $params->number('sizeMax');
		$filter->altMin = $params->int('altMin');
		$filter->altMax = $params->int('altMax');
		$filter->uploadedFrom = $params->date('uploadedFrom');
		$filter->uploadedTo = $params->date('uploadedTo');
		$filter->empty = $params->flag('empty');

		return $filter;
	}
}
