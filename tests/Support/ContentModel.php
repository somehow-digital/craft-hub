<?php

namespace SomehowDigital\Craft\Hub\Tests\Support;

use craft\elements\User;
use craft\fields\Assets as AssetsField;
use craft\fields\Entries as EntriesField;
use craft\fields\Link;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\models\Section;

/**
 * A content model with sections, entry types, fields and a volume used in all the ways Hub tells apart,
 * and the counts the database holds for them.
 *
 * - News (channel, URLs on all sites): Article entries, referenced through `related`
 * - Pages (structure, URLs on the first site): Page entries, and Article as an entry type without entries
 * - Settings (single, no URLs): a stale Page entry
 * - Archive (channel, no URLs): Empty, an entry type without entries
 * - Block: nested in `blocks`, Orphan: not used anywhere
 * - `cta` links to entries of any section, `unusedText` and `teaserImg` aren't placed in any layout
 */
trait ContentModel
{
	protected function createContentModel(): void
	{
		$this->site(2, 'de');

		$summary = $this->field(PlainText::class, 1, 'summary', ['instructions' => 'A short summary.']);
		$block = $this->entryType(12, 'block', [$summary]);
		$blocks = $this->field(Matrix::class, 2, 'blocks', [
			'entryTypes' => [$block],
			'siteSettings' => ['site-en' => ['uriFormat' => '{owner.uri}/{slug}']],
		]);
		$related = $this->field(EntriesField::class, 3, 'related', ['sources' => ['section:section-news']]);
		$cta = $this->field(Link::class, 4, 'cta', ['types' => ['entry', 'url'], 'name' => 'Call to Action']);
		$image = $this->field(AssetsField::class, 5, 'image', ['sources' => '*']);
		$this->field(PlainText::class, 6, 'unusedText');
		$this->field(Matrix::class, 7, 'legacy');
		$teaserImage = $this->field(AssetsField::class, 9, 'teaserImage', ['sources' => ['volume:volume-images']]);
		$this->field(AssetsField::class, 10, 'teaserImg');

		$article = $this->entryType(10, 'article', [$summary, $blocks, $related, $image, $teaserImage]);
		$page = $this->entryType(11, 'page', [$summary, $cta, $summary]);
		$this->entryType(13, 'orphan');
		$empty = $this->entryType(14, 'empty');

		$this->section(1, 'news', Section::TYPE_CHANNEL, [$article], [1 => true, 2 => true]);
		$this->section(2, 'pages', Section::TYPE_STRUCTURE, [$page, $article], [1 => true, 2 => false]);
		$this->section(3, 'settings', Section::TYPE_SINGLE, [$page], [1 => false]);
		$this->section(4, 'archive', Section::TYPE_CHANNEL, [$empty], [1 => false]);

		$this->volume(1, 'images', fields: [$summary]);

		$this->layouts[] = $this->layout(User::class, 'users', [$summary]);
		// Layouts of element types that no longer exist are left out.
		$this->layouts[] = $this->layout('Missing\\Element', 'missing', [$summary]);

		$this->db()->respondToEntryRows([
			['sectionId' => 1, 'typeId' => 10, 'fieldId' => null, 'total' => 5, 'enabled' => 4, 'drafts' => 1, 'lastUpdated' => (new \DateTime('-1 month'))->format('Y-m-d H:i:s')],
			['sectionId' => 2, 'typeId' => 11, 'fieldId' => null, 'total' => 2, 'enabled' => 2, 'drafts' => 0, 'lastUpdated' => (new \DateTime('-2 months'))->format('Y-m-d H:i:s')],
			['sectionId' => 3, 'typeId' => 11, 'fieldId' => null, 'total' => 1, 'enabled' => 1, 'drafts' => 0, 'lastUpdated' => (new \DateTime('-2 years'))->format('Y-m-d H:i:s')],
			['sectionId' => null, 'typeId' => 12, 'fieldId' => 2, 'total' => 7, 'enabled' => 7, 'drafts' => 0, 'lastUpdated' => (new \DateTime('-1 month'))->format('Y-m-d H:i:s')],
		]);

		// Only the relations by field and target, not the relations of single elements.
		$this->db()->respond('FROM {{%relations}} {{relations}}', fn (string $sql) => str_contains($sql, 'GROUP BY `relations`.`fieldId`') ? [
			['fieldId' => 3, 'sectionId' => 1, 'volumeId' => null, 'references' => 4, 'sources' => 2, 'targets' => 3],
			['fieldId' => 4, 'sectionId' => 2, 'volumeId' => null, 'references' => 1, 'sources' => 1, 'targets' => 1],
			['fieldId' => 5, 'sectionId' => null, 'volumeId' => 1, 'references' => 3, 'sources' => 3, 'targets' => 2],
		] : []);
	}
}
