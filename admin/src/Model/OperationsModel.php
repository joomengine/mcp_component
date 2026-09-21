<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Model;


use Joomla\CMS\MVC\Model\ListModel;
use Joomla\CMS\Factory;
use VDM\Component\JoomEngineMcp\Administrator\Administration\Operations;
use VDM\Component\JoomEngineMcp\Administrator\Database\JoomlaStore;
use VDM\Component\JoomEngineMcp\Administrator\Database\Structure;
use VDM\Component\JoomEngineMcp\Administrator\Security\Envelope;


/**
 * Bounded read-only state lists which never select credentials or ciphertext.
 *
 * @since  0.1.0
 */
final class OperationsModel extends ListModel
{
	/** @var string Exact extension element; the namespace intentionally has a different spelling. @since 0.1.0 */
	protected $option = 'com_joomengine_mcp';

	/** @inheritDoc */
	protected function populateState($ordering = 'id', $direction = 'desc')
	{
		$kind = Factory::getApplication()->getInput()->getCmd('kind', 'execution');
		$this->setState('filter.kind', in_array($kind, ['execution', 'job', 'artifact', 'grant', 'audit'], true) ? $kind : 'execution');
		parent::populateState('id', 'desc');
		$this->setState('list.limit', max(1, min(100, (int) $this->state->get('list.limit', 20))));
	}

	/** @inheritDoc */
	protected function getStoreId($id = '')
	{
		return parent::getStoreId($id . ':' . $this->getState('filter.kind'));
	}

	/** @inheritDoc */
	protected function getListQuery()
	{
		if (!$this->getCurrentUser()->authorise('mcp.audit', 'com_joomengine_mcp'))
		{
			throw new \RuntimeException('Not authorized to inspect MCP state.', 403);
		}

		$kind = $this->getState('filter.kind', 'execution');
		$columns = match ($kind)
		{
			'execution' => ['id', 'uuid', 'principal_key', 'plan_uuid', 'status', 'created_at', 'updated_at', 'version'],
			'job' => ['id', 'uuid', 'principal_id', 'track', 'action_name', 'execution_uuid', 'status', 'progress', 'message', 'cancel_requested', 'created_at', 'updated_at', 'version'],
			'artifact' => ['id', 'uuid', 'job_uuid', 'name', 'mime_type', 'size', 'sha256', 'created_at', 'expires_at'],
			'grant' => ['id', 'uuid', 'principal_key', 'duration', 'remaining_uses', 'revoked', 'expires_at', 'created_at', 'version'],
			'audit' => ['id', 'uuid', 'actor_id', 'event', 'action_name', 'outcome', 'created_at'],
			default => throw new \RuntimeException('Unknown MCP state list.', 400),
		};
		$db = $this->getDatabase();

		return $db->createQuery()->select($db->quoteName($columns))->from($db->quoteName(Structure::table($kind)))->order($db->quoteName('id') . ' DESC');
	}
}
