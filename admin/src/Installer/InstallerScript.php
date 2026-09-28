<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Installer;


use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Version;
use Joomla\Database\DatabaseInterface;
use RuntimeException;
use Throwable;
use VDM\Component\JoomEngineMcp\Administrator\Database\JoomlaStore;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


/**
 * Native Joomla installer owning the component's catalogue assets.
 *
 * Update preserves operator settings, custom rows and ACL.
 * The distributed archive already contains its complete PHP dependency tree.
 *
 * @since 0.1.0
 */
final class InstallerScript implements InstallerScriptInterface
{
	/** @var DatabaseInterface Joomla database. @since 0.1.0 */
	private DatabaseInterface $database;
	/** @var CMSApplicationInterface Native installation application. @since 0.1.0 */
	private CMSApplicationInterface $application;

	/** @param DatabaseInterface $database Database. @param CMSApplicationInterface $application Installation application. @since 0.1.0 */
	public function __construct(DatabaseInterface $database, CMSApplicationInterface $application)
	{
		$this->database = $database;
		$this->application = $application;
	}

	/** @inheritDoc */
	public function preflight(string $type, InstallerAdapter $adapter): bool
	{
		if ($type === 'uninstall')
		{
			return true;
		}

		try
		{
			$version = (new Version())->getShortVersion();

			if (version_compare(PHP_VERSION, '8.3.0', '<') || version_compare($version, '6.1.0', '<') || version_compare($version, '7.0.0', '>='))
			{
				throw new RuntimeException('JoomEngine MCP requires Joomla 6.1–6.x and PHP 8.3 or later.');
			}

			foreach (['curl', 'json', 'mbstring', 'sodium', 'fileinfo'] as $extension)
			{
				if (!extension_loaded($extension))
				{
					throw new RuntimeException('Enable the required PHP extension: ' . $extension);
				}
			}

			$source = $adapter->getParent()->getPath('source');

			foreach (['admin/autoload.php', 'admin/vendor/autoload.php', 'admin/data/catalogue-seed.json'] as $file)
			{
				if (!is_file($source . '/' . $file))
				{
					throw new RuntimeException('The component source ZIP is incomplete; required file is missing: ' . $file);
				}
			}

			$current = $this->component();

			if ($current !== null)
			{
				$manifest = json_decode($current['manifest_cache'], true, 32, JSON_THROW_ON_ERROR);

				if (version_compare((string) $adapter->getManifest()->version, $manifest['version'] ?? '0', '<'))
				{
					throw new RuntimeException('Downgrading the installed MCP component is not supported. Restore a matching full-site backup instead.');
				}
			}

			return true;
		}
		catch (Throwable $error)
		{
			$this->application->enqueueMessage($error instanceof RuntimeException ? $error->getMessage() : 'The MCP installation preflight failed.', 'error');

			return false;
		}
	}

	/** @inheritDoc */
	public function install(InstallerAdapter $adapter): bool
	{
		return true;
	}

	/** @inheritDoc */
	public function update(InstallerAdapter $adapter): bool
	{
		return true;
	}

	/** @inheritDoc */
	public function postflight(string $type, InstallerAdapter $adapter): bool
	{
		if ($type === 'uninstall')
		{
			return true;
		}

		try
		{
			require_once JPATH_ADMINISTRATOR . '/components/com_joomengine_mcp/autoload.php';
			$store = new JoomlaStore($this->database);

			if ($type !== 'install')
			{
				$seed = Json::decode(file_get_contents(JPATH_ADMINISTRATOR . '/components/com_joomengine_mcp/data/catalogue-seed.json'), true, 16777216);
				(new SeedUpdater($store))->apply($seed);
			}

			(new Assets($this->database, $store))->synchronize();
			$this->application->enqueueMessage('JoomEngine MCP is installed. Configure its canonical API URL and Joomla permissions in Components → JoomEngine MCP → Options.');

			return true;
		}
		catch (Throwable $error)
		{
			// Joomla intentionally ignores a false postflight return. Throwing lets its
			// adapter abort and roll back instead of reporting a broken runtime as installed.
			throw new RuntimeException('The MCP installation could not complete; Joomla is rolling back this installation.', 0, $error);
		}
	}

	/** @inheritDoc */
	public function uninstall(InstallerAdapter $adapter): bool
	{
		return true;
	}

	/** @return ?array<string,mixed> Installed component record. @since 0.1.0 */
	private function component(): ?array
	{
		$db = $this->database;
		$query = $db->createQuery()->select('*')->from($db->quoteName('#__extensions'))
			->where($db->quoteName('type') . ' = ' . $db->quote('component'))
			->where($db->quoteName('element') . ' = ' . $db->quote('com_joomengine_mcp'));

		return $db->setQuery($query)->loadAssoc() ?: null;
	}
}
