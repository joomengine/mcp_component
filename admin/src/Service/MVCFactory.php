<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Service;


use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\MVC\Factory\MVCFactory as JoomlaMVCFactory;
use Joomla\Input\Input;
use VDM\Component\JoomEngineMcp\Administrator\Controller\OperationsController;
use VDM\Component\JoomEngineMcp\Administrator\Contract\RuntimeAwareInterface;


/**
 * Joomla MVC factory with explicit component service injection and API fallback.
 *
 * Parent lifecycle, form, router, user, mail, database and dispatcher injection are
 * retained. API models and tables fall back to Administrator like ApiMVCFactory.
 *
 * @since 0.1.0
 */
final class MVCFactory extends JoomlaMVCFactory
{
	/** @var RuntimeFactory Component services. @since 0.1.0 */
	private RuntimeFactory $runtime;

	/** @param RuntimeFactory $runtime Component composition. @since 0.1.0 */
	public function __construct(RuntimeFactory $runtime)
	{
		parent::__construct('VDM\\Component\\JoomEngineMcp');
		$this->runtime = $runtime;
	}

	/** @inheritDoc */
	public function createController($name, $prefix, array $config, CMSApplicationInterface $app, Input $input)
	{
		$controller = $this->inject(parent::createController($name, $prefix, $config, $app, $input));

		if ($controller instanceof OperationsController)
		{
			$controller->setOperations($this->runtime->administration($app));
		}

		return $controller;
	}

	/** @inheritDoc */
	public function createModel($name, $prefix = '', array $config = [])
	{
		$model = parent::createModel($name, $prefix, $config);

		if ($model === null && strtolower($prefix) === 'api')
		{
			$model = parent::createModel($name, 'Administrator', $config);
		}

		return $this->inject($model);
	}

	/** @inheritDoc */
	public function createTable($name, $prefix = '', array $config = [])
	{
		$table = parent::createTable($name, $prefix, $config);

		if ($table === null && strtolower($prefix) === 'api')
		{
			$table = parent::createTable($name, 'Administrator', $config);
		}

		return $this->inject($table);
	}

	/** @param ?object $instance Native MVC result. @return ?object Explicitly injected MVC result. @since 0.1.0 */
	private function inject(?object $instance): ?object
	{
		if ($instance instanceof RuntimeAwareInterface)
		{
			$instance->setRuntimeFactory($this->runtime);
		}

		return $instance;
	}
}
