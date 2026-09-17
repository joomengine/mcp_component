<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Security;


use Joomla\CMS\Application\ApiApplication;
use Joomla\CMS\Authentication\Authentication;
use Joomla\CMS\Authentication\AuthenticationResponse;
use Joomla\CMS\Event\User\AuthenticationEvent;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Event\Dispatcher;
use RuntimeException;


/**
 * Reuse Joomla's token plugin, including its account, group and token checks.
 *
 * Joomla's API route has already authenticated. This second, isolated token-only
 * check prevents another API authentication mechanism from substituting for the
 * explicit token boundary or supplying an unrelated credential for forwarding.
 *
 * @since 0.1.0
 */
final class ApiCredential
{
	/** @var InputFilter Joomla's native token header normalization. @since 0.1.0 */
	private InputFilter $filter;

	/** @param InputFilter $filter Native Joomla input filter. @since 0.1.0 */
	public function __construct(InputFilter $filter)
	{
		$this->filter = $filter;
	}

	/** @param ApiApplication $application Already-authenticated Joomla API application. @return string Verified current token, never persisted. @since 0.1.0 */
	public function resolve(ApiApplication $application): string
	{
		$user = $application->getIdentity();
		$dispatcher = new Dispatcher();
		PluginHelper::importPlugin('api-authentication', 'token', true, $dispatcher);
		$response = new AuthenticationResponse();
		$dispatcher->dispatch('onUserAuthenticate', new AuthenticationEvent('onUserAuthenticate', [
			'credentials' => ['username' => ''], 'options' => ['action' => 'core.login.api'], 'subject' => $response,
		]));

		if ($user === null || (int) $user->id < 1 || $response->status !== Authentication::STATUS_SUCCESS
			|| $response->type !== 'Token' || $response->username !== $user->username)
		{
			throw new RuntimeException('A valid Joomla API token is required.', 401);
		}

		$server = $application->getInput()->server;
		$header = $server->get('HTTP_AUTHORIZATION', '', 'string');

		if ($header === '' && PHP_SAPI === 'apache2handler' && function_exists('apache_request_headers'))
		{
			$headers = apache_request_headers();
			$header = is_array($headers) ? (string) ($this->filter->clean(array_change_key_case($headers, CASE_LOWER)['authorization'] ?? '', 'STRING')) : '';
		}

		if ($header === '')
		{
			$header = $server->get('REDIRECT_HTTP_AUTHORIZATION', '', 'string');
		}

		$token = str_starts_with($header, 'Bearer ') ? $this->filter->clean(trim(substr($header, 7)), 'BASE64') : '';
		$token = $token !== '' ? $token : $server->get('HTTP_X_JOOMLA_TOKEN', '', 'string');

		if (!is_string($token) || $token === '' || strlen($token) > 8192 || preg_match('/[\x00-\x20\x7f]/', $token) === 1)
		{
			throw new RuntimeException('A valid Joomla API token is required.', 401);
		}

		return $token;
	}
}
