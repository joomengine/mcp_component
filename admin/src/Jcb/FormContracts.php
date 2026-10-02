<?php
/**
 * @package    JoomEngine.Mcp
 * @created    2 October 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Component\JoomEngineMcp\Administrator\Jcb;


use SimpleXMLElement;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Service\Json;


/**
 * Describe installed administrator forms explicitly bound by generated API code.
 *
 * Inspection never invokes an installed controller, model, rule or source field.
 * Native validation and runtime ACL/form changes remain authoritative. Metadata
 * is materialized during catalogue synchronization, not inferred from a request.
 *
 * @since 1.0.6
 */
final class FormContracts
{
	/** @var string Confined installed administrator component root. @since 1.0.6 */
	private string $administratorRoot;
	/** @var string Confined installed API component root. @since 1.0.6 */
	private string $apiRoot;
	/** @var array<string,array{path:string,sha256:string}> Contract source provenance. @since 1.0.6 */
	private array $sources = [];
	/** @var int Bounded number of XML fields traversed per contract. @since 1.0.6 */
	private int $fieldCount = 0;

	/**
	 * @param string $administratorRoot Installed administrator component directory.
	 * @param string $apiRoot Installed API component directory.
	 * @since 1.0.6
	 */
	public function __construct(string $administratorRoot, string $apiRoot)
	{
		$this->administratorRoot = realpath($administratorRoot) ?: '';
		$this->apiRoot = realpath($apiRoot) ?: '';
	}

