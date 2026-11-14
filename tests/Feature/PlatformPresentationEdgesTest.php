<?php

use App\Actions\Socialstream\CreateUserFromProvider;
use App\Filament\Admin\Pages\PlatformSetup;
use App\Filament\Pages\Workspace;
use App\Filament\Resources\Pages\Pages\ListPages;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\PanelSetup;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Jetstream\Features;
use Laravel\Socialite\Two\User as ProviderUser;
use Liberu\Cms\Pages\Models\Page;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('keeps the overview unavailable without a panel or permitted website tenant', function () {
    Filament::setCurrentPanel(null);
    Filament::setTenant(null);
    $workspace = new Workspace;
    expect($workspace->quickLinks())->toBe([])
        ->and($workspace->setupDetails())->toBeNull();
});

it('persists platform wizard progress before completion', function () {
    $actor = User::factory()->create(['is_platform_admin' => true]);
    $this->actingAs($actor);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant(null);
    Livewire::test(PlatformSetup::class)
        ->fillForm(['site_name' => 'Draft platform', 'site_email' => 'owner@example.test'])
        ->goToNextWizardStep()->assertHasNoErrors();
    $setup = PanelSetup::where('scope', 'platform')->sole();
    expect($setup->data)->toMatchArray(['site_name' => 'Draft platform', 'site_email' => 'owner@example.test'])
        ->and($setup->completed_at)->toBeNull();
});

it('shows the workflow badge color for every editorial status', function (string $status, string $color) {
    $actor = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $actor->id]);
    $this->actingAs($actor);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($team);
    $page = Page::factory()->create(['team_id' => $team->id, 'user_id' => $actor->id, 'status' => $status]);
    $component = Livewire::test(ListPages::class)->assertSuccessful()->assertCanSeeTableRecords([$page]);
    expect($component->instance()->getTable()->getColumn('status')->record($page)->getColor($page->status))->toBe($color);
})->with(['published' => ['published', 'success'], 'review' => ['review', 'warning'], 'archived' => ['archived', 'gray']]);

it('edits a user and saves or clears roles through the resource form', function (bool $clearRoles) {
    $actor = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $actor->id]);
    $this->actingAs($actor);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($team);
    setPermissionsTeamId($team->id);
    foreach (['ViewAny:User', 'View:User', 'Update:User', 'Delete:User'] as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $target = User::factory()->create();
    $target->teams()->attach($team);
    $role = Role::create(['name' => 'editor-test', 'guard_name' => 'web', 'team_id' => $team->id]);
    if ($clearRoles) {
        $target->assignRole($role);
        config(['permission.teams' => false]);
    }
    Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
        ->assertSuccessful()->assertActionExists('delete')
        ->fillForm(['name' => 'Updated editor', 'email' => $target->email, 'password' => 'long-secure-password', 'roles' => $clearRoles ? [] : [$role->id]])
        ->call('save')->assertHasNoFormErrors();
    expect($target->fresh()->name)->toBe('Updated editor')
        ->and($target->fresh()->hasRole('editor-test'))->toBe(! $clearRoles)
        ->and(UserResource::getRelations())->toBe([]);
})->with(['tenant roles' => [false], 'cleared roles without team scoping' => [true]]);

it('uses the provider avatar when creating a connected user', function () {
    config(['jetstream.features' => [...config('jetstream.features', []), Features::profilePhotos()]]);
    Storage::fake('public');
    $avatar = 'https://provider.example.test/avatar.png';
    Http::fake([$avatar => Http::response(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='), 200)]);
    $provider = (new ProviderUser)->map(['id' => 'avatar-user', 'name' => 'Avatar User', 'email' => 'avatar@example.test', 'avatar' => $avatar])->setToken('test-token');
    $actor = app(CreateUserFromProvider::class)->create('github', $provider);
    expect($actor->email_verified_at)->not->toBeNull()
        ->and($actor->profile_photo_path)->not->toBeNull()
        ->and($actor->connectedAccounts()->sole()->provider_id)->toBe('avatar-user');
    Storage::disk('public')->assertExists($actor->profile_photo_path);
    Http::assertSentCount(1);
});
