<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Liberu\Cms\Contracts\Module\ModuleRegistryInterface;
use Liberu\Cms\Menus\Contracts\MenuRepositoryInterface;
use Liberu\Cms\Menus\MenusModule;
use Liberu\Cms\Menus\Models\Menu;
use Liberu\Cms\Menus\Models\MenuItem;
use Liberu\Cms\Menus\Services\MenuService;

uses(RefreshDatabase::class);

it('rejects blank menu fields and missing creation identity', function (array $attributes, string $field): void {
    try {
        app(MenuService::class)->createMenu($attributes);
        $this->fail('Invalid menu must be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
    expect(Menu::query()->count())->toBe(0);
})->with([
    [['name' => ' ', 'location' => 'header'], 'name'],
    [['name' => 'Main', 'location' => []], 'location'],
    [['name' => 'Main', 'location' => 'header', 'variant' => ' '], 'variant'],
    [['name' => 'Main'], 'menu'],
]);

it('updates menus and existing items without dropping their stored link identity', function (): void {
    $service = app(MenuService::class);
    $menu = $service->createMenu(['name' => 'Main', 'location' => 'header']);
    expect($menu->variant)->toBe('default');
    expect($service->updateMenu($menu, ['name' => 'Updated'])->name)->toBe('Updated');
    $item = $service->saveItem($menu, ['label' => 'Original', 'url' => '/home'])->refresh();
    $updated = $service->saveItem($menu, ['label' => 'Updated item'], $item);
    expect($updated->id)->toBe($item->id)->and($updated->label)->toBe('Updated item')->and($updated->url)->toBe('/home');
    $service->deleteItem($updated);
    expect($menu->items()->count())->toBe(0);
});

it('requires typed menu link fields and object visibility rules', function (array $attributes, string $field): void {
    $menu = Menu::factory()->create();
    try {
        app(MenuService::class)->saveItem($menu, $attributes);
        $this->fail('Invalid item must be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
    expect($menu->items()->count())->toBe(0);
})->with([
    [['url' => '/home'], 'label'],
    [['label' => ' ', 'url' => '/home'], 'label'],
    [['label' => 'Unknown', 'link_type' => 'unknown'], 'link_type'],
    [['label' => 'Content', 'link_type' => 'content'], 'content_id'],
    [['label' => 'System', 'link_type' => 'system'], 'system_route'],
    [['label' => 'Custom', 'link_type' => 'custom'], 'url'],
    [['label' => 'Hidden', 'url' => '/home', 'visibility' => 'invalid'], 'visibility'],
]);

it('rejects unsafe custom URLs before creating an item', function (mixed $url): void {
    $menu = Menu::factory()->create();
    expect(fn () => app(MenuService::class)->saveItem($menu, ['label' => 'Unsafe', 'url' => $url]))
        ->toThrow(ValidationException::class);
    expect($menu->items()->count())->toBe(0);
})->with(['//foreign.example.test', '/path with space', '/path\\escape', 'javascript:alert(1)', 123]);

it('accepts HTTP and relative custom links', function (string $url): void {
    $menu = Menu::factory()->create();
    $item = app(MenuService::class)->saveItem($menu, ['label' => 'Valid', 'url' => $url]);
    expect($item->url)->toBe($url)->and($item->menu_id)->toBe($menu->id);
})->with(['/home', 'https://example.test/home', 'http://example.test/home']);

it('rejects items from another menu and foreign or self parent links', function (string $fault): void {
    $service = app(MenuService::class);
    $menu = Menu::factory()->create();
    $other = Menu::factory()->create();
    $item = MenuItem::factory()->for($menu)->create()->refresh();
    $foreign = MenuItem::factory()->for($other)->create()->refresh();
    try {
        match ($fault) {
            'foreign item' => $service->saveItem($menu, ['label' => 'Changed'], $foreign),
            'foreign parent' => $service->saveItem($menu, ['parent_id' => $foreign->id], $item),
            'self parent' => $service->saveItem($menu, ['parent_id' => $item->id], $item),
        };
        $this->fail('Invalid item ownership or parent must be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($fault === 'foreign item' ? 'menu_id' : 'parent_id');
    }
    expect($item->fresh()->parent_id)->toBeNull()->and($foreign->fresh()->label)->toBe($foreign->label);
})->with(['foreign item', 'foreign parent', 'self parent']);

it('rejects descendant nesting and allows relocation beneath an independent branch', function (): void {
    $service = app(MenuService::class);
    $menu = Menu::factory()->create();
    $root = $service->saveItem($menu, ['label' => 'Root', 'link_type' => 'custom', 'url' => '/root']);
    $child = $service->saveItem($menu, ['label' => 'Child', 'link_type' => 'custom', 'url' => '/child', 'parent_id' => $root->id]);
    $grandchild = $service->saveItem($menu, ['label' => 'Grandchild', 'link_type' => 'custom', 'url' => '/grandchild', 'parent_id' => $child->id]);
    expect(fn () => $service->saveItem($menu, ['parent_id' => $grandchild->id], $root))
        ->toThrow(ValidationException::class, 'An item cannot be nested beneath its own descendant.');
    $independent = $service->saveItem($menu, ['label' => 'Independent', 'link_type' => 'custom', 'url' => '/independent']);
    expect($service->saveItem($menu, ['parent_id' => $independent->id], $root)->parent_id)->toBe($independent->id);
});

it('exposes the registered module identity and repository lookup used by the host', function (): void {
    $module = app(ModuleRegistryInterface::class)->get('menus');
    $manifest = json_decode(file_get_contents(base_path('modules/cms-menus/module.json')), true, flags: JSON_THROW_ON_ERROR);
    expect($module)->toBeInstanceOf(MenusModule::class)
        ->and($module->name())->toBe('Menus')
        ->and($module->version())->toBe($manifest['version']);
    $menu = Menu::factory()->create();
    $repository = app(MenuRepositoryInterface::class);
    expect($repository->find($menu->id)?->is($menu))->toBeTrue();
    $menu->delete();
    expect($repository->find($menu->id))->toBeNull();
});