	/**
	 * Bind a route to exactly one literal native model/form mapping.
	 *
	 * Unknown/dynamic mappings return null rather than guessing a singular name.
	 * Once a form binding is established, unsafe XML is an explicit diagnostic.
	 *
	 * @param array $route Actual installed router registration.
	 * @return ?array Schema, native descriptors, verification and provenance.
	 * @throws OperationException When a bound form is malformed or unsafe.
	 * @since 1.0.6
	 */
	public function forRoute(array $route): ?array
	{
		$this->sources = [];
		$this->fieldCount = 0;
		$controller = explode('.', (string) ($route['controller'] ?? ''))[0];

		if ($this->administratorRoot === '' || $this->apiRoot === ''
			|| preg_match('/\A[A-Za-z][A-Za-z0-9_]*\z/D', $controller) !== 1)
		{
			return null;
		}

		$source = $this->read($this->apiRoot, 'src/Controller/' . ucfirst($controller) . 'Controller.php', 'api');

		if ($source === null)
		{
			return null;
		}

		$tokens = self::tokens($source);
		$mapping = self::method($tokens, 'getModel');
		$models = self::assignedLiterals($mapping, '$name');
		$prefixes = self::assignedLiterals($mapping, '$prefix');

		if (array_diff($prefixes, ['', 'Administrator']) !== [])
		{
			return null;
		}

		// A literal parent model call is used by explicitly implemented adapters.
		foreach (self::callArguments($mapping, 'getModel', 'parent') as $arguments)
		{
			$name = self::literal($arguments[0] ?? []);
			$prefix = self::literal($arguments[1] ?? []);

			if ($prefix !== null && $prefix !== '' && $prefix !== 'Administrator')
			{
				return null;
			}

			if ($name !== null && $prefix === 'Administrator')
			{
				$models[] = $name;
			}
		}

		$forms = [];

		foreach (array_unique($models) as $model)
		{
			if (preg_match('/\A[A-Za-z][A-Za-z0-9_]*\z/D', $model) !== 1)
			{
				continue;
			}

			$modelSource = $this->read($this->administratorRoot, 'src/Model/' . ucfirst($model) . 'Model.php', 'administrator');

			if ($modelSource === null)
			{
				continue;
			}

			$modelTokens = self::tokens($modelSource);

			foreach (self::callArguments(self::method($modelTokens, 'getForm'), 'loadForm', '$this') as $arguments)
			{
				$form = self::literal($arguments[1] ?? []);

				if ($form !== null && preg_match('/\A[A-Za-z][A-Za-z0-9_-]*\z/D', $form) === 1)
				{
					$forms[$model . ':' . $form] = ['model' => $model, 'form' => $form, 'tokens' => $modelTokens];
				}
			}
		}

		if (count($forms) !== 1)
		{
			return null;
		}

		$binding = array_values($forms)[0];
		$xml = $this->xml('forms/' . $binding['form'] . '.xml');
		$modelTokens = $binding['tokens'];
		$dynamicRequired = self::changesRequired($modelTokens);
		$tree = $this->form($xml, $dynamicRequired, ['forms/' . $binding['form'] . '.xml'], 0);
		$decodedFields = self::decodedFields($modelTokens);

		foreach ($tree['fields'] as $name => $descriptor)
		{
			if (strtolower($descriptor['native_type'] ?? '') === 'subform' && in_array($name, $decodedFields, true))
			{
				$tree['verification'][$name]['representation'] = 'json';
			}
		}

		$requestOnly = $tree['request_only'];
		$generation = [];

		foreach ($tree['fields'] as $name => &$descriptor)
		{
			if (($descriptor['validation'] ?? '') === 'guid' && $name === 'guid')
			{
				$serverGenerated = self::serverGuid($modelTokens, $name);
				$descriptor['identity'] = ['kind' => 'guid', 'server_generated' => $serverGenerated];

				if ($descriptor['required'] && ($descriptor['default'] ?? '') === '' && !$serverGenerated
					&& !$dynamicRequired && empty($descriptor['showon']))
				{
					$generation[$name] = ['kind' => 'guid', 'on' => 'create', 'format' => 'uuid-v4'];
				}
			}
		}
		unset($descriptor);

		// JCB's explicit validation control is transient; its use must be observed
		// in validate(), not inferred merely from a field called not_required.
		if (isset($tree['fields']['not_required']) && self::usesValidationControl($modelTokens))
		{
			$requestOnly[] = 'not_required';
			$tree['fields']['not_required']['request_only'] = true;
			$tree['fields']['not_required']['semantics'] = 'Comma-separated field names whose native required attribute is disabled and whose input is removed by model validation.';
		}

		$schema = $tree['schema'];
		$schema['description'] = 'Installed native administrator form. Defaults, choices and conditional rules are described from XML; the API applies its native model, ACL and validation. Omitted PATCH fields remain omitted.';

		// PATCH/PUT work with an existing record and must not require every field
		// needed by a fresh form. The API owns its update/back-fill semantics.
		if (($route['method'] ?? '') !== 'POST')
		{
			unset($schema['required']);
		}

		ksort($this->sources, SORT_STRING);
		$contract = ['schema' => $schema, 'fields' => $tree['fields'],
			'verification' => ['fields' => $tree['verification'], 'request_only' => array_values(array_unique($requestOnly))],
			'generation' => $generation, 'native_required_adjustments' => $dynamicRequired,
			'provenance' => ['model' => $binding['model'], 'form' => $binding['form'], 'sources' => array_values($this->sources)]];
		$contract['fingerprint'] = hash('sha256', Json::canonical($contract));

		return $contract;
	}

