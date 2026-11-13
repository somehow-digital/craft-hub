<?php

declare(strict_types=1);

namespace SomehowDigital\Craft\Hub\handlers;

use SomehowDigital\Craft\Hub\handlers\activity\AccountActivity;
use SomehowDigital\Craft\Hub\handlers\activity\ConfigActivity;
use SomehowDigital\Craft\Hub\handlers\activity\ContentActivity;
use SomehowDigital\Craft\Hub\handlers\activity\SystemActivity;

/**
 * Records what users do in the activity log, as Craft's own events tell it, whether it's done in the control panel, on
 * the site or on the command line.
 *
 * Only what users would call a change is recorded: drafts and revisions are left out until they're applied or reverted
 * to, unless drafts are recorded too (see Settings::$activityDrafts), as are nested elements, which are saved along with
 * their owners, and elements saved again or propagated to other sites. Element actions are recorded as a batch of what
 * they changed, as are project config changes applied from the YAML files.
 *
 * @internal
 */
final readonly class ActivityTracker
{
	public function __construct(
		private ContentActivity $content,
		private AccountActivity $account,
		private ConfigActivity $config,
		private SystemActivity $system,
	) {}

	public function register(): void
	{
		$this->content->register();
		$this->account->register();
		$this->config->register();
		$this->system->register();
	}
}
