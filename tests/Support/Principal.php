<?php
/**
 * @package    JoomEngine.Mcp
 * @created    17 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Tests\Support;


use VDM\Component\JoomEngineMcp\Administrator\Contract\PrincipalInterface;


/**
 * Mutable test authority for authorization/revocation contracts, not runtime use.
 *
 * @since 0.1.0
 */
final class Principal implements PrincipalInterface
{
	/** @var string Test identity. @since 0.1.0 */
	private string $id;

	/** @var string Test execution track. @since 0.1.0 */
	private string $track;

	/** @var int[] Current test viewing levels. @since 0.1.0 */
	private array $levels;

	/** @var array<string,bool> Explicit test permission denials. @since 0.1.0 */
	private array $denials = [];

	/** @param string $id Test principal. @param string $track API or CLI fixture. @param int[] $levels Authorized viewing levels. @since 0.1.0 */
	public function __construct(string $id = 'joomla:42', string $track = 'api', array $levels = [1, 7])
	{
		$this->id = $id;
		$this->track = $track;
		$this->levels = $levels;
	}

	/** @inheritDoc */
	public function getId(): string
	{
		return $this->id;
	}

	/** @inheritDoc */
	public function getTrack(): string
	{
		return $this->track;
	}

	/** @inheritDoc */
	public function isLocal(): bool
	{
		return $this->track === 'cli';
	}

	/** @inheritDoc */
	public function getViewLevels(): array
	{
		return $this->levels;
	}

	/** @inheritDoc */
	public function authorise(string $action, string $asset): bool
	{
		return !isset($this->denials[$action . ':' . $asset]) && !isset($this->denials[$action . ':*']);
	}

	/** @param string $action Permission to revoke in this fixture. @param string $asset Optional asset. @return void @since 0.1.0 */
	public function deny(string $action, string $asset = '*'): void
	{
		$this->denials[$action . ':' . $asset] = true;
	}
}
