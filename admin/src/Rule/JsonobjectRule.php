<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Rule;


use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormRule;
use Joomla\Registry\Registry;
use SimpleXMLElement;


/**
 * Validates inert JSON-object form content without evaluating templates or code.
 *
 * @since  0.1.0
 */
final class JsonobjectRule extends FormRule
{
	/** @inheritDoc */
	public function test(SimpleXMLElement $element, $value, $group = null, ?Registry $input = null, ?Form $form = null)
	{
		if (!is_string($value) || strlen($value) > 1048576)
		{
			return false;
		}

		try
		{
			return json_decode($value, false, 64, JSON_THROW_ON_ERROR) instanceof \stdClass;
		}
		catch (\JsonException)
		{
			return false;
		}
	}
}
