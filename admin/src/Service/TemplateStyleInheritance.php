<?php
/**
 * @package    JoomEngine.Mcp
 * @created    29 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Service;


use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;


/**
 * Resolve template inheritance from authenticated native style manifests before writing.
 *
 * @since 1.0.1
 */
final class TemplateStyleInheritance
{
	/** @param array<string,mixed> $configuration Reviewed API binding. @return ?int Fixed native client for a style create, otherwise null. @since 1.0.1 */
	public static function client(array $configuration): ?int
	{
		if (($configuration['method'] ?? '') !== 'POST')
		{
			return null;
		}

		return match ($configuration['route'] ?? '')
		{
			'/v1/templates/styles/site' => 0,
			'/v1/templates/styles/administrator' => 1,
			default => null,
		};
	}

	/**
	 * Inspect bounded authorized list/item actions, never untrusted pagination URLs.
	 *
	 * @param mixed $template Requested installed template name.
	 * @param int $client Fixed site or administrator client.
	 * @param callable(string,array):array $read Authorized native API action reader.
	 * @param int $limit Installation-bounded list page size.
	 * @return array<string,mixed> Frozen inheritance fields and their source identities.
	 * @since 1.0.1
	 */
	public static function snapshot(mixed $template, int $client, callable $read, int $limit = 100): array
	{
		if (!self::templateName($template) || !in_array($client, [0, 1], true) || $limit < 1 || $limit > 100)
		{
			throw new OperationException('INVALID_INPUT', 'Template style creation requires an installed template name.');
		}

		$base = $client === 0 ? 'templates.site-styles' : 'templates.administrator-styles';
		$seen = [];
		$sources = [];
		$inheritance = null;
		$offset = 0;

		for ($page = 0; $page < 10; $page++)
		{
			$response = $read($base . '.list', ['offset' => $offset, 'limit' => $limit]);
			$document = $response['data'] ?? null;
			$items = is_array($document) ? ($document['data'] ?? null) : null;

			if (!is_array($items) || !array_is_list($items) || count($items) > $limit)
			{
				self::unavailable();
			}

			foreach ($items as $item)
			{
				$attributes = is_array($item) ? ($item['attributes'] ?? null) : null;
				$id = is_array($item) ? ($item['id'] ?? null) : null;

				if (!is_array($attributes) || !self::positiveId($id) || isset($seen[(string) $id]))
				{
					self::unavailable();
				}

				$seen[(string) $id] = true;

				if (($attributes['template'] ?? null) !== $template)
				{
					continue;
				}

				if (!in_array($attributes['client_id'] ?? null, [$client, (string) $client], true) || count($sources) >= 20)
				{
					self::unavailable();
				}

				$detail = $read($base . '.get', ['id' => (int) $id]);
				$node = $detail['data']['data'] ?? null;
				$values = is_array($node) ? ($node['attributes'] ?? null) : null;

				if (!is_array($values) || (string) ($node['id'] ?? '') !== (string) $id
					|| ($values['template'] ?? null) !== $template
					|| !in_array($values['client_id'] ?? null, [$client, (string) $client], true))
				{
					self::unavailable();
				}

				$fields = self::manifest($values['xml'] ?? null, $client);

				if ($fields['parent'] === $template || ($inheritance !== null && $inheritance !== $fields))
				{
					self::unavailable();
				}

				$inheritance = $fields;
				$sources[] = (int) $id;
			}

			$offset += count($items);
			$next = $document['links']['next'] ?? null;
			$hasNext = $next !== null && $next !== '';
			$pages = $document['meta']['total-pages'] ?? null;
			$lastPage = (is_int($pages) && $pages >= 0 && $pages <= 100000) ? $page + 1 >= $pages : false;

			// A full page without links also requires one bounded subsequent read.
			if (!$hasNext && (count($items) < $limit || $lastPage))
			{
				if ($inheritance === null)
				{
					self::unavailable();
				}

				sort($sources, SORT_NUMERIC);

				return ['item' => $inheritance, 'template' => $template, 'client_id' => $client, 'source_style_ids' => $sources];
			}

			if ($items === [])
			{
				self::unavailable();
			}
		}

		self::unavailable();
	}

	/** @param mixed $xml Native JSON serialization of templateDetails.xml. @param ?int $client Expected native client, when available. @return array{parent:string,inheritable:int} Validated native inheritance. @since 1.0.1 */
	public static function manifest(mixed $xml, ?int $client = null): array
	{
		if (!is_array($xml) || array_is_list($xml) || !is_string($xml['name'] ?? null) || trim($xml['name']) === '')
		{
			self::unavailable();
		}

		$attributes = $xml['@attributes'] ?? [];

		if (!is_array($attributes) || (isset($attributes['type']) && $attributes['type'] !== 'template')
			|| ($client !== null && isset($attributes['client']) && $attributes['client'] !== ($client === 0 ? 'site' : 'administrator')))
		{
			self::unavailable();
		}

		$parent = array_key_exists('parent', $xml) ? $xml['parent'] : '';
		$inheritable = array_key_exists('inheritable', $xml) ? $xml['inheritable'] : '0';

		// Empty SimpleXML elements are encoded as empty objects by Joomla.
		$parent = $parent === [] ? '' : $parent;
		$inheritable = $inheritable === [] ? '0' : $inheritable;

		if (($parent !== '' && !self::templateName($parent)) || !in_array($inheritable, [0, 1, '', '0', '1'], true))
		{
			self::unavailable();
		}

		return ['parent' => $parent, 'inheritable' => (int) $inheritable];
	}

	/** @param mixed $value Native template directory identifier. @return bool Whether it is a bounded literal name. @since 1.0.1 */
	private static function templateName(mixed $value): bool
	{
		return is_string($value) && preg_match('/\A[A-Za-z0-9_][A-Za-z0-9_.-]{0,49}\z/D', $value) === 1 && !str_contains($value, '..');
	}

	/** @param mixed $value Native JSON:API ID. @return bool Whether an exact positive safe integer is present. @since 1.0.1 */
	private static function positiveId(mixed $value): bool
	{
		return (is_int($value) && $value > 0 && $value <= 9007199254740991)
			|| (is_string($value) && preg_match('/\A[1-9][0-9]{0,15}\z/D', $value) === 1 && (float) $value <= 9007199254740991);
	}

	/** @return never Refuse an incomplete or ambiguous native template manifest before any mutation. @since 1.0.1 */
	private static function unavailable(): never
	{
		throw new OperationException('TEMPLATE_INHERITANCE_UNAVAILABLE', 'Template inheritance could not be verified from existing styles for this client. Check the installed template and its manifest before creating another style.');
	}
}