	/**
	 * Traverse form/fieldset/fields groups while preserving nested data names.
	 *
	 * @param SimpleXMLElement $xml Native form or form group.
	 * @param bool $dynamicRequired Whether the model changes required attributes.
	 * @param string[] $chain Confined XML reference chain.
	 * @param int $depth Bounded traversal depth.
	 * @return array Native form tree and equivalent data schema.
	 * @since 1.0.6
	 */
	private function form(SimpleXMLElement $xml, bool $dynamicRequired, array $chain, int $depth): array
	{
		if ($depth > 16)
		{
			throw new OperationException('JCB_FORM_CONTRACT_INVALID', 'The bound native form exceeds the supported nesting depth.');
		}

		$properties = [];
		$fields = [];
		$verification = [];
		$required = [];
		$requestOnly = [];

		foreach ($xml->children() as $child)
		{
			$tag = $child->getName();

			if ($tag === 'fieldset' || $tag === 'fields')
			{
				$nested = $this->form($child, $dynamicRequired, $chain, $depth + 1);
				$group = $tag === 'fields' ? (string) $child['name'] : '';

				if ($group !== '' && self::name($group))
				{
					$properties[$group] = $nested['schema'];
					$fields[$group] = ['native_type' => 'fields', 'fields' => $nested['fields']];
					$verification[$group] = ['representation' => 'object', 'properties' => $nested['verification']];
				}
				else
				{
					$properties += (array) $nested['schema']['properties'];
					$fields += $nested['fields'];
					$verification += $nested['verification'];
					$required = array_merge($required, $nested['schema']['required'] ?? []);
					$requestOnly = array_merge($requestOnly, $nested['request_only']);
				}

				continue;
			}

			if ($tag !== 'field')
			{
				continue;
			}

			$name = (string) $child['name'];
			$type = strtolower((string) $child['type']);

			if (!self::name($name) || in_array($type, ['note', 'spacer'], true))
			{
				continue;
			}

			if (++$this->fieldCount > 4096)
			{
				throw new OperationException('JCB_FORM_CONTRACT_INVALID', 'The bound native form exceeds the supported field count.');
			}

			$entry = $this->field($child, $dynamicRequired, $chain, $depth + 1);
			$properties[$name] = $entry['schema'];
			$fields[$name] = $entry['descriptor'];

			if ($entry['verification'] !== [])
			{
				$verification[$name] = $entry['verification'];
			}

			if ($entry['descriptor']['request_only'])
			{
				$requestOnly[] = $name;
			}

			if ($entry['descriptor']['required'] && !$dynamicRequired
				&& !isset($entry['descriptor']['default']) && empty($entry['descriptor']['showon'])
				&& !$entry['descriptor']['readonly'] && !$entry['descriptor']['disabled'])
			{
				$required[] = $name;
			}
		}

		$schema = ['type' => 'object', 'properties' => $properties === [] ? (object) [] : $properties, 'additionalProperties' => true];

		if ($required !== [])
		{
			$schema['required'] = array_values(array_unique($required));
		}

		return ['schema' => $schema, 'fields' => $fields, 'verification' => $verification, 'request_only' => $requestOnly];
	}

