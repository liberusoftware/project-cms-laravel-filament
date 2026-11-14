<?php

use App\Http\Middleware\EnsureTwoFactorForPrivilegedUsers;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetPermissionsTeam;
use App\Models\Collection;
use App\Models\Team;
use App\Models\User;
use App\Support\FilamentTenantResolver;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Liberu\Cms\Contracts\Tenancy\TenantContextInterface;

uses(RefreshDatabase::class);

it('reports an unknown platform administrator without provisioning access', function () {
    $this->artisan('cms:platform-admin', ['email' => 'missing@example.test'])
        ->expectsOutput('No user found with that email address.')
        ->assertFailed();
    expect(User::count())->toBe(0);
});

it('preserves conservative security header defaults when configuration has invalid types', function () {
    config(['security-headers.content_type_options' => [], 'security-headers.hsts.enabled' => true, 'security-headers.hsts.max_age' => 'invalid', 'security-headers.hsts.preload' => true]);
    $response = (new SecurityHeaders)->handle(Request::create('/'), fn () => response('ok'));
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Strict-Transport-Security'))->toContain('max-age=31536000', 'preload');
});

it('lets privileged users proceed outside a Filament panel', function () {
    config(['two-factor.enforce' => true]);
    Filament::setCurrentPanel(null);
    $actor = User::factory()->create(['is_platform_admin' => true]);
    $request = Request::create('/outside-panel');
    $request->setUserResolver(fn () => $actor);
    expect((new EnsureTwoFactorForPrivilegedUsers)->handle($request, fn () => response('allowed'))->getContent())->toBe('allowed');
});

it('allows enrollment and logout routes so privileged users can complete two factor setup', function (string $name) {
    config(['two-factor.enforce' => true]);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $actor = User::factory()->create(['is_platform_admin' => true]);
    $request = Request::create('/two-factor');
    $request->setUserResolver(fn () => $actor);
    $route = (new Route('GET', '/two-factor', fn () => null))->name($name);
    $request->setRouteResolver(fn () => $route);
    expect((new EnsureTwoFactorForPrivilegedUsers)->handle($request, fn () => response('allowed'))->getContent())->toBe('allowed');
})->with(['filament.admin.two-factor.setup', 'filament.admin.auth.logout']);

it('sets permission scope from the active panel tenant and preserves it without a tenant', function () {
    $actor = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $actor->id]);
    $this->actingAs($actor);
    config(['permission.teams' => true]);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($team);
    setPermissionsTeamId(null);
    $middleware = new SetPermissionsTeam;
    expect($middleware->handle(Request::create('/'), fn () => response('allowed'))->getContent())->toBe('allowed')
        ->and(getPermissionsTeamId())->toBe($team->id);
    Filament::setTenant(null);
    $middleware->handle(Request::create('/'), fn () => response('allowed'));
    expect(getPermissionsTeamId())->toBe($team->id);
});

it('uses panel tenancy only when the API tenant context is empty', function () {
    $actor = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $actor->id]);
    $this->actingAs($actor);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($team);
    $resolver = app(FilamentTenantResolver::class);
    $context = app(TenantContextInterface::class);
    $context->setTenantId(null);
    expect($resolver->currentTenantId())->toBe($team->id);
    $context->setTenantId(999);
    expect($resolver->currentTenantId())->toBe(999);
    $context->setTenantId(null);
    Filament::setTenant(null);
    expect($resolver->currentTenantId())->toBeNull();
});

it('restores a personal team when a persisted user has no current team selected', function () {
    $actor = User::factory()->create();
    $team = $actor->createPersonalTeam();
    $actor->forceFill(['current_team_id' => null])->save();
    $actor->unsetRelation('currentTeam');
    expect($actor->currentTeam->is($team))->toBeTrue()
        ->and($actor->fresh()->current_team_id)->toBe($team->id)
        ->and($actor->isCurrentTeam($team))->toBeTrue()
        ->and($actor->isCurrentTeam((new Team)->forceFill(['id' => $team->id + 1])))->toBeFalse();
});

it('exposes collection menu links and labels for the public page route', function () {
    $collection = new Collection(['name' => 'Guides', 'slug' => 'guides']);
    expect($collection->menu_link)->toBe(route('pages.show', 'guides'))
        ->and($collection->menu_name)->toBe('Guides')
        ->and(Collection::getFilamentSearchLabel())->toBe('name');
});
