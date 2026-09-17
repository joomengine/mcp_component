<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Security;


use InvalidArgumentException;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;


/**
 * Authenticated, context-bound encryption for sensitive durable execution state.
 *
 * @since 0.1.0
 */
final class Envelope
{
	/** @var string Derived symmetric key, never emitted or logged. @since 0.1.0 */
	private string $key;

	/**
	 * Derive a separate MCP key from the Joomla installation secret.
	 *
	 * @param string $secret Joomla's existing installation secret.
	 * @since 0.1.0
	 */
	public function __construct(string $secret)
	{
		if (strlen($secret) < 16 || !function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt'))
		{
			throw new InvalidArgumentException('MCP durable encryption requires sodium and a valid Joomla installation secret.');
		}

		$this->key = hash_hkdf('sha256', $secret, 32, 'joomengine-mcp-envelope-v1');
	}

	/** @param string $plaintext Sensitive value. @param string $context Principal and record purpose. @return string Versioned encrypted envelope. @since 0.1.0 */
	public function encrypt(string $plaintext, string $context): string
	{
		$nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
		$ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $context, $nonce, $this->key);

		return 'v1.' . base64_encode($nonce . $ciphertext);
	}

	/** @param string $envelope Stored encrypted envelope. @param string $context Required principal and purpose. @return string Authenticated plaintext. @since 0.1.0 */
	public function decrypt(string $envelope, string $context): string
	{
		$raw = str_starts_with($envelope, 'v1.') ? base64_decode(substr($envelope, 3), true) : false;
		$length = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

		if ($raw === false || strlen($raw) < $length + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES)
		{
			throw new OperationException('STATE_UNAVAILABLE', 'The encrypted execution state is unavailable.');
		}

		$value = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, $length), $context, substr($raw, 0, $length), $this->key);

		if ($value === false)
		{
			throw new OperationException('STATE_UNAVAILABLE', 'The encrypted execution state is unavailable.');
		}

		return $value;
	}

	/** @param string $value Non-secret token payload. @return string Authentication tag. @since 0.1.0 */
	public function sign(string $value): string
	{
		return hash_hmac('sha256', 'mcp-confirmation-v1:' . $value, $this->key);
	}
}
