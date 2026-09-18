<?php

declare(strict_types=1);

namespace VDM\Component\JoomEngineMcp\Administrator\Native\Contract;

use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\ActionDescriptor;

interface CapabilityResolverInterface
{
    /** @return array{id: int|null, configured: bool} */
    public function actor(): array;

    /**
     * @return array{allowed: bool, requirements: list<array{action: string, asset: string, allowed: bool}>}
     */
    public function resolve(ActionDescriptor $descriptor): array;
}
