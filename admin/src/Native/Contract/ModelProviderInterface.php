<?php

declare(strict_types=1);

namespace VDM\Component\JoomEngineMcp\Administrator\Native\Contract;

interface ModelProviderInterface
{
    public function administrator(string $component, string $modelName): object;
}
