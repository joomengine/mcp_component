<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Service;


use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;


/**
 * Native installed-extension lookup independent of request or database code paths.
 *
 * @since 0.1.0
 */
final class ExtensionAvailability
{
	/** @var DatabaseInterface Joomla extension registry. @since 0.1.0 */
	private DatabaseInterface $database;

	/** @param DatabaseInterface $database Injected Joomla database. @since 0.1.0 */
	public function __construct(DatabaseInterface $database)
	{
		$this->database = $database;
	}

	/**
	 * Resolve component names and group/element plugin identifiers.
	 *
	 * @param string $name Declarative extension dependency.
	 * @return bool Whether the extension is installed and enabled.
	 * @since 0.1.0
	 */
	public function enabled(string $name): bool
	{
		if (preg_match('/\Acom_[a-z0-9_]+\z/D', $name) === 1)
		{
			$type = 'component';
			$element = $name;
			$folder = '';
		}
		elseif (preg_match('/\A([a-z][a-z0-9_-]*)\/([A-Za-z][A-Za-z0-9_-]*)\z/D', $name, $matches) === 1)
		{
			$type = 'plugin';
			$folder = $matches[1];
			$element = $matches[2];
		}
		else
		{
			return false;
		}

		$db = $this->database;
		$query = $db->createQuery()->select($db->quoteName('extension_id'))->from($db->quoteName('#__extensions'))
			->where($db->quoteName('type') . ' = :extension_type')
			->where($db->quoteName('element') . ' = :extension_element')
			->where($db->quoteName('folder') . ' = :extension_folder')
			->where($db->quoteName('enabled') . ' = 1')
			->where($db->quoteName('state') . ' = 0')
			->bind(':extension_type', $type, ParameterType::STRING)
			->bind(':extension_element', $element, ParameterType::STRING)
			->bind(':extension_folder', $folder, ParameterType::STRING);

		return (int) $db->setQuery($query, 0, 1)->loadResult() > 0;
	}
}
