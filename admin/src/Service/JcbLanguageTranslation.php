<?php
/**
 * @package    JoomEngine.Mcp
 * @created    29 October 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Service;


use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;


/**
 * Preserve JCB's immutable source while updating an existing translation.
 *
 * Joomla's API backfills omitted table columns before form validation. An explicit
 * null suppresses that backfill, then Registry/Form filtering omits the disabled
 * source entirely. The native model loads the existing row before binding the
 * filtered data, so the source remains unchanged and must be verified afterwards.
 *
 * @since 1.0.11
 */
final class JcbLanguageTranslation
{
	/**
	 * Diagnose an installed legacy create form before claiming a write.
	 *
	 * @param array $configuration Source-fingerprinted native API configuration.
	 * @return bool Whether a native source-form correction is required.
	 * @since 1.0.11
	 */
	public static function blocksCreate(array $configuration): bool
	{
		return ($configuration['method'] ?? '') === 'POST'
			&& ($configuration['operation'] ?? '') === 'create'
			&& ($configuration['route'] ?? '') === '/v1/componentbuilder/language_translations'
			&& ($configuration['authentication'] ?? '') === 'joomla-api-token'
			&& ($configuration['api_form']['source_create_disabled'] ?? false) === true;
	}

	/**
	 * Limit the compatibility rule to the observed native translation form.
	 *
	 * @param array $configuration Authorized, installed API binding configuration.
	 * @return bool Whether this PATCH needs immutable-source preservation.
	 * @since 1.0.11
	 */
	public static function preservesSource(array $configuration): bool
	{
		$form = $configuration['api_form'] ?? [];

		return ($configuration['method'] ?? '') === 'PATCH'
			&& preg_match('~\A/v1/componentbuilder/language_translations?/:id\z~D', $configuration['route'] ?? '') === 1
			&& ($configuration['route_parameters'] ?? []) === [['name' => 'id', 'kind' => 'positive-integer']]
			&& ($configuration['operation'] ?? '') === 'update'
			&& ($configuration['authentication'] ?? '') === 'joomla-api-token'
			&& ($configuration['response_shape'] ?? '') === 'jsonapi'
			&& ($form['provenance']['form'] ?? '') === 'language_translation'
			&& strtolower($form['provenance']['model'] ?? '') === 'language_translation'
			&& ($form['fields']['source']['disabled'] ?? false) === true
			&& ($form['fields']['source']['readonly'] ?? false) === true;
	}

	/**
	 * Suppress native API backfill without turning null into an intended write.
	 *
	 * @param array $body Approved mutable form values.
	 * @param array $current Independently read and approval-bound existing record.
	 * @return array Native request body; the source is not a requested mutation.
	 * @throws OperationException When the source cannot be safely preserved.
	 * @since 1.0.11
	 */
	public static function body(array $body, array $current): array
	{
		if (array_key_exists('source', $body) && $body['source'] !== null)
		{
			throw new OperationException('INVALID_INPUT', 'The language translation source is read-only. Omit source when editing its translations.');
		}

		if (!isset($current['source']) || !is_string($current['source']))
		{
			throw new OperationException('PREFLIGHT_FAILED', 'The native language translation read did not expose its source string; this update cannot safely preserve it.');
		}

		$body['source'] = null;

		return $body;
	}
}
