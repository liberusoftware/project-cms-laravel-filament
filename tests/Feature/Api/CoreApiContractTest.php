<?php

use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Liberu\Cms\Core\Models\Setting;
use Liberu\Cms\Core\Models\Site;
use Liberu\Cms\CoreApi\Http\Controllers\CoreApiController;
use Liberu\Cms\CoreApi\Http\Resources\CoreAliasResource;
use Liberu\Cms\CoreApi\Http\Resources\CoreChannelResource;
use Liberu\Cms\CoreApi\Http\Resources\CoreIdentityResource;
use Liberu\Cms\CoreApi\Http\Resources\CoreSettingResource;
use Liberu\Cms\CoreApi\Http\Resources\CoreSiteResource;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->otherTeam = Team::factory()->create();
    Sanctum::actingAs($this->team, ['content:read', 'content:write'], 'sanctum');
});

it('creates reads updates and deletes a site through its authenticated API', function () {
    $url = route('cms-api.core.sites');
    $this->postJson($url, ['key' => 'main', 'name' => 'Main website', 'settings' => ['theme' => 'light']])
        ->assertCreated()->assertJsonPath('data.type', 'cms-site')->assertJsonPath('data.key', 'main');
    $this->getJson($url)->assertOk()->assertJsonPath('data.0.name', 'Main website');
    $this->getJson(route('cms-api.core.site', ['site' => 'main']))->assertOk()->assertJsonPath('data.settings.theme', 'light');
    $this->patchJson(route('cms-api.core.site.update', ['site' => 'main']), ['name' => 'Updated website'])
        ->assertOk()->assertJsonPath('data.name', 'Updated website');
    $this->deleteJson(route('cms-api.core.site.delete', ['site' => 'main']))->assertNoContent();
    expect(Site::withoutGlobalScopes()->count())->toBe(0);
});

it('creates and maintains site channels by both key and numeric identifier', function (bool $numeric) {
    Site::create(['key' => 'main', 'name' => 'Main', 'team_id' => $this->team->id]);
    $created = $this->postJson(route('cms-api.core.channels.create', ['site' => 'main']), ['key' => 'web', 'name' => 'Website'])
        ->assertCreated()->assertJsonPath('data.type', 'cms-channel');
    $id = $created->json('data.id');
    $this->getJson(route('cms-api.core.channels', ['site' => 'main']))->assertOk()->assertJsonPath('data.0.key', 'web');
    $parameters = ['site' => 'main', 'channel' => $numeric ? $id : 'web'];
    $this->patchJson(route('cms-api.core.channel.update', $parameters), ['name' => 'Public website'])
        ->assertOk()->assertJsonPath('data.name', 'Public website');
    $this->deleteJson(route('cms-api.core.channel.delete', $parameters))->assertNoContent();
    $this->getJson(route('cms-api.core.channels', ['site' => 'main']))->assertOk()->assertJsonCount(0, 'data');
})->with(['numeric id' => [true], 'stable key' => [false]]);

it('creates and reads identities aliases and environment scoped settings', function () {
    Site::create(['key' => 'main', 'name' => 'Main', 'team_id' => $this->team->id]);
    $this->postJson(route('cms-api.core.identity.create', ['site' => 'main']), ['content_type' => 'page', 'content_id' => '123', 'canonical_path' => '/home'])
        ->assertCreated()->assertJsonPath('data.type', 'cms-content-identity')->assertJsonPath('data.canonical_path', '/home');
    $this->getJson(route('cms-api.core.identities', ['site' => 'main']))->assertOk()->assertJsonPath('data.0.content_id', '123');
    $this->postJson(route('cms-api.core.alias.create', ['site' => 'main']), ['alias' => '/legacy', 'target_type' => 'page', 'target_id' => '123', 'redirect_status' => 308])
        ->assertCreated()->assertJsonPath('data.type', 'cms-content-alias')->assertJsonPath('data.redirect_status', 308);
    $this->getJson(route('cms-api.core.aliases', ['site' => 'main']))->assertOk()->assertJsonPath('data.0.alias', '/legacy');
    $this->getJson(route('cms-api.core.alias', ['site' => 'main', 'alias' => 'legacy']))->assertOk()->assertJsonPath('data.target_id', '123');
    $this->putJson(route('cms-api.core.setting.put', ['site' => 'main', 'key' => 'branding']), ['value' => ['name' => 'Production']])
        ->assertCreated()->assertJsonPath('data.value.name', 'Production');
    $this->putJson(route('cms-api.core.setting.put', ['site' => 'main', 'key' => 'branding']), ['value' => ['name' => 'Staging'], 'environment' => 'staging'])
        ->assertCreated()->assertJsonPath('data.environment', 'staging');
    $this->getJson(route('cms-api.core.settings', ['site' => 'main']))->assertOk()->assertJsonPath('data.0.value.name', 'Production');
    $this->getJson(route('cms-api.core.settings', ['site' => 'main', 'environment' => 'staging']))->assertOk()->assertJsonPath('data.0.value.name', 'Staging');
});

