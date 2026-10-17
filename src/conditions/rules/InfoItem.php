<?php

namespace SomehowDigital\Craft\Hub\conditions\rules;

use craft\base\FieldInterface;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\Volume;
use SomehowDigital\Craft\Hub\models\EntryTypeInfo;
use SomehowDigital\Craft\Hub\models\FieldInfo;
use SomehowDigital\Craft\Hub\models\SectionInfo;
use SomehowDigital\Craft\Hub\models\VolumeInfo;

/**
 * The section, entry type, field or volume Hub knows something about.
 */
trait InfoItem
{
	protected function item(SectionInfo|EntryTypeInfo|FieldInfo|VolumeInfo $info): Section|EntryType|FieldInterface|Volume
	{
		return match (true) {
			$info instanceof SectionInfo => $info->section,
			$info instanceof EntryTypeInfo => $info->entryType,
			$info instanceof FieldInfo => $info->field,
			default => $info->volume,
		};
	}
}
