<?php

namespace SomehowDigital\Craft\Hub\models;

use craft\base\Model;
use craft\enums\Color;

/**
 * A label items of all lists can be labeled with, shown by its icon and color next to their names, its label as tooltip.
 */
class Label extends Model
{
	/** names of Craft's icons, as Craft's `iconSvg()` takes anything else for the path or markup of an SVG */
	public const ICON_PATTERN = '/^[\da-z\-]+$/';

	public ?int $id = null;

	public string $name = '';

	/** name of a Craft icon */
	public ?string $icon = null;

	/** one of Craft's colors, see {@see Color} */
	public ?string $color = null;

	public int $sortOrder = 0;

	public ?string $uid = null;

	public function getIcon(): string
	{
		return $this->icon ?: 'tag';
	}

	public function getColor(): string
	{
		return $this->color ?: Color::Gray->value;
	}

	/**
	 * @return array<int,mixed>
	 */
	protected function defineRules(): array
	{
		return [
			[['name'], 'trim'],
			[['name'], 'required'],
			[['name', 'icon'], 'string', 'max' => 255],
			[['icon'], 'match', 'pattern' => self::ICON_PATTERN],
			[['color'], 'in', 'range' => array_map(fn (Color $color) => $color->value, Color::cases())],
		];
	}
}
