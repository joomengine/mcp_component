<?php
/** Minimal database boundary for isolated association-reader tests; not a Joomla installation. */
namespace Joomla\Database;

interface DatabaseInterface
{
	public function createQuery(): object;
	public function quoteName(array|string $name, ?string $alias = null): array|string;
	public function quote(string $value): string;
	public function setQuery(object $query, int $offset = 0, int $limit = 0): self;
	public function loadAssocList(): array;
}
