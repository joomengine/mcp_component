<?php
/**
 * @package    JoomEngine.Mcp
 * @created    24 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Jcb;


use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


/**
 * Public, allowlisted explanation of immutable JCB command preparations.
 *
 * Native execution remains authoritative for option precedence and global
 * defaults. Keep frozen input layers distinct: a textual zero is not necessarily
 * an effective false in the native command. Repository objects, unknown bundle
 * keys and arbitrary native values may contain secrets and are never published.
 *
 * @since 0.1.1
 */
final class CommandPreview
{
	/**
	 * Build a reviewable preview without loading native services or repository data.
	 *
	 * @param array<string,mixed> $prepared The authorized, immutable prepared payload.
	 * @return array<string,mixed> Public selectors, options, effects and revision hashes.
	 * @since 0.1.1
	 */
	public function describe(array $prepared): array
	{
		$command = (string) $prepared['command'];
		[, $family, $entity] = explode(':', $command);
		$input = $prepared['input'];
		$options = (array) $input['options'];
		$environment = (array) $input['environment'];
		$compile = $command === 'componentbuilder:compile:component';
		$bundle = $compile && isset($options['options']) ? (array) Json::decode($options['options']) : [];
		$environmentOptions = [];

		foreach ($environment as $name => $value)
		{
			$prefix = $compile ? 'JCB_' : 'JCB_GET_';

			if (str_starts_with($name, $prefix))
			{
				$environmentOptions[strtolower(substr($name, strlen($prefix)))] = $value;
			}
		}

		$selectors = [];

		foreach ($input['selectors'] as $selector)
		{
			// Native package selectors may be arbitrary names; do not echo paths/URLs.
			$selectors[] = preg_match('/\A[\p{L}\p{N}][\p{L}\p{N} ._-]{0,190}\z/uD', $selector) === 1
				? ['value' => $selector]
				: ['redacted' => true, 'fingerprint' => hash('sha256', $selector)];
		}

		return [
			'command' => $command,
			'selection' => ['entity' => $entity, 'count' => count($selectors), 'selectors' => $selectors],
			'frozenOptions' => [
				'explicit' => (object) $this->options($options, $compile),
				'bundle' => (object) $this->options($bundle, $compile),
				'environment' => (object) $this->options($environmentOptions, $compile),
				'resolution' => 'Native command precedence and empty-value rules apply; omitted values retain the frozen JCB configuration defaults.',
			],
			'repository' => $this->repository($options['repo'] ?? null),
			'effects' => $this->effects($family, $input),
			'revisions' => [
				'commandContract' => $prepared['contractFingerprint'],
				'implementation' => $prepared['implementation'],
				'definitionsAndConfiguration' => $prepared['snapshot'],
			],
			'dependencies' => [
				'revisionScope' => 'entire-installed-jcb-definition-and-configuration-graph',
				'recheck' => 'The graph and command revisions are checked before execution; changes invalidate this plan.',
				'remoteClosure' => 'Native execution resolves remote dependencies; this preview does not claim an enumerated remote closure.',
			],
		];
	}

	/**
	 * Publish only reviewed option names and safe scalar domains.
	 *
	 * @param array<string,mixed> $values One frozen native option layer.
	 * @param bool $compile Whether compiler rather than package semantics apply.
	 * @return array<string,mixed> Bounded safe values or a redaction marker.
	 * @since 0.1.1
	 */
	private function options(array $values, bool $compile): array
	{
		$allowed = $compile ? [
			'backup' => null, 'repository' => null, 'install' => null, 'compile-install' => null,
			'add-placeholders' => ['0', '1', '2'], 'debug-line-nr' => ['0', '1', '2'],
			'minify' => ['0', '1', '2'], 'powers' => ['0', '1', '2'],
			'joomla-version' => ['3', '4', '5', '6'], 'powers-repository' => ['0', '1', '2'],
			'indentation-value' => ['1', '2', '4'], 'add-build-date' => ['1', '2', '3'], 'build-date' => [],
		] : ['force' => null, 'resolve' => null];
		$result = [];

		foreach ($values as $name => $value)
		{
			$name = strtolower(str_replace('_', '-', trim((string) $name)));

			if (!array_key_exists($name, $allowed))
			{
				continue;
			}

			$domain = $allowed[$name];
			$safe = $value === null || is_bool($value)
				|| ((is_string($value) || is_int($value)) && ($domain === null
					? in_array((string) $value, ['', '0', '1', 'true', 'false', 'yes', 'no', 'on', 'off'], true)
					: ($name === 'build-date' ? preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', (string) $value) === 1
						: in_array((string) $value, $domain, true))));
			$result[$name] = $safe ? $value : ['redacted' => true];
		}

		ksort($result, SORT_STRING);

		return $result;
	}

	/** @param mixed $repository Frozen repository selector. @return array Safe identity, never repository JSON. @since 0.1.1 */
	private function repository(mixed $repository): array
	{
		if ($repository === null || $repository === '')
		{
			return ['selection' => 'native-configured-repositories'];
		}

		if (is_string($repository) && preg_match('/\A[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}\z/D', trim($repository)) === 1)
		{
			return ['selection' => 'configured-repository', 'guid' => trim($repository)];
		}

		return ['selection' => 'frozen-local-repository-configuration', 'detailsRedacted' => true];
	}

	/** @param string $family Reviewed native family. @param array $input Frozen native input. @return array Public possible effects. @since 0.1.1 */
	private function effects(string $family, array $input): array
	{
		$description = match ($family)
		{
			'get' => 'Synchronize selected definitions and their dependencies; local persistence may change.',
			'init' => 'Initialize selected definitions and their dependencies from the selected repositories.',
			'pull' => 'Replace local definitions and dependencies with remote content, including local changes.',
			'push' => 'Publish selected definitions and dependencies to external repositories; partial publication may require reconciliation.',
			'reset' => 'Reset native tracking and traverse dependencies; this is not an atomic rollback.',
			'compile' => 'Generate source and archive artifacts; configured build hooks, backup and repository output may run.',
		};
		$result = ['description' => $description, 'atomicRollback' => false];

		if ($family === 'compile')
		{
			$result['installExtensions'] = CommandInput::requestsInstallation($input);
		}

		return $result;
	}
}
