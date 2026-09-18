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
use VDM\Component\JoomEngineMcp\Administrator\Database\JoomlaStore;
use VDM\Component\JoomEngineMcp\Administrator\Security\Envelope;


/**
 * POST-only administrator consent-management endpoints with native CSRF checks.
 *
 * @since  0.1.0
 */
final class OperationsController extends BaseController
{
	/** @var ?Operations Injected administrator operations service. @since 0.1.0 */
	private ?Operations $operations = null;

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
