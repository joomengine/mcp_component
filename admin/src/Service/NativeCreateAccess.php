<?php
/**
 * @package    JoomEngine.Mcp
 * @created    9 November 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Service;


use VDM\Component\JoomEngineMcp\Administrator\Contract\PrincipalInterface;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;


/**
 * Check native article/banner create ACL before claiming an outbound mutation.
 *
 * The component's API adapter forwards the current Joomla identity's token.
 * This preflight does not replace the native controller's final authorization.
 *
 * @since 1.0.12
 */
final class NativeCreateAccess
{
	/**
	 * Reject a known missing native permission before execution or lease creation.
	 *
	 * @param array<string,mixed> $binding Current API binding.
	 * @param array<string,mixed> $request Validated outgoing request.
	 * @param PrincipalInterface $principal Current authenticated Joomla authority.
	 * @return void
	 * @since 1.0.12
	 */
	public static function requireAllowed(array $binding, array $request, PrincipalInterface $principal): void
	{
		if ($principal->isLocal() || $principal->getTrack() !== 'api'
			|| ($binding['track'] ?? '') !== 'api' || ($binding['handler'] ?? '') !== 'api.request'
			|| ($binding['configuration']['authentication'] ?? 'joomla-api-token') !== 'joomla-api-token'
			|| ($request['method'] ?? '') !== 'POST')
		{
			return;
		}

		$component = match ($request['path'] ?? '')
		{
			'/v1/content/articles' => 'com_content',
			'/v1/banners' => 'com_banners',
			default => null,
		};

		if ($component === null)
		{
			return;
		}

		$category = $request['body']['catid'] ?? null;
		$asset = (is_int($category) || (is_string($category) && ctype_digit($category))) && (int) $category > 0
			? $component . '.category.' . (int) $category : $component;

		foreach ([['core.manage', $component], ['core.create', $asset]] as [$action, $target])
		{
			if (!$principal->authorise($action, $target))
			{
				throw new OperationException('NATIVE_CREATE_DENIED',
					'The Joomla API user needs Access Administration Interface (core.manage) for the component and Create (core.create) for the target category. No mutation was submitted; Super Users membership is not required.',
					['action' => $action, 'asset' => $target, 'mutationSubmitted' => false]);
			}
		}
	}
}
