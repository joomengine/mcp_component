<?php
/**
 * @package    JoomEngine.Mcp
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Controller;


use Joomla\CMS\MVC\Controller\FormController;


/**
 * Native form controller with separate catalogue-administration authority.
 *
 * Concrete controllers select a fixed entity; native save/cancel, CSRF, checkout
 * and edit-session contracts remain owned by Joomla's FormController.
 *
 * @since  0.1.0
 */
abstract class DefinitionController extends FormController
{
	/** @var string Exact extension element; the namespace intentionally has a different spelling. @since 0.1.0 */
	protected $option = 'com_joomengine_mcp';

	/** @var string Fixed definition identifier. @since 0.1.0 */
	protected const ENTITY = '';

	/** @inheritDoc */
	protected function allowAdd($data = [])
	{
		$user = $this->app->getIdentity();

		return $user->authorise('core.admin', 'com_joomengine_mcp')
			&& $user->authorise('core.create', 'com_joomengine_mcp');
	}

	/** @inheritDoc */
	protected function allowEdit($data = [], $key = 'id')
	{
		$user = $this->app->getIdentity();
		$id = (int) ($data[$key] ?? 0);

		return $id > 0 && $user->authorise('core.admin', 'com_joomengine_mcp')
			&& $user->authorise('core.edit', 'com_joomengine_mcp.' . static::ENTITY . '.' . $id);
	}
}