it('returns not found for absent or foreign tenant resources', function (bool $foreign) {
    if ($foreign) {
        $other = $this->otherTeam;
        Site::create(['key' => 'unavailable', 'name' => 'Private', 'team_id' => $other->id]);
    }
    $this->getJson(route('cms-api.core.site', ['site' => 'unavailable']))->assertNotFound();
    $this->getJson(route('cms-api.core.channels', ['site' => 'unavailable']))->assertNotFound();
    $this->getJson(route('cms-api.core.alias', ['site' => 'unavailable', 'alias' => 'absent']))->assertNotFound();
    $this->patchJson(route('cms-api.core.site.update', ['site' => 'unavailable']), ['name' => 'Attempt'])->assertNotFound();
    $this->deleteJson(route('cms-api.core.site.delete', ['site' => 'unavailable']))->assertNotFound();
})->with(['absent' => [false], 'other tenant' => [true]]);

it('requires mutation ability and validates payloads without creating partial records', function () {
    Sanctum::actingAs($this->team, ['content:read'], 'sanctum');
    $this->postJson(route('cms-api.core.sites.create'), ['key' => 'denied', 'name' => 'Denied'])->assertForbidden();
    Sanctum::actingAs($this->team, ['content:read', 'content:write'], 'sanctum');
    $this->postJson(route('cms-api.core.sites.create'), [])->assertUnprocessable()->assertJsonValidationErrors(['key', 'name']);
    expect(Site::withoutGlobalScopes()->count())->toBe(0);
});

it('rejects malformed custom validation results before changing persistent state', function (mixed $validated, string $action, string $exception) {
    Site::create(['key' => 'main', 'name' => 'Main', 'team_id' => $this->team->id]);
    $request = new class($validated) extends Request
    {
        public function __construct(private mixed $validatedResult)
        {
            parent::__construct();
        }

        public function validate(array $rules, ...$params): mixed
        {
            return $this->validatedResult;
        }
    };
    $controller = app(CoreApiController::class);
    expect(fn () => $action === 'createSite' ? $controller->createSite($request) : $controller->putSetting($request, 'main', 'branding'))
        ->toThrow($exception);
    expect(Site::withoutGlobalScopes()->count())->toBe(1)
        ->and(Setting::withoutGlobalScopes()->count())->toBe(0);
})->with([
    'nonarray validation result' => [null, 'createSite', ValidationException::class],
    'nonarray setting' => [['value' => 'invalid'], 'putSetting', UnexpectedValueException::class],
    'list setting' => [['value' => ['invalid']], 'putSetting', UnexpectedValueException::class],
    'nonstring environment' => [['value' => ['name' => 'Valid'], 'environment' => 99], 'putSetting', UnexpectedValueException::class],
]);

it('refuses to serialize the wrong model through a core resource', function (string $resource) {
    expect(fn () => (new $resource(new stdClass))->resolve(request()))->toThrow(UnexpectedValueException::class);
})->with([
    CoreSiteResource::class,
    CoreChannelResource::class,
    CoreIdentityResource::class,
    CoreAliasResource::class,
    CoreSettingResource::class,
]);
