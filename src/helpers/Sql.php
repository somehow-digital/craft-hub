<?php

namespace SomehowDigital\Craft\Hub\helpers;

use Craft;

/**
 * What differs between the databases Craft runs on.
 */
final class Sql
{
	/**
	 * Operator matching text regardless of its case, which `like` does on MySQL only.
	 */
	public static function likeOperator(): string
	{
		return Craft::$app->getDb()->getIsPgsql() ? 'ilike' : 'like';
	}
}
