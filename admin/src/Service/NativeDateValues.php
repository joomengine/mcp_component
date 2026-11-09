<?php
/**
 * @package    JoomEngine.Mcp
 * @created    9 November 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Service;


use DateTimeImmutable;
use DateTimeZone;
use Throwable;


/**
 * Compare approved absolute calendar values with native user_utc SQL storage.
 *
 * The caller must establish the exact native API bindings. A timezone comes only
 * from the authenticated identity on the same installation, never tool arguments.
 * Relative expressions and malformed dates do not acquire normalized equality.
 *
 * @since 1.0.12
 */
final class NativeDateValues
{
	/** @var array<string,string[]> Reviewed native calendar controls. @since 1.0.12 */
	private const FIELDS = [
		'content.articles' => ['created', 'publish_up', 'publish_down'],
		'modules.site' => ['publish_up', 'publish_down'],
		'modules.administrator' => ['publish_up', 'publish_down'],
	];

	/** @param string $resource Reviewed native resource. @return string[] Calendar controls. @since 1.0.12 */
	public static function fields(string $resource): array
	{
		return self::FIELDS[$resource] ?? [];
	}

	/**
	 * Match only a concrete native UTC instant or a cleared scheduling value.
	 *
	 * @param string $resource Reviewed native resource.
	 * @param string $field Requested native field.
	 * @param mixed $desired Approved input, unchanged before native filtering.
	 * @param mixed $observed Independent native read-back.
	 * @param ?string $timezone Authenticated native user timezone, when proven.
	 * @return ?bool Native equality, or null when context cannot establish it.
	 * @since 1.0.12
	 */
	public static function compare(string $resource, string $field, mixed $desired, mixed $observed, ?string $timezone): ?bool
	{
		if (!in_array($field, self::fields($resource), true))
		{
			return null;
		}

		// Empty created/publish_up can cause a model to select the current time;
		// only publish_down and module schedules have a reviewed clear contract.
		if (($field === 'publish_down' || str_starts_with($resource, 'modules.'))
			&& in_array($desired, ['', null, '0000-00-00 00:00:00'], true))
		{
			return in_array($observed, ['', null, '0000-00-00 00:00:00'], true);
		}

		if (!is_string($desired) || !is_string($observed)
			|| preg_match('/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D', $desired) !== 1
			|| preg_match('/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D', $observed) !== 1)
		{
			return null;
		}

		if ($timezone === null)
		{
			return null;
		}

		try
		{
			$zone = new DateTimeZone($timezone);
			$expected = new DateTimeImmutable($desired, $zone);
			$errors = DateTimeImmutable::getLastErrors();

			if (is_array($errors) && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
			{
				return false;
			}

			$actual = new DateTimeImmutable($observed, new DateTimeZone('UTC'));
			$errors = DateTimeImmutable::getLastErrors();

			return (!is_array($errors) || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
				&& $expected->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') === $actual->format('Y-m-d H:i:s');
		}
		catch (Throwable)
		{
			return false;
		}
	}
}
