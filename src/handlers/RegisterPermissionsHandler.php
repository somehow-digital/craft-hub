<?php

namespace SomehowDigital\Craft\Hub\handlers;

use Craft;
use craft\events\RegisterUserPermissionsEvent;
use SomehowDigital\Craft\Hub\Hub;

class RegisterPermissionsHandler
{
	public function __invoke(RegisterUserPermissionsEvent $event): void
	{
		$event->permissions[] = [
			'heading' => Craft::t('hub', 'Hub'),
			'permissions' => [
				Hub::PERMISSION_ACCESS => [
					'label' => Craft::t('hub', 'Access the content structure overview'),
					'nested' => [
						Hub::PERMISSION_MANAGE_LABELS => [
							'label' => Craft::t('hub', 'Manage labels'),
						],
					],
				],
			],
		];
	}
}
