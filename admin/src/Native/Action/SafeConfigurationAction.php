<?php

declare(strict_types=1);

namespace VDM\Component\JoomEngineMcp\Administrator\Native\Action;

use VDM\Component\JoomEngineMcp\Administrator\Native\Contract\ActionInterface;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\ActionDescriptor;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\ActionException;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\Input;

final readonly class SafeConfigurationAction implements ActionInterface
{
    private const KEYS = [
        'sitename',
        'offline',
        'display_offline_message',
        'editor',
        'captcha',
        'list_limit',
        'sef',
        'sef_rewrite',
        'gzip',
        'debug',
        'debug_lang',
    ];

    public function __construct(
        private object $application,
        private string $actionName = 'configuration.get_safe',
    )
    {
    }

    public function descriptor(): ActionDescriptor
    {
        return new ActionDescriptor(
            $this->actionName,
            'Return only explicitly non-secret Joomla global configuration values.',
            'read',
            [['action' => 'core.admin', 'asset' => 'com_config']],
            ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
            ['type' => 'object', 'additionalProperties' => ['type' => ['string', 'integer', 'boolean', 'null']]],
        );
    }

    public function execute(array $input): array
    {
        Input::rejectUnknown($input, []);

        if (!method_exists($this->application, 'get')) {
            throw new ActionException('JOOMLA_RUNTIME_UNAVAILABLE', 'Joomla configuration is unavailable.');
        }

        $values = [];

        foreach (self::KEYS as $key) {
            $value = $this->application->get($key);
            $values[$key] = is_scalar($value) || $value === null ? $value : null;
        }

        return $values;
    }
}