	/**
	 * Describe a literal field without turning custom PHP into executable policy.
	 *
	 * @param SimpleXMLElement $xml Native field XML.
	 * @param bool $dynamicRequired Whether native required attributes may change.
	 * @param string[] $chain Confined XML reference chain.
	 * @param int $depth Bounded traversal depth.
	 * @return array Schema, descriptor and source-backed representation rules.
	 * @since 1.0.6
	 */
	private function field(SimpleXMLElement $xml, bool $dynamicRequired, array $chain, int $depth): array
	{
		$nativeType = (string) $xml['type'];
		$type = strtolower($nativeType);
		$filter = strtolower((string) $xml['filter']);
		$validate = (string) $xml['validate'];
		$descriptor = ['native_type' => $nativeType, 'filter' => (string) $xml['filter'], 'validation' => $validate,
			'required' => self::truth((string) $xml['required']), 'readonly' => self::truth((string) $xml['readonly']),
			'disabled' => self::truth((string) $xml['disabled']), 'multiple' => self::truth((string) $xml['multiple']),
			'request_only' => $filter === 'unset', 'native_required_adjustments' => $dynamicRequired];

		foreach (['label', 'description', 'hint', 'showon', 'maxlength', 'min', 'max', 'step', 'pattern'] as $attribute)
		{
			if (isset($xml[$attribute]))
			{
				$descriptor[$attribute] = (string) $xml[$attribute];
			}
		}

		if (isset($xml['default']))
		{
			$descriptor['default'] = (string) $xml['default'];
		}

		$choices = [];

		foreach ($xml->option as $option)
		{
			$choices[] = ['value' => (string) $option['value'], 'label' => trim((string) $option)];
		}

		if ($choices !== [])
		{
			$descriptor['choices'] = $choices;
		}

		// Modal selection fields declare their own identity key in XML. Retain
		// it as reference metadata; do not issue SQL or invent a target record.
		$referenceKey = (string) $xml['sql_title_key'];

		if ($type === 'modalselect' && self::name($referenceKey))
		{
			$descriptor['relationship'] = ['option_value_properties' => [$referenceKey],
				'native_reference' => ['table' => (string) $xml['sql_title_table'],
					'title_column' => (string) $xml['sql_title_column'], 'value_column' => $referenceKey,
					'data_key_name' => (string) $xml['data-key-name']],
				'authority' => 'Literal native modal-selection XML; resolve actual relationship identifiers through authorized API records.'];
		}

		$verification = [];
		$schema = [];

		// Filters, rather than field names or appearance, establish coercion.
		if (in_array($filter, ['int', 'integer', 'intval', 'uint'], true) || $type === 'integer')
		{
			$schema = ['type' => ['integer', 'number', 'string', 'boolean', 'null']];
			$verification = ['representation' => 'integer'];
		}
		elseif (in_array($filter, ['bool', 'boolean'], true))
		{
			$schema = ['type' => ['boolean', 'integer', 'string', 'null']];
			$verification = ['representation' => 'boolean'];
		}
		elseif (in_array($type, ['text', 'textarea', 'editor', 'password', 'hidden', 'email', 'url', 'calendar'], true)
			&& $filter !== 'raw')
		{
			$schema = ['type' => ['string', 'number', 'boolean', 'null']];
		}
		elseif ($type === 'editor' || $type === 'textarea')
		{
			$schema = ['type' => ['string', 'null']];
			$descriptor['inert_source'] = $filter === 'raw';
		}

		if ($type === 'subform')
		{
			$subform = null;

			if (isset($xml->form))
			{
				$subform = $xml->form;
			}
			elseif (isset($xml['formsource']))
			{
				$reference = (string) $xml['formsource'];

				if (pathinfo($reference, PATHINFO_EXTENSION) === '')
				{
					$reference .= '.xml';
				}

				$reference = str_starts_with($reference, 'forms/') ? $reference : 'forms/' . $reference;

				if (in_array($reference, $chain, true))
				{
					throw new OperationException('JCB_FORM_CONTRACT_INVALID', 'The bound native subform contains a cyclic form reference.');
				}

				$chain[] = $reference;
				$subform = $this->xml($reference);
			}

			if ($subform !== null)
			{
				$nested = $this->form($subform, $dynamicRequired, $chain, $depth + 1);
				$descriptor['fields'] = $nested['fields'];
				$row = $nested['schema'];
				$many = $descriptor['multiple'] || self::truth((string) $subform['repeat']);
				$schema = $many ? ['anyOf' => [
					['type' => 'array', 'items' => $row],
					['type' => 'object', 'additionalProperties' => $row],
				]] : $row;
				$verification = ['representation' => $many ? 'array' : 'object',
					'properties' => $many ? [] : $nested['verification'],
					'items' => ['representation' => 'object', 'properties' => $nested['verification']]];

				if ($many)
				{
					// Joomla repeatable subforms also accept named row objects. The
					// verification contract keeps row keys and does not reorder them.
					$verification['additionalProperties'] = $verification['items'];
				}
			}
		}

		if ($descriptor['multiple'] && $type !== 'subform')
		{
			$schema = ['type' => ['array', 'null'], 'items' => $schema === [] ? (object) [] : $schema];
			$verification = $verification === [] ? [] : ['representation' => 'array', 'items' => $verification];
		}

		foreach ([['src/Rule/', $validate, 'Rule.php', 'validation_source'], ['src/Field/', $nativeType, 'Field.php', 'field_source']] as $source)
		{
			if ($source[1] !== '' && preg_match('/\A[A-Za-z][A-Za-z0-9_]*\z/D', $source[1]) === 1)
			{
				$path = $source[0] . ucfirst($source[1]) . $source[2];
				$code = $this->read($this->administratorRoot, $path, 'administrator');

				if ($code !== null)
				{
					$descriptor[$source[3]] = ['path' => 'administrator/' . $path, 'sha256' => hash('sha256', $code),
						'authority' => 'Native installed code; custom rules and relationship choices are evaluated by the API.'];

					if ($source[3] === 'field_source')
					{
						$options = self::optionValues(self::tokens($code));

						if ($options !== [])
						{
							$descriptor['relationship'] = ['option_value_properties' => $options,
								'authority' => 'Literal select.option value properties from the native field; resolve actual values through authorized API records.'];
						}
					}
				}
			}
		}

		$parts = ['Native field type: ' . $type . '.'];

		if ($filter !== '')
		{
			$parts[] = 'Native filter: ' . (string) $xml['filter'] . '.';
		}

		if ($validate !== '')
		{
			$parts[] = 'Native validation rule: ' . $validate . '.';
		}

		if ($descriptor['required'])
		{
			$parts[] = $dynamicRequired || !empty($descriptor['showon'])
				? 'The form declares required; native runtime or conditional rules determine when it applies.'
				: 'The form declares required.';
		}

		if (isset($descriptor['showon']))
		{
			$parts[] = 'Native showon: ' . $descriptor['showon'] . '.';
		}

		if (isset($descriptor['default']))
		{
			// SchemaValidator applies JSON Schema defaults to mutation input. Native
			// XML defaults describe form initialization; silently injecting them into
			// PATCH would overwrite unrelated existing fields and server controls.
			$parts[] = 'Native XML default: ' . Json::encode($descriptor['default']) . '; described only, never inserted into an omitted API field.';
		}

		$schema['description'] = implode(' ', $parts);

		return ['schema' => $schema, 'descriptor' => $descriptor, 'verification' => $verification];
	}

