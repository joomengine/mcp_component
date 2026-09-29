<?php
/** Native list pagination contract; GPL-2.0-or-later. */

use VDM\Component\JoomEngineMcp\Administrator\Native\Action\CoreEntityAction;
use VDM\Component\JoomEngineMcp\Administrator\Native\Action\FixedModelListAction;
use VDM\Component\JoomEngineMcp\Administrator\Native\Action\ListExtensionsAction;
use VDM\Component\JoomEngineMcp\Administrator\Native\Contract\ModelProviderInterface;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\ActionException;
use VDM\Component\JoomEngineMcp\Administrator\Native\Domain\CoreEntityDefinition;
use VDM\Component\JoomEngineMcp\Administrator\Native\Joomla\ModelListPage;

/** Reproduce the native Joomla ListModel::getStart() last-page clamp. */
final class PaginationModelFixture
{
    public array $state = [];
    public int $itemReads = 0;

    public function __construct(public array $rows) {}
    public function setState(string $key, mixed $value): void { $this->state[$key] = $value; }
    public function getTotal(): int { return count($this->rows); }
    public function getItems(): array
    {
        $this->itemReads++;
        $start = $this->state['list.start'];
        $limit = $this->state['list.limit'];
        if ($start > 0 && $start > count($this->rows) - $limit && $limit > 0) {
            $start = max(0, (int) (ceil(count($this->rows) / $limit) - 1) * $limit);
        }
        return array_slice($this->rows, $start, $limit);
    }
}

foreach (['core-entity', 'fixed-model-list', 'extensions.list', 'extensions.installed.list'] as $adapter) {
    foreach ([0, 1, 5] as $size) {
        test($adapter . ' preserves exact offsets for ' . $size . ' rows in fresh and reused model sessions', static function () use ($adapter, $size): void {
            $rows = [];
            for ($id = 1; $id <= $size; $id++) {
                $rows[] = (object) ['id' => $id, 'extension_id' => $id, 'title' => 'Row ' . $id, 'name' => 'Row ' . $id];
            }
            $retained = new PaginationModelFixture($rows);
            foreach ([false, true] as $reuse) {
                foreach ([[0, 3], [1, 1], [max(0, $size - 1), 3], [$size, 1], [$size + 3, 2], [0, 3]] as [$offset, $limit]) {
                    $model = $reuse ? $retained : new PaginationModelFixture($rows);
                    $provider = new class ($model) implements ModelProviderInterface {
                        public function __construct(private object $model) {}
                        public function administrator(string $component, string $modelName): object { return $this->model; }
                    };
                    $action = match ($adapter) {
                        'core-entity' => new CoreEntityAction(new CoreEntityDefinition('tests.entities', 'test entities', 'com_tests', 'Entities', 'Entity', ['id', 'title'], ['title'], supportsState: false), 'list', $provider),
                        'fixed-model-list' => new FixedModelListAction('tests.entities.list', 'Read test entities.', 'com_tests', 'Entities', ['id', 'title'], $provider),
                        default => new ListExtensionsAction($provider, $adapter),
                    };
                    $result = $action->execute(['offset' => $offset, 'limit' => $limit]);
                    $idField = str_starts_with($adapter, 'extensions.') ? 'extensionId' : 'id';
                    $expected = array_map(static fn (object $row): int => $row->id, array_slice($rows, $offset, $limit));
                    expect(array_column($result['items'], $idField) === $expected, 'Native last-page clamping changed the requested slice.');
                    expect($result['page'] === ['offset' => $offset, 'limit' => $limit, 'count' => count($expected), 'total' => $size], 'Page metadata disagrees with the requested slice.');
                }
            }
        });
    }
}

test('cache getData pagination counts the full collection and slices exactly', static function (): void {
    $model = new class {
        public array $state = [];
        public function setState(string $key, mixed $value): void { $this->state[$key] = $value; }
        public function getData(): array {
            expect($this->state['list.start'] === 0 && $this->state['list.limit'] === 0, 'Cache total depended on a first-page slice.');
            return [(object) ['group' => 'one'], (object) ['group' => 'two'], (object) ['group' => 'three']];
        }
    };
    foreach ([[1, 1], [3, 1], [8, 2]] as [$offset, $limit]) {
        $page = ModelListPage::read($model, $offset, $limit, 'getData');
        expect($page['total'] === 3 && count($page['items']) === ($offset === 1 ? 1 : 0), 'Cache count or end boundary is inconsistent.');
    }
});

test('native false totals and false item results remain failures', static function (): void {
    foreach (['total', 'items'] as $failure) {
        $model = new class ($failure) {
            public function __construct(private string $failure) {}
            public function setState(string $key, mixed $value): void {}
            public function getTotal(): int|false { return $this->failure === 'total' ? false : 1; }
            public function getItems(): false { return false; }
        };
        try {
            ModelListPage::read($model, 0, 2);
            expect(false, 'A failed native ' . $failure . ' read was treated as success.');
        } catch (ActionException $exception) {
            expect($exception->errorCode === 'MODEL_RESULT_INVALID', 'Unexpected native list failure classification.');
        }
    }
});
