<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Handler;


use VDM\Component\JoomEngineMcp\Administrator\Contract\HandlerInterface;
use VDM\Component\JoomEngineMcp\Administrator\Contract\PrincipalInterface;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\ActionException;


/**
 * Execute a registered native action only inside the actual local-console boundary.
 *
 * @since 0.1.0
 */
final class NativeHandler implements HandlerInterface
{
	/** @var NativeFactory Reviewed native primitive factory. @since 0.1.0 */
	private NativeFactory $factory;

	/** @param NativeFactory $factory Injected native factory. @since 0.1.0 */
	public function __construct(NativeFactory $factory)
	{
		$this->factory = $factory;
	}

	/** @inheritDoc */
	public function execute(array $arguments, array $binding, PrincipalInterface $principal): array
	{
		if (PHP_SAPI !== 'cli' || !$principal->isLocal() || $principal->getTrack() !== 'cli' || ($binding['track'] ?? '') !== 'cli')
		{
			throw new OperationException('LOCAL_CONSOLE_REQUIRED', 'Native execution requires the actual local Joomla console.');
		}

		$level = ob_get_level();
		ob_start(static function (string $buffer): string
		{
			return '';
		}, 4096);

		try
		{
			return $this->factory->create($binding)->execute($arguments);
		}
		catch (ActionException $error)
		{
			throw new OperationException($error->errorCode, $error->getMessage());
		}
		finally
		{
			while (ob_get_level() > $level)
			{
				ob_end_clean();
			}
		}
	}
}