	/**
	 * Parse confined XML with external entities and DTDs prohibited.
	 *
	 * @param string $relative Native form path below the administrator directory.
	 * @return SimpleXMLElement Safe parsed native form.
	 * @since 1.0.6
	 */
	private function xml(string $relative): SimpleXMLElement
	{
		$bytes = $this->read($this->administratorRoot, $relative, 'administrator');

		if ($bytes === null || preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $bytes) === 1)
		{
			throw new OperationException('JCB_FORM_CONTRACT_INVALID', 'The bound native form is unavailable or contains prohibited XML declarations.');
		}

		$previous = libxml_use_internal_errors(true);

		try
		{
			$xml = simplexml_load_string($bytes, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);

			if ($xml === false || $xml->getName() !== 'form')
			{
				throw new OperationException('JCB_FORM_CONTRACT_INVALID', 'The bound native form is not valid form XML.');
			}

			return $xml;
		}
		finally
		{
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
	}

	/**
	 * Read only regular files confined to their trusted installed component root.
	 *
	 * @param string $root Canonical installed root.
	 * @param string $relative Literal source path within the component.
	 * @param string $area Public relative provenance prefix.
	 * @return ?string Bounded source bytes, or null when unavailable.
	 * @since 1.0.6
	 */
	private function read(string $root, string $relative, string $area): ?string
	{
		if ($root === '' || strlen($relative) > 512
			|| preg_match('/\A(?:[A-Za-z0-9_-]+\/)*[A-Za-z0-9_.-]+\z/D', $relative) !== 1
			|| in_array('..', explode('/', $relative), true))
		{
			return null;
		}

		$path = realpath($root . '/' . $relative);

		if ($path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)
			|| !is_file($path) || !is_readable($path) || filesize($path) > 2097152)
		{
			return null;
		}

		$bytes = file_get_contents($path);

		if ($bytes === false)
		{
			return null;
		}

