<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\CMS\Table\Asset;
use Joomla\Database\DatabaseInterface;
use VDM\Component\JoomEngineMcp\Administrator\Database\JoomlaStore;
use VDM\Component\JoomEngineMcp\Administrator\Database\Structure;
use VDM\Component\JoomEngineMcp\Administrator\Installer\SeedUpdater;

require __DIR__ . '/bootstrap.php';
$app->bootComponent('com_joomengine_mcp');
$db = $container->get(DatabaseInterface::class);
$store = new JoomlaStore($db);
$seed = json_decode(file_get_contents(JPATH_COMPONENT . '/data/catalogue-seed.json'), true, 128, JSON_THROW_ON_ERROR);
$checks = 0;
foreach ($seed['entities'] as $entity => $records)
{
	foreach ($records as $expected)
	{
		$actual = $store->one($entity, ['name' => $expected['name']]);
		if ($actual === null || !hash_equals($actual['seed_hash'], SeedUpdater::hash($entity, $actual)))
		{
			throw new RuntimeException('Native SQL installation changed the shipped definition: ' . $entity . '/' . $expected['name']);
		}
		$checks++;
		foreach (Structure::columns($entity) as $field => $type)
		{
			if ($type === 'json')
			{
				json_decode($actual[$field], false, 128, JSON_THROW_ON_ERROR);
				if ($actual[$field] !== $expected[$field])
				{
					throw new RuntimeException('Installed JSON/source bytes differ: ' . $entity . '/' . $field);
				}
				$checks++;
			}
		}
		$asset = new Asset($db);
		if (!$asset->loadByName('com_joomengine_mcp.' . $entity . '.' . (int) $actual['id'])
			|| (int) $asset->id !== (int) $actual['asset_id'] || (int) $asset->lft >= (int) $asset->rgt)
		{
			throw new RuntimeException('A shipped definition has no valid native row asset.');
		}
		$checks++;
	}
}
echo json_encode(['checks' => $checks, 'seedRoundTrip' => 'exact', 'joomla' => JVERSION, 'database' => $db->getServerType(), 'live' => true], JSON_THROW_ON_ERROR) . "\n";
