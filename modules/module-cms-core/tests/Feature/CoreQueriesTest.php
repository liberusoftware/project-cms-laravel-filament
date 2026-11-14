<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Liberu\Cms\Core\Actions\CoreMutationService;
use Liberu\Cms\Core\Models\Site;
use Liberu\Cms\Core\Queries\CoreQueryService;

it('queries the owned site graph with search pagination and environment isolation', function () {
    $actions = app(CoreMutationService::class);
    $queries = app(CoreQueryService::class);
    $site = $actions->createSite(['key' => 'docs', 'name' => 'Documentation']);
    $channel = $actions->createChannel($site, ['key' => 'web', 'name' => 'Website']);
    $identity = $actions->createIdentity($site, ['channel_id' => $channel->id, 'content_type' => 'page', 'content_id' => '42', 'owner_type' => Site::class, 'owner_id' => (string) $site->id]);
    $alias = $actions->createAlias($site, ['channel_id' => $channel->id, 'alias' => 'old guide', 'target_type' => 'page', 'target_id' => '42']);
    $setting = $actions->putSetting($site, 'branding', ['name' => 'Production']);
    $actions->putSetting($site, 'branding', ['name' => 'Staging'], 'staging');
    expect($queries->sites(10, 'Documentation')->items())->toHaveCount(1)
        ->and($queries->channels('docs', 10, 'Website')->items())->toHaveCount(1)
        ->and($queries->alias('docs', 'old%20guide')->is($alias))->toBeTrue()
        ->and($queries->aliases('docs')->items())->toHaveCount(1)
        ->and($queries->identities('docs')->items())->toHaveCount(1)
        ->and($queries->settings('docs')->items()[0]->value)->toBe(['name' => 'Production'])
        ->and($queries->settings('docs', 10, 'staging')->items()[0]->value)->toBe(['name' => 'Staging'])
        ->and($channel->identities->sole()->is($identity))->toBeTrue()
        ->and($channel->aliases->sole()->is($alias))->toBeTrue()
        ->and($alias->site->is($site))->toBeTrue()
        ->and($alias->channel->is($channel))->toBeTrue()
        ->and($queries->site('docs')->is($site))->toBeTrue()
        ->and($identity->site->is($site))->toBeTrue()
        ->and($identity->owner->is($site))->toBeTrue()
        ->and($setting->site->is($site))->toBeTrue();
    config(['cms-api.pagination.max' => 'invalid']);
    expect($queries->sites(500)->perPage())->toBe(100)
        ->and(fn () => $queries->channels('missing'))->toThrow(ModelNotFoundException::class);
});

it('updates and deletes records through the owning mutation boundary', function () {
    $actions = app(CoreMutationService::class);
    $site = $actions->createSite(['key' => 'docs', 'name' => 'Docs']);
    $actions->updateSite($site, ['name' => 'Updated', 'unexpected' => 'ignored']);
    $channel = $actions->createChannel('docs', ['key' => 'web', 'name' => 'Web']);
    $actions->updateChannel($channel, ['name' => 'Updated web', 'unexpected' => 'ignored']);
    expect($site->fresh()->name)->toBe('Updated')->and($channel->fresh()->name)->toBe('Updated web');
    $actions->deleteChannel($channel);
    expect($site->channels()->count())->toBe(0);
    $actions->deleteSite($site);
    expect(Site::count())->toBe(0);
});

it('rejects invalid setting keys and channel identities and accepts numeric site identities', function () {
    $actions = app(CoreMutationService::class);
    $site = $actions->createSite(['key' => 'docs', 'name' => 'Docs']);
    expect($actions->createChannel($site->id, ['key' => 'web', 'name' => 'Web'])->site_id)->toBe($site->id)
        ->and(fn () => $actions->putSetting(null, '', []))->toThrow(ValidationException::class)
        ->and(fn () => $actions->createIdentity($site, ['content_type' => 'page', 'content_id' => '42', 'channel_id' => 'invalid']))->toThrow(ValidationException::class);
});
