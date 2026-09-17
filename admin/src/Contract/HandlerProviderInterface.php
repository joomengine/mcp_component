<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Contract;


use Joomla\CMS\Application\CMSApplicationInterface;


/**
 * Reviewed PHP extension provider for genuinely new execution primitives.
 *
 * Providers are injected by Joomla service providers, never named by database
 * content. Implementations must enforce target ACL and declare track limits.
 *
 * @since 0.1.0
 */
interface HandlerProviderInterface
{
	/** @param CMSApplicationInterface $application Actual application. @param PrincipalInterface $principal Authenticated authority. @return array<string,HandlerInterface> Explicit service keys. @since 0.1.0 */
	public function handlers(CMSApplicationInterface $application, PrincipalInterface $principal): array;
}
