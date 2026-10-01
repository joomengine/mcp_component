<?php
/**
 * @package    JoomEngine.Mcp
 * @created    1 October 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Service;


use Joomla\CMS\Application\ApiApplication;
use Joomla\Component\Messages\Administrator\Table\MessageTable;
use VDM\Component\JoomEngineMcp\Administrator\Contract\PrincipalInterface;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Security\JoomlaPrincipal;


/**
 * Read one native message table record under Joomla's authenticated API identity.
 *
 * No controller, model getItem, identity switch or console primitive is called.
 * Native table observers remain attached through the component MVC factory.
 *
 * @since 1.0.1
 */
final class JoomlaMessageSnapshot
{
	/** @var ApiApplication Actual authenticated application. @since 1.0.1 */
	private ApiApplication $application;

	/** @param ApiApplication $application Trusted API composition root. @since 1.0.1 */
	public function __construct(ApiApplication $application)
	{
		$this->application = $application;
	}

	/**
	 * Match the configured API origin and mount to this executing installation.
	 *
	 * Forwarded headers are deliberately not authority. Unproven proxy/alias
	 * configurations retain the configured HTTP snapshot path instead.
	 *
	 * @param string $base Server-configured API base.
	 * @param array $server Actual web-server script and request metadata.
	 * @param string $apiScript Server-owned Joomla root API entrypoint.
	 * @return bool The configured API request addresses this native installation.
	 * @since 1.0.1
	 */
	public static function sameInstallation(string $base, array $server, string $apiScript): bool
	{
		$parts = parse_url($base);
		$filename = $server['SCRIPT_FILENAME'] ?? '';
		$name = $server['SCRIPT_NAME'] ?? '';
		$host = $server['HTTP_HOST'] ?? '';
		$https = strtolower((string) ($server['HTTPS'] ?? ''));
		$scheme = $https !== '' && $https !== 'off' && $https !== '0' ? 'https' : 'http';

		if ($parts === false || !is_string($filename) || !is_string($name) || !is_string($host)
			|| realpath($apiScript) === false || realpath($filename) !== realpath($apiScript)
			|| preg_match('~\A(/(?:[^/?#]*/)*api/index\.php)(?:/.*)?\z~D', $name, $script) !== 1
			|| ($parts['scheme'] ?? '') !== $scheme || ($parts['path'] ?? '') !== $script[1])
		{
			return false;
		}

		$port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
		$authority = strtolower($parts['host'] ?? '');
		$allowed = [$authority . ':' . $port];

		if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))
		{
			$allowed[] = $authority;
		}

		return in_array(strtolower($host), $allowed, true);
	}

	/** @param int $id Authorized record ID. @param PrincipalInterface $principal Current request authority. @return array Native fields, or an indistinguishable unavailable result. @since 1.0.1 */
	public function __invoke(int $id, PrincipalInterface $principal): array
	{
		$user = $this->application->getIdentity();
		$actual = new JoomlaPrincipal($user);

		if ($id < 1 || $principal->isLocal() || $principal->getTrack() !== 'api' || $actual->getId() !== $principal->getId())
		{
			throw new OperationException('DEFINITION_UNAVAILABLE', 'The requested message snapshot is unavailable.');
		}

		$table = $this->application->bootComponent('com_messages')->getMVCFactory()->createTable('Message', 'Administrator');

		if (!$table instanceof MessageTable)
		{
			throw new OperationException('SNAPSHOT_UNAVAILABLE', 'The native message table is unavailable.');
		}

		if (!$table->load(['message_id' => $id, 'user_id_to' => (int) $user->id]))
		{
			return [];
		}

		// MessageSnapshot rechecks both identities after native table observers.
		return $table->getProperties();
	}
}
