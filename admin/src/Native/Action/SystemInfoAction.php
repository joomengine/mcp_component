<?php

declare(strict_types=1);

namespace VDM\Component\JoomEngineMcp\Administrator\Native\Action;

use VDM\Component\JoomEngineMcp\Administrator\Native\Contract\ActionInterface;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\ActionDescriptor;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\Input;

final class SystemInfoAction implements ActionInterface
{
    public function descriptor(): ActionDescriptor
    {
        return new ActionDescriptor(
            'system.info',
            'Return non-secret Joomla and PHP runtime versions.',
            'read',
            [],
            ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
            [
                'type' => 'object',
                'required' => ['joomlaVersion', 'phpVersion'],
                'properties' => [
                    'joomlaVersion' => ['type' => 'string'],
                    'phpVersion' => ['type' => 'string'],
                ],
                'additionalProperties' => false,
            ],
        );
    }

    public function execute(array $input): array
    {
        Input::rejectUnknown($input, []);

        return [
            'joomlaVersion' => defined('JVERSION') ? (string) JVERSION : 'unknown',
            'phpVersion' => PHP_VERSION,
        ];
    }
}
