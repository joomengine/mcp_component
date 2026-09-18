<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Http;


use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;


/**
 * An HTTP exchange failed without asserting that a remote mutation was rolled back.
 *
 * @since 0.1.0
 */
final class NetworkException extends RuntimeException implements NetworkExceptionInterface
{
	/** @var RequestInterface Original request; never included in a diagnostic message. @since 0.1.0 */
	private RequestInterface $request;

	/** @param RequestInterface $request Failed exchange. @since 0.1.0 */
	public function __construct(RequestInterface $request)
	{
		parent::__construct('The bounded HTTP exchange did not complete. A submitted write may require reconciliation.');
		$this->request = $request;
	}

	/** @return RequestInterface The failed PSR request, as required by PSR-18. @since 0.1.0 */
	public function getRequest(): RequestInterface
	{
		return $this->request;
	}
}
