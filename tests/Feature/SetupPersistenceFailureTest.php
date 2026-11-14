<?php

use App\Models\PanelSetup;
use App\Models\User;
use App\Support\Onboarding\SetupManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Liberu\Cms\Contracts\Tenancy\TenantContextInterface;
use Liberu\Cms\Core\Models\Site;

uses(RefreshDatabase::class);

it('rejects a malformed validation result before persisting setup state', function (mixed $data, string $message) {
    $actor = User::factory()->create(['is_platform_admin' => true]);
    $factory = Validator::getFacadeRoot();
    $validator = Mockery::mock(Illuminate\Contracts\Validation\Validator::class);
    $validator->shouldReceive('validate')->once()->andReturn(['data' => $data]);
    try {
        Validator::shouldReceive('make')->once()->andReturn($validator);
        expect(fn () => app(SetupManager::class)->save($actor, null, []))
            ->toThrow(LogicException::class, $message);
        expect(PanelSetup::count())->toBe(0);
    } finally {
        Validator::swap($factory);
    }
})->with([
    'scalar result' => ['invalid', 'Validated setup data must be an array.'],
    'nonstring field' => [[0 => 'invalid'], 'Validated setup fields must be strings or null.'],
    'nonscalar field value' => [['site_name' => ['invalid']], 'Validated setup fields must be strings or null.'],
]);

it('propagates unrelated uniqueness failures without classifying them as domain conflicts', function () {
    $actor = User::factory()->create(['is_platform_admin' => true]);
    DB::unprepared("CREATE TRIGGER reject_setup BEFORE INSERT ON panel_setups BEGIN SELECT RAISE(ABORT, 'UNIQUE constraint failed: panel_setups.scope'); END");
    try {
        expect(fn () => app(SetupManager::class)->save($actor, null, ['site_name' => 'Draft']))
            ->toThrow(UniqueConstraintViolationException::class);
        expect(PanelSetup::count())->toBe(0);
    } finally {
        DB::unprepared('DROP TRIGGER reject_setup');
    }
});

it('rolls back site allocation and restores tenant context if a model observer invalidates its identity', function () {
    $actor = User::factory()->create();
    $team = $actor->createPersonalTeam();
    $context = app(TenantContextInterface::class);
    $context->setTenantId(991);
    new Site;
    $dispatcher = Site::getEventDispatcher();
    Site::setEventDispatcher(clone $dispatcher);
    Site::created(function (Site $site) {
        $site->setAttribute('id', 0);
    });
    try {
        expect(fn () => app(SetupManager::class)->save($actor, $team, [
            'name' => 'Draft website', 'domain' => null, 'default_locale' => 'en', 'timezone' => 'UTC',
        ], true))->toThrow(LogicException::class, 'A CMS site must have an integer ID.');
        expect(PanelSetup::count())->toBe(0)
            ->and(Site::withoutGlobalScopes()->count())->toBe(0)
            ->and($context->tenantId())->toBe(991);
    } finally {
        Site::setEventDispatcher($dispatcher);
        $context->setTenantId(null);
    }
});
