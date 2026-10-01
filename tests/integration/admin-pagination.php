<?php
/**
 * @package    JoomEngine.Mcp
 * @created    30 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Session\Session;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\DispatcherInterface;
use Joomla\Input\Input;
use Joomla\Registry\Registry;
use Joomla\Session\SessionInterface;
use VDM\Component\JoomEngineMcp\Administrator\Database\JoomlaStore;
use VDM\Component\JoomEngineMcp\Administrator\Database\Structure;
use VDM\Component\JoomEngineMcp\Administrator\Model\DefinitionsModel;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;

// Buffer output until the native administrator session has started its headers.
ob_start();
require __DIR__ . '/bootstrap.php';
$console = $app;
$db = $container->get(DatabaseInterface::class);
$admin = $container->get(UserFactoryInterface::class)->loadUserByUsername('mcp_test_admin');
$store = new JoomlaStore($db);
$checks = 0;
$messages = [];
$check = static function (bool $condition, string $name) use (&$checks, &$messages): void
{
	if (!$condition)
	{
		throw new RuntimeException($name);
	}

	$checks++;
	$messages[] = 'PASS ' . $name;
};
$check($admin->id > 0 && $admin->authorise('core.admin', 'com_joomengine_mcp'), 'Native pagination administrator identity');

// Use Joomla's real administrator session and real application for every request.
// Fresh MVC models must populate their own state from nested SearchTools input.
$container->alias('session', 'session.web.administrator')->alias(Session::class, 'session.web.administrator')
	->alias(\Joomla\Session\Session::class, 'session.web.administrator')->alias(SessionInterface::class, 'session.web.administrator');
$app = new AdministratorApplication(new Input(), clone $container->get('config'), null, $container);
Factory::$application = $app;
$session = $container->get('session.web.administrator');
$app->setSession($session);
$session->start();
$session->set('registry', new Registry());
$app->loadIdentity($admin);
$check($app->isClient('administrator') && $session->isActive(), 'Real administrator application and active native session');

/** Submit an administrator request, retaining native session state between calls. */
$request = static function (string $view, array $values) use ($container, $session, $admin, $console, $check): array
{
	$input = new Input(['option' => 'com_joomengine_mcp', 'view' => $view] + $values);
	$application = new AdministratorApplication($input, clone $container->get('config'), null, $container);
	$application->setSession($session);
	$application->setDispatcher($container->get(DispatcherInterface::class));
	$application->loadLanguage($console->getLanguage());
	$application->loadIdentity($admin);
	Factory::$application = $application;
	$factory = $application->bootComponent('com_joomengine_mcp')->getMVCFactory();
	$model = $factory->createModel(ucfirst($view), 'Administrator', ['ignore_request' => false]);
	$model->setCurrentUser($admin);
	$state = $model->getState()->toArray();
	$items = $model->getItems();
	$check(is_array($items), $view . ' request returns native database items');

	return ['ids' => array_map(static fn ($item): int => (int) $item->id, $items),
		'limit' => (int) $state['list.limit'], 'start' => (int) $state['list.start'],
		'total' => (int) $model->getTotal(), 'state' => $state,
		'paginationStart' => (int) $model->getPagination()->limitstart, 'model' => $model];
};

/** Compare the actual SQL page and populated offset against a known row sequence. */
$page = static function (array $actual, array $all, int $offset, int $limit, string $label) use ($check): void
{
	$check($actual['start'] === $offset && $actual['limit'] === $limit, $label . ' native offset and limit');
	$check($actual['total'] === count($all), $label . ' native database total');
	$check($actual['ids'] === array_slice($all, $offset, $limit), $label . ' native SQL row IDs');
	$check($actual['paginationStart'] === $offset, $label . ' native pagination matches displayed rows');

	if ($actual['model'] instanceof DefinitionsModel)
	{
		$check((int) $actual['model']->getFilterForm()->getValue('limit', 'list') === $limit,
			$label . ' selected page size matches the effective row limit');
	}
};

$marker = 'pagination.' . bin2hex(random_bytes(8));
$definitions = array_keys(Structure::definitions());
$operations = ['execution', 'job', 'artifact', 'grant', 'audit'];
$fixtures = [];
$ids = [];
$stateIds = [];
$empty = ['published' => '', 'access' => '', 'provider_id' => ''];
$list = ['fullordering' => 'a.id ASC', 'limit' => 20];

