<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Field;


use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use VDM\Component\JoomEngineMcp\Administrator\Database\Structure;


/**
 * Native relation field restricted to known catalogue tables and administrators.
 *
 * @since  0.1.0
 */
final class DefinitionreferenceField extends ListField
{
	/** @var string Joomla form field type. @since 0.1.0 */
	protected $type = 'Definitionreference';

	/** @inheritDoc */
	protected function getOptions()
	{
		$entity = (string) $this->element['entity'];
		$options = [HTMLHelper::_('select.option', '', Text::_('COM_JOOMENGINE_MCP_CHOOSE_REFERENCE'))];

		if (!isset(Structure::definitions()[$entity]) || !$this->getCurrentUser()->authorise('core.admin', 'com_joomengine_mcp'))
		{
			return $options;
		}

		$db = $this->getDatabase();
		$query = $db->createQuery()->select($db->quoteName(['id', 'title', 'name']))
			->from($db->quoteName(Structure::table($entity)))->where($db->quoteName('published') . ' <> -2')
			->order($db->quoteName('title') . ' ASC');

		foreach ($db->setQuery($query, 0, 20000)->loadObjectList() as $row)
		{
			$options[] = HTMLHelper::_('select.option', $row->id, $row->title . ' — ' . $row->name);
		}

		return array_merge($options, parent::getOptions());
	}
}
