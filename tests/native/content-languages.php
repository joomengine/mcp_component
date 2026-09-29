<?php
/** Content-language ordering and identifier mapping; GPL-2.0-or-later. */

use VDM\Component\JoomEngineMcp\Administrator\Native\Action\CoreEntityAction;
use VDM\Component\JoomEngineMcp\Administrator\Native\Contract\ModelProviderInterface;
use VDM\Component\JoomEngineMcp\Administrator\Native\Joomla\CoreEntityCatalogue;

test('content languages support every advertised ordering using native physical fields', static function (): void {
    $entity = array_values(array_filter(CoreEntityCatalogue::all(), static fn ($entity): bool => $entity->id === 'languages.content'))[0];
    $model = new class {
        public array $state = [];
        public function setState(string $key, mixed $value): void { $this->state[$key] = $value; }
        public function isValidFilterColumn(string $name): bool { return in_array($name, ['a.lang_id', 'a.title', 'a.ordering', 'a.published'], true); }
        public function getTotal(): int { return 1; }
        public function getItems(): array {
            expect(in_array($this->state['list.ordering'], ['a.lang_id', 'a.title', 'a.ordering', 'a.published'], true), 'The advertised sort reached a nonexistent or ambiguous native column.');
            return [(object) ['lang_id' => 7, 'title' => 'English', 'lang_code' => 'en-GB', 'published' => 1]];
        }
    };
    $provider = new class ($model) implements ModelProviderInterface {
        public function __construct(private object $model) {}
        public function administrator(string $component, string $modelName): object {
            expect($component === 'com_languages' && $modelName === 'Languages', 'Unexpected content-language model.');
            return $this->model;
        }
    };
    $action = new CoreEntityAction($entity, 'list', $provider);
    $orders = $action->descriptor()->inputSchema['properties']['order']['enum'];
    expect(in_array('id', $orders, true) && in_array('lang_id', $orders, true), 'Compatibility identifier ordering disappeared.');
    foreach ($orders as $order) {
        $result = $action->execute(['offset' => 0, 'limit' => 2, 'order' => $order]);
        expect($result['items'][0]['id'] === 7 && $result['items'][0]['lang_id'] === 7, 'Public and native language identifiers disagree.');
        expect($model->state['list.ordering'] === 'a.' . ($order === 'id' ? 'lang_id' : $order), 'Native sort column does not match the advertised field.');
    }
    $action->execute([]);
    expect($model->state['list.ordering'] === 'a.lang_id', 'Default content-language ordering changed to an unsupported field.');
});