try
{
	// Clone installed catalogue rows so every view has three real database pages.
	// These are inert fixture definitions; no handler or native write is dispatched.
	foreach ($definitions as $entity)
	{
		$seed = $store->find($entity, [], 1)[0] ?? null;

		if (!is_array($seed))
		{
			// Optional catalogue types, such as prompts, can legitimately have no seed.
			// Supply every portable column explicitly, including required text/JSON.
			$seed = [];

			foreach (Structure::columns($entity) as $name => $type)
			{
				if ($name === 'id')
				{
					continue;
				}

				$seed[$name] = match (true)
				{
					$type === 'ref:provider' => $ids['provider'][0],
					$type === 'ref:schema' => $ids['schema'][0],
					$type === 'ref:action' => $ids['action'][0],
					str_starts_with($type, 'optional:') || in_array($type, ['date', 'nullable_int'], true) => null,
					in_array($type, ['int', 'published', 'access'], true) => 0,
					$type === 'version' => 1,
					$type === 'json' => '{}',
					default => '',
				};
			}
		}

		$check(is_array($seed), 'Complete inert ' . $entity . ' definition fixture template');
		unset($seed['id']);

		for ($index = 0; $index < 60; $index++)
		{
			$row = $seed;
			$row['asset_id'] = 0;
			$row['name'] = $marker . '.' . $entity . '.' . sprintf('%03d', $index);
			$row['title'] = $marker . ($index < 30 ? '.group-a' : '.group-b') . '.' . sprintf('%03d', $index);
			$row['published'] = 1;
			$row['access'] = 1;
			$row['ordering'] = $index;
			$row['checked_out'] = null;
			$row['checked_out_time'] = null;
			$row['seed_revision'] = '';
			$row['seed_hash'] = '';
			$row['customized'] = 1;

			if ($entity !== 'provider')
			{
				$row['provider_id'] = $ids['provider'][0];
			}

			if ($entity === 'resource')
			{
				$row['uri'] = 'joomla://pagination/' . $marker . '/' . $index;
			}

			$id = $store->insert($entity, $row);
			$ids[$entity][] = $id;
			$fixtures[$entity][$id] = $row;
		}
	}

	foreach ($definitions as $entity)
	{
		$view = $entity . 's';
		$session->set('registry', new Registry());
		$filters = ['search' => $marker] + $empty;

		foreach ([0, 20, 40, 20, 0, 40] as $index => $offset)
		{
			$actual = $request($view, ['filter' => $filters, 'list' => $list, 'limitstart' => $offset]);
			$page($actual, $ids[$entity], $offset, 20, $view . ' navigation ' . $index);
		}

		$check($actual['model']->getFilterForm() instanceof Form, $view . ' uses installed native SearchTools form');
		$check(($actual['model']->getActiveFilters()['search'] ?? null) === $marker, $view . ' search appears in native active filters');
		// Retained nested filters/list state must also support a navigation-only request.
		$page($request($view, ['limitstart' => 20]), $ids[$entity], 20, 20, $view . ' retained session navigation');
		$page($request($view, ['filter' => $filters, 'list' => ['fullordering' => 'a.id ASC', 'limit' => 10], 'limitstart' => 40]),
			$ids[$entity], 40, 10, $view . ' smaller page size');
		$page($request($view, ['filter' => $filters, 'list' => ['fullordering' => 'a.id ASC', 'limit' => 30], 'limitstart' => 40]),
			$ids[$entity], 30, 30, $view . ' larger page size aligns native offset');
		$page($request($view, ['filter' => $filters, 'list' => $list, 'limitstart' => -20]),
			$ids[$entity], 0, 20, $view . ' negative offset is bounded');
		$bounded = $request($view, ['filter' => $filters, 'list' => ['fullordering' => 'a.id ASC', 'limit' => 750], 'limitstart' => 750]);
		$check($bounded['limit'] === 500 && $bounded['start'] === 500, $view . ' oversized page aligns to effective bound');
		$all = $request($view, ['filter' => $filters, 'list' => ['fullordering' => 'a.id ASC', 'limit' => 0], 'limitstart' => 20]);
		$page($all, $ids[$entity], 0, 500, $view . ' legacy all-items request selects the explicit maximum');
		$page($request($view, []), $ids[$entity], 0, 500, $view . ' normalized page size survives a later request');

		// Changed filters reset a stale submitted page, but identical filters do not.
		$search = ['search' => $marker . '.group-a'] + $empty;
		$page($request($view, ['filter' => $search, 'list' => $list, 'limitstart' => 40]),
			array_slice($ids[$entity], 0, 30), 0, 20, $view . ' changed search resets page');
		$page($request($view, ['filter' => $search, 'list' => $list, 'limitstart' => 20]),
			array_slice($ids[$entity], 0, 30), 20, 20, $view . ' unchanged search retains requested page');

		foreach (array_slice($ids[$entity], 0, 20) as $id)
		{
			$store->update($entity, ['published' => 0], ['id' => $id]);
		}

		$page($request($view, ['filter' => $filters, 'list' => $list, 'limitstart' => 0]),
			$ids[$entity], 0, 20, $view . ' publication test restores other filters');
		$published = ['search' => $marker, 'published' => '1', 'access' => '', 'provider_id' => ''];
		$page($request($view, ['filter' => $published, 'list' => $list, 'limitstart' => 40]),
			array_slice($ids[$entity], 20), 0, 20, $view . ' changed publication resets page');
		$page($request($view, ['filter' => $published, 'list' => $list, 'limitstart' => 20]),
			array_slice($ids[$entity], 20), 20, 20, $view . ' unchanged publication retains requested page');

		foreach (array_slice($ids[$entity], 0, 20) as $id)
		{
			$store->update($entity, ['access' => 2], ['id' => $id]);
		}

		$page($request($view, ['filter' => $filters, 'list' => $list, 'limitstart' => 0]),
			$ids[$entity], 0, 20, $view . ' access test restores other filters');
		$access = ['search' => $marker, 'published' => '', 'access' => '1', 'provider_id' => ''];
		$page($request($view, ['filter' => $access, 'list' => $list, 'limitstart' => 40]),
			array_slice($ids[$entity], 20), 0, 20, $view . ' changed access resets page');
		$page($request($view, ['filter' => $access, 'list' => $list, 'limitstart' => 20]),
			array_slice($ids[$entity], 20), 20, 20, $view . ' unchanged access retains requested page');

		if ($entity !== 'provider')
		{
			foreach (array_slice($ids[$entity], 0, 20) as $id)
			{
				$store->update($entity, ['provider_id' => $ids['provider'][1]], ['id' => $id]);
			}

			$page($request($view, ['filter' => $filters, 'list' => $list, 'limitstart' => 0]),
				$ids[$entity], 0, 20, $view . ' provider test restores other filters');
			$provider = ['search' => $marker, 'published' => '', 'access' => '', 'provider_id' => (string) $ids['provider'][0]];
			$page($request($view, ['filter' => $provider, 'list' => $list, 'limitstart' => 40]),
				array_slice($ids[$entity], 20), 0, 20, $view . ' changed provider resets page');
			$page($request($view, ['filter' => $provider, 'list' => $list, 'limitstart' => 20]),
				array_slice($ids[$entity], 20), 20, 20, $view . ' unchanged provider retains requested page');
		}

		$idSearch = ['search' => 'id:' . $ids[$entity][25]] + $empty;
		$page($request($view, ['filter' => $idSearch, 'list' => $list, 'limitstart' => 40]),
			[$ids[$entity][25]], 0, 20, $view . ' changed exact ID search resets page');
	}

	// Separate list views retain their own nested filters, ordering, size and page.
	$session->set('registry', new Registry());
	$page($request('tools', ['filter' => ['search' => $marker] + $empty, 'list' => $list, 'limitstart' => 0]), $ids['tool'], 0, 20, 'Tools isolated initial view');
	$page($request('tools', ['filter' => ['search' => $marker] + $empty, 'list' => $list, 'limitstart' => 40]), $ids['tool'], 40, 20, 'Tools isolated third page');
	$page($request('schemas', ['filter' => ['search' => $marker . '.group-a'] + $empty, 'list' => ['fullordering' => 'a.id ASC', 'limit' => 10], 'limitstart' => 0]),
		array_slice($ids['schema'], 0, 30), 0, 10, 'Schemas isolated search and page size');
	$page($request('schemas', ['limitstart' => 20]), array_slice($ids['schema'], 0, 30), 20, 10, 'Schemas isolated navigation');
	$page($request('tools', []), $ids['tool'], 40, 20, 'Returning to Tools retains its own page and filters');

	// Operational rows are inert metadata with no worker dispatch or credentials.
	foreach ($operations as $kind)
	{
		for ($index = 0; $index < 60; $index++)
		{
			$row = [];

			foreach (Structure::columns($kind) as $name => $type)
			{
				if ($name === 'id')
				{
					continue;
				}

				$row[$name] = match ($type)
				{
					'int', 'bigint' => 0,
					'version' => 1,
					'uuid' => Json::uuid(),
					'hash' => hash('sha256', $marker . '.' . $name . '.' . $index),
					'json' => '{}',
					default => '',
				};
			}

			if (isset($row['status']))
			{
				$row['status'] = 'completed';
			}

			$stateIds[$kind][] = $store->insert($kind, $row);
		}
	}

	$allOperationIds = [];

	foreach ($operations as $kind)
	{
		$allOperationIds[$kind] = array_map('intval', $db->setQuery($db->createQuery()->select($db->quoteName('id'))
			->from($db->quoteName(Structure::table($kind)))->order($db->quoteName('id') . ' DESC'))->loadColumn());
		$session->set('registry', new Registry());

		// The installed operations footer posts top-level limit; SearchTools lists post list[limit].
		foreach ([0, 20, 40, 20, 0] as $index => $offset)
		{
			$page($request('operations', ['kind' => $kind, 'limit' => 20, 'limitstart' => $offset]),
				$allOperationIds[$kind], $offset, 20, $kind . ' native footer navigation ' . $index);
		}

		$end = (int) floor((count($allOperationIds[$kind]) - 1) / 20) * 20;
		$page($request('operations', ['kind' => $kind, 'limit' => 20, 'limitstart' => $end]),
			$allOperationIds[$kind], $end, 20, $kind . ' native end navigation');
		$page($request('operations', ['kind' => $kind, 'list' => ['limit' => 30], 'limitstart' => 40]),
			$allOperationIds[$kind], 30, 30, $kind . ' nested limit aligns page');
		$page($request('operations', ['kind' => $kind, 'list' => ['limit' => 20], 'limitstart' => -20]),
			$allOperationIds[$kind], 0, 20, $kind . ' negative offset is bounded');
		$bounded = $request('operations', ['kind' => $kind, 'list' => ['limit' => 250], 'limitstart' => 250]);
		$check($bounded['limit'] === 100 && $bounded['start'] === 200, $kind . ' oversized page aligns to effective bound');
		$all = $request('operations', ['kind' => $kind, 'list' => ['limit' => 0], 'limitstart' => 20]);
		$check($all['limit'] === 1 && $all['start'] === 0, $kind . ' all-items request stays bounded');
	}

	$session->set('registry', new Registry());
	$page($request('operations', ['kind' => 'execution', 'limit' => 20, 'limitstart' => 40]),
		$allOperationIds['execution'], 40, 20, 'Execution remembers independent third page');
	$page($request('operations', ['kind' => 'audit', 'limit' => 20, 'limitstart' => 0]),
		$allOperationIds['audit'], 0, 20, 'Audit begins at its independent first page');
	$page($request('operations', ['kind' => 'audit', 'limit' => 20, 'limitstart' => 20]),
		$allOperationIds['audit'], 20, 20, 'Audit remembers independent second page');
	$page($request('operations', ['kind' => 'execution']), $allOperationIds['execution'], 40, 20, 'Execution page survives Audit navigation');
	$page($request('operations', ['kind' => 'audit']), $allOperationIds['audit'], 20, 20, 'Audit page survives Execution navigation');
	$invalid = $request('operations', ['kind' => 'invalid', 'limit' => 20, 'limitstart' => 0, 'filter' => ['kind' => 'audit']]);
	$check($invalid['state']['filter.kind'] === 'execution', 'Invalid operation kind and nested filter cannot select another table');
}
finally
{
	foreach ($stateIds as $kind => $values)
	{
		$store->remove($kind, ['id' => ['in', $values]]);
		$check($store->find($kind, ['id' => ['in', $values]], 1) === [], 'Cleanup only owned ' . $kind . ' pagination rows');
	}

	foreach (array_reverse($definitions) as $entity)
	{
		if (isset($ids[$entity]))
		{
			$store->remove($entity, ['id' => ['in', $ids[$entity]]]);
			$check($store->find($entity, ['id' => ['in', $ids[$entity]]], 1) === [], 'Cleanup only owned ' . $entity . ' pagination rows');
		}
	}

	$session->destroy();
	Factory::$application = $console;
	ob_end_clean();
	echo implode("\n", $messages) . "\n";
}

echo json_encode(['checks' => $checks, 'joomla' => JVERSION, 'database' => $db->getServerType(),
	'liveAdministratorRequests' => true, 'definitionViews' => 8, 'operationKinds' => 5], JSON_THROW_ON_ERROR) . "\n";
