<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Controller;


use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;
use Joomla\CMS\Factory;
use VDM\Component\JoomEngineMcp\Administrator\Administration\Operations;
use VDM\Component\JoomEngineMcp\Administrator\Contract\RuntimeAwareInterface;
use VDM\Component\JoomEngineMcp\Administrator\Database\JoomlaStore;
use VDM\Component\JoomEngineMcp\Administrator\Security\Envelope;
use VDM\Component\JoomEngineMcp\Administrator\Security\JoomlaPrincipal;
use VDM\Component\JoomEngineMcp\Administrator\Service\RuntimeFactory;


/**
 * POST-only administrator consent-management endpoints with native CSRF checks.
 *
 * @since  0.1.0
 */
final class OperationsController extends BaseController implements RuntimeAwareInterface
{
	/** @var ?Operations Injected administrator operations service. @since 0.1.0 */
	private ?Operations $operations = null;
	/** @var ?RuntimeFactory Explicit catalogue synchronization service. @since 0.1.1 */
	private ?RuntimeFactory $runtime = null;

	/** @inheritDoc */
	public function setRuntimeFactory(RuntimeFactory $runtime): void
	{
		$this->runtime = $runtime;
	}

	/** @param Operations $operations Explicit native MVC service injection. @return void @since 0.1.0 */
	public function setOperations(Operations $operations): void
	{
		$this->operations = $operations;
	}

	/** @return void Revoke one observed grant revision. @since 0.1.0 */
	public function revoke(): void
	{
		$this->requirePost();
		$this->operations->revoke($this->app->getIdentity(), $this->input->post->getInt('id'), $this->input->post->getInt('version'));
		$this->setRedirect(Route::_('index.php?option=com_joomengine_mcp&view=operations&kind=grant', false), Text::_('COM_JOOMENGINE_MCP_REVOKED'));
	}

	/** @return void Record operator-inspected effects without invoking any handler. @since 0.1.0 */
	public function reconcile(): void
	{
		$this->requirePost();
		$this->operations->reconcile($this->app->getIdentity(), $this->input->post->getInt('id'), $this->input->post->getInt('version'),
			$this->input->post->getCmd('outcome'), $this->input->post->getString('note'), $this->input->post->getBool('acknowledged'));
		$this->setRedirect(Route::_('index.php?option=com_joomengine_mcp&view=operations&kind=execution', false), Text::_('COM_JOOMENGINE_MCP_RECONCILED'));
	}

	/** @return void Cancel one observed durable job without replaying its mutation. @since 0.1.1 */
	public function cancelJob(): void
	{
		$this->requirePost();
		$this->operations->cancelJob($this->app->getIdentity(), $this->input->post->getInt('id'), $this->input->post->getInt('version'));
		$this->setRedirect(Route::_('index.php?option=com_joomengine_mcp&view=operations&kind=job', false), Text::_('COM_JOOMENGINE_MCP_JOB_CANCELLATION_REQUESTED'));
	}

	/** @return void Refresh reviewed JCB rows under native administrator authority. @since 0.1.1 */
	public function synchronizeJcb(): void
	{
		$this->requirePost();
		$user = $this->app->getIdentity();

		if ($this->runtime === null || !$user->authorise('core.manage', 'com_joomengine_mcp')
			|| !$user->authorise('core.admin', 'com_joomengine_mcp') || !$user->authorise('core.admin', 'com_componentbuilder'))
		{
			throw new \RuntimeException('Native administration permission is required to synchronize JCB definitions.', 403);
		}

		$this->runtime->synchronizeJcb($this->app, new JoomlaPrincipal($user));
		$this->setRedirect(Route::_('index.php?option=com_joomengine_mcp&view=operations&kind=job', false), Text::_('COM_JOOMENGINE_MCP_JCB_SYNCHRONIZED'));
	}

	/** @return void Validate request boundary and injected dependency. @since 0.1.0 */
	private function requirePost(): void
	{
		if ($this->input->getMethod() !== 'POST' || $this->operations === null)
		{
			throw new \RuntimeException('This operation requires an administrator POST request.', 405);
		}

		$this->checkToken();
	}
}
