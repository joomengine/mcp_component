<?php
/**
 * @package    JoomEngine.Mcp
 * @created    21 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Rule;


use InvalidArgumentException;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormRule;
use Joomla\Registry\Registry;
use SimpleXMLElement;
use VDM\Component\JoomEngineMcp\Administrator\Service\Settings;


/**
 * Apply the runtime's trusted path validation during native administrator saves.
 *
 * @since 0.1.1
 */
final class RuntimepathRule extends FormRule
{
	/** @inheritDoc */
	public function test(SimpleXMLElement $element, $value, $group = null, ?Registry $input = null, ?Form $form = null)
	{
		$name = (string) $element['name'];

		if (!in_array($name, ['php_cli_binary', 'artifact_directory'], true) || !is_string($value))
		{
			return false;
		}

		try
		{
			new Settings([$name => $value]);

			return true;
		}
		catch (InvalidArgumentException)
		{
			return false;
		}
	}
}