		$this->sources[$area . '/' . $relative] = ['path' => $area . '/' . $relative, 'sha256' => hash('sha256', $bytes)];

		return $bytes;
	}

	/** @param string $source Inert PHP source. @return array Significant PHP tokens. @since 1.0.6 */
	private static function tokens(string $source): array
	{
		return array_values(array_filter(token_get_all($source), static fn (mixed $token): bool => !is_array($token)
			|| !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG], true)));
	}

	/** @param mixed $token PHP token. @return string Exact token text. @since 1.0.6 */
	private static function text(mixed $token): string
	{
		return is_array($token) ? $token[1] : (string) $token;
	}

	/**
	 * Select a literal named method body without examining string/comment contents.
	 *
	 * @param array $tokens Significant source tokens.
	 * @param string $name Method name.
	 * @return array Method body tokens, or empty when ambiguous/absent.
	 * @since 1.0.6
	 */
	private static function method(array $tokens, string $name): array
	{
		$found = [];

		foreach ($tokens as $index => $token)
		{
			if (!is_array($token) || $token[0] !== T_FUNCTION || self::text($tokens[$index + 1] ?? '') !== $name)
			{
				continue;
			}

			while (isset($tokens[$index]) && self::text($tokens[$index]) !== '{' && self::text($tokens[$index]) !== ';')
			{
				$index++;
			}

			if (self::text($tokens[$index] ?? '') !== '{')
			{
				continue;
			}

			$body = [];
			$depth = 1;

			while (isset($tokens[++$index]) && $depth > 0)
			{
				$text = self::text($tokens[$index]);
				$depth += $text === '{' ? 1 : ($text === '}' ? -1 : 0);

				if ($depth > 0)
				{
					$body[] = $tokens[$index];
				}
			}

			$found[] = $body;
		}

		return count($found) === 1 ? $found[0] : [];
	}

	/** @param array $tokens Source tokens. @param string $variable Assigned variable. @return string[] Literal values. @since 1.0.6 */
	private static function assignedLiterals(array $tokens, string $variable): array
	{
		$values = [];

		foreach ($tokens as $index => $token)
		{
			if (is_array($token) && $token[0] === T_VARIABLE && $token[1] === $variable
				&& self::text($tokens[$index + 1] ?? '') === '=' && self::text($tokens[$index + 3] ?? '') === ';')
			{
				$value = self::literal([$tokens[$index + 2] ?? '']);

				if ($value !== null)
				{
					$values[] = $value;
				}
			}
		}

		return $values;
	}

	/**
	 * Extract call arguments without evaluating PHP expressions.
	 *
	 * @param array $tokens Method body tokens.
	 * @param string $method Literal called method name.
	 * @param ?string $owner Optional exact object/class token owning the call.
	 * @return array<int,array<int,array>> Tokenized argument lists.
	 * @since 1.0.6
	 */
	private static function callArguments(array $tokens, string $method, ?string $owner = null): array
	{
		$calls = [];

		foreach ($tokens as $index => $token)
		{
			if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== $method
				|| !in_array(self::text($tokens[$index - 1] ?? ''), ['->', '::'], true)
				|| ($owner !== null && self::text($tokens[$index - 2] ?? '') !== $owner)
				|| self::text($tokens[$index + 1] ?? '') !== '(')
			{
				continue;
			}

			$arguments = [[]];
			$depth = 1;
			$argument = 0;
			$index++;

			while (isset($tokens[++$index]) && $depth > 0)
			{
				$text = self::text($tokens[$index]);
				$depth += in_array($text, ['(', '[', '{'], true) ? 1 : (in_array($text, [')', ']', '}'], true) ? -1 : 0);

				if ($text === ',' && $depth === 1)
				{
					$arguments[++$argument] = [];
				}
				elseif ($depth > 0)
				{
					$arguments[$argument][] = $tokens[$index];
				}
			}

			$calls[] = $arguments;
		}

		return $calls;
	}

	/** @param array $tokens One PHP expression. @return ?string Safe literal identifier. @since 1.0.6 */
	private static function literal(array $tokens): ?string
	{
		$token = $tokens[0] ?? null;

		if (count($tokens) !== 1 || !is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING)
		{
			return null;
		}

		$value = substr($token[1], 1, -1);

		return preg_match('/\A[A-Za-z0-9_.-]*\z/D', $value) === 1 ? $value : null;
	}

	/** @param array $tokens Model source tokens. @return bool Native form required attributes can change. @since 1.0.6 */
	private static function changesRequired(array $tokens): bool
	{
		foreach (self::callArguments($tokens, 'setFieldAttribute') as $arguments)
		{
			if (self::literal($arguments[1] ?? []) === 'required')
			{
				return true;
			}
		}

		return false;
	}

	/** @param array $tokens Model source tokens. @return bool Exact JCB validation control is consumed. @since 1.0.6 */
	private static function usesValidationControl(array $tokens): bool
	{
		$validate = self::method($tokens, 'validate');
		$control = false;

		foreach ($validate as $token)
		{
			$control = $control || self::literal([$token]) === 'not_required';
		}

		return $control && self::changesRequired($validate);
	}

	/** @param array $tokens Native model tokens. @param string $field GUID field name. @return bool Native form sets a generated GUID. @since 1.0.6 */
	private static function serverGuid(array $tokens, string $field): bool
	{
		foreach (self::callArguments(self::method($tokens, 'getForm'), 'setValue') as $arguments)
		{
			if (self::literal($arguments[0] ?? []) !== $field)
			{
				continue;
			}

			$value = array_map(self::text(...), $arguments[2] ?? []);

			if ($value === ['GuidHelper', '::', 'get', '(', ')'])
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Observe field-specific native structured decoding without reading storage.
	 *
	 * @param array $tokens Native model tokens.
	 * @return string[] Literal item properties passed to JSON/Registry decoding.
	 * @since 1.0.6
	 */
	private static function decodedFields(array $tokens): array
	{
		$properties = [];
		$tokens = self::method($tokens, 'getItem');

		foreach ($tokens as $index => $token)
		{
			if (is_array($token) && $token[0] === T_STRING && in_array($token[1], ['json_decode', 'loadString'], true)
				&& self::text($tokens[$index + 1] ?? '') === '('
				&& self::text($tokens[$index + 2] ?? '') === '$item'
				&& self::text($tokens[$index + 3] ?? '') === '->')
			{
				$name = self::text($tokens[$index + 4] ?? '');

				if (self::name($name))
				{
					$properties[] = $name;
				}
			}
		}

		return array_values(array_unique($properties));
	}

	/**
	 * Observe dynamic choice identity properties, never dynamic choice values.
	 *
	 * @param array $tokens Native field source tokens.
	 * @return string[] Source-backed option value properties, such as guid or id.
	 * @since 1.0.6
	 */
	private static function optionValues(array $tokens): array
	{
		$properties = [];

		foreach (self::callArguments(self::method($tokens, 'getOptions'), '_') as $arguments)
		{
			$value = array_map(self::text(...), $arguments[1] ?? []);

			if (self::literal($arguments[0] ?? []) === 'select.option' && count($value) === 3
				&& str_starts_with($value[0], '$') && $value[1] === '->' && self::name($value[2]))
			{
				$properties[] = $value[2];
			}
		}

		return array_values(array_unique($properties));
	}

	/** @param string $value Native field/group name. @return bool Safe JSON property name. @since 1.0.6 */
	private static function name(string $value): bool
	{
		return preg_match('/\A[A-Za-z][A-Za-z0-9_]*\z/D', $value) === 1;
	}

	/** @param string $value Native XML truth value. @return bool Native declaration is enabled. @since 1.0.6 */
	private static function truth(string $value): bool
	{
		return in_array(strtolower($value), ['true', '1', 'yes', 'required'], true);
	}
}
