<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Liberu\Cms\Core\Module\DatabaseModuleStateRepository;

test('it reads, caches, writes, and forgets module state', function (): void {
    DB::table('cms_modules')->insert(['key' => 'blog', 'enabled' => false]);
    $repository = new DatabaseModuleStateRepository(DB::getFacadeRoot());

    expect($repository->isEnabled('blog'))->toBeFalse();

    DB::table('cms_modules')->where('key', 'blog')->update(['enabled' => true]);
    expect($repository->isEnabled('blog'))->toBeFalse();

    $repository->setEnabled('blog', true);
    expect($repository->isEnabled('blog'))->toBeTrue();

    $repository->forget('blog');
    expect($repository->isEnabled('blog', false))->toBeFalse()
        ->and(DB::table('cms_modules')->where('key', 'blog')->exists())->toBeFalse();
});
