<?php
/**
 * @package    JoomEngine.Mcp
 * @created    22 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Style\SymfonyStyle;
use VDM\Component\JoomEngineMcp\Administrator\Domain\OperationException;
use VDM\Component\JoomEngineMcp\Administrator\Jcb\CommandOutput;

$joomla = getenv('JOOMLA_SRC') ?: (getenv('JOOMLA_ROOT') ?: '');
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
require is_file($autoload) ? $autoload : $joomla . '/administrator/components/com_joomengine_mcp/vendor/autoload.php';

if (!is_file($joomla . '/libraries/vendor/autoload.php'))
{
	throw new RuntimeException('JOOMLA_SRC must select the actual Joomla source installation.');
}

require $joomla . '/libraries/vendor/autoload.php';
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void
{
	if (!$condition)
	{
		throw new RuntimeException($label);
	}

	$checks++;
};
$input = new ArrayInput([]);
$input->setInteractive(false);
$output = new CommandOutput(4096);
$style = new SymfonyStyle($input, $output);
$style->section('Package Request');
$style->definitionList(['Entity' => 'joomla_component'], ['Items' => 1]);
$style->table(['Category', 'Count'], [['local', 1]]);
$check(str_contains($output->contents(), 'joomla_component') && str_contains($output->contents(), 'local'),
	'Native package definition lists and tables render through the bounded worker output.');
$check(!str_contains($output->contents(), "\033"), 'The captured noninteractive native output contains no terminal control sequences.');

// The actual native compiler chooses this method directly for human output.
$diagnostic = $output->getErrorOutput();
$errors = new SymfonyStyle($input, $diagnostic);
$errors->definitionList(['Component' => 'compiler fixture']);
$errors->error('compiler diagnostic');
$check(!str_contains($output->contents(), 'compiler fixture') && !str_contains($output->contents(), 'compiler diagnostic')
	&& str_contains($diagnostic->contents(), 'compiler fixture') && str_contains($diagnostic->contents(), 'compiler diagnostic'),
	'Compiler tables and errors retain the distinct bounded diagnostic stream.');

$bounded = new CommandOutput(16);
foreach ([$bounded, $bounded->getErrorOutput()] as $stream)
{
	$stream->write('prefix');
	try
	{
		$stream->write(str_repeat('x', 17));
		throw new RuntimeException('Native worker output exceeded its byte bound.');
	}
	catch (OperationException $error)
	{
		$check($error->getIdentifier() === 'WORKER_OUTPUT_LIMIT' && $stream->contents() === 'prefix',
			'Each native stream rejects overflow without appending partial output.');
	}
}

echo 'Native JCB command output: ' . $checks . ' checks passed.' . PHP_EOL;
