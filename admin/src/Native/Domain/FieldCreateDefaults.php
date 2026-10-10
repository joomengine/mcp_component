<?php
/**
 * @package    JoomEngine.Mcp
 * @created    10 October 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Native\Domain;


/**
 * Complete omitted native field-create form values without replacing explicit data.
 *
 * Parameter defaults match Joomla 6.1.4 administrator com_fields/forms/field.xml.
 * Empty parameter groups are filtered away and cannot supply the non-null SQL column.
 *
 * @since [[[NEXT_VERSION]]]
 */
final class FieldCreateDefaults
{
	/** @var array<string,string> Native form defaults, retaining form string types. @since [[[NEXT_VERSION]]] */
	private const PARAMS = ['show_on' => '', 'showlabel' => '1', 'display' => '2', 'display_readonly' => '2', 'searchindex' => '0'];

	/** @param array $data Validated native create data. @return array Completed detached form values. @since [[[NEXT_VERSION]]] */
	public static function complete(array $data): array
	{
		return $data + ['default_value' => '', 'description' => '', 'params' => self::PARAMS];
	}
}
