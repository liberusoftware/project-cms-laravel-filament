<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Liberu\Cms\Core\Models\Site;
use Liberu\Cms\SiteFactory\Models\SiteDomain;
use Liberu\Cms\SiteFactory\Models\SiteTemplate;
use Liberu\Cms\SiteFactory\Services\SiteFactoryService;
use Liberu\Cms\SiteFactoryApi\Http\SiteFactoryController;
use Liberu\Cms\SiteFactoryApi\Tests\Tenant;

it('lists templates before matching the numeric site detail route', function () {
    $this->getJson('/api/v1/cms/site-factory/templates')->assertOk()->assertExactJson(['data' => []]);
    $this->postJson('/api/v1/cms/site-factory/templates', ['key' => 'simple', 'name' => 'Simple'])
        ->assertCreated()->assertJsonPath('data.configuration', []);
    $this->postJson('/api/v1/cms/site-factory/templates', ['key' => 'starter', 'name' => 'Starter', 'configuration' => ['theme' => 'light'], 'initial_content' => ['pages' => ['home']]])
        ->assertCreated()->assertJsonPath('data.initial_content.pages.0', 'home');
    $this->getJson('/api/v1/cms/site-factory/templates')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.1.key', 'starter');
});

it('provisions filters updates clones and archives sites by numeric identifier', function () {
    $this->postJson('/api/v1/cms/site-factory/templates', ['key' => 'starter', 'name' => 'Starter'])->assertCreated();
    $response = $this->postJson('/api/v1/cms/site-factory', ['key' => 'main', 'name' => 'Main', 'template' => 'starter'])->assertCreated()->assertJsonPath('data.status', 'active');
    $id = $response->json('data.id');
    $this->getJson('/api/v1/cms/site-factory?filter=main&page[size]=1')->assertOk()->assertJsonPath('data.0.key', 'main')->assertJsonPath('meta.per_page', 1);
    $this->getJson('/api/v1/cms/site-factory')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/cms/site-factory/'.$id)->assertOk()->assertJsonPath('data.name', 'Main');
    $this->patchJson('/api/v1/cms/site-factory/'.$id, ['name' => 'Renamed', 'status' => 'suspended'])->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.status', 'suspended');
    $this->postJson('/api/v1/cms/site-factory/'.$id.'/suspend')->assertOk()->assertJsonPath('data.status', 'suspended');
    $clone = $this->postJson('/api/v1/cms/site-factory/'.$id.'/clone', ['key' => 'copy', 'name' => 'Copy'])->assertCreated()->json('data.id');
    $this->postJson('/api/v1/cms/site-factory/'.$id.'/archive')->assertOk()->assertJsonPath('data.status', 'archived');
    $this->patchJson('/api/v1/cms/site-factory/'.$id, ['status' => 'active'])->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->deleteJson('/api/v1/cms/site-factory/'.$clone)->assertNoContent();
    expect(Site::findOrFail($clone)->status)->toBe('archived');
    $this->deleteJson('/api/v1/cms/site-factory/'.$clone.'/teardown')->assertUnprocessable()->assertJsonValidationErrors('confirm');
    $this->deleteJson('/api/v1/cms/site-factory/'.$clone.'/teardown', ['confirm' => true])->assertNoContent();
    expect(Site::find($clone))->toBeNull();
});

it('maintains lifecycle aliases addressed by the stable site key', function () {
    $this->postJson('/api/v1/cms/site-factory', ['key' => 'source', 'name' => 'Source'])->assertCreated();
    $this->postJson('/api/v1/cms/site-factory/sites/source/clone', ['key' => 'copy', 'name' => 'Copy'])->assertCreated()->assertJsonPath('data.key', 'copy');
    $this->postJson('/api/v1/cms/site-factory/sites/copy/suspend')->assertOk()->assertJsonPath('data.status', 'suspended');
    $this->postJson('/api/v1/cms/site-factory/sites/copy/archive')->assertOk()->assertJsonPath('data.status', 'archived');
    $this->deleteJson('/api/v1/cms/site-factory/sites/copy/teardown', ['confirm' => true])->assertNoContent();
    $this->getJson('/api/v1/cms/site-factory?filter=copy')->assertOk()->assertJsonPath('meta.total', 0);
});

it('verifies a provisioned domain only with its verification token', function () {
    $this->postJson('/api/v1/cms/site-factory', ['key' => 'domain-site', 'name' => 'Domain site', 'domain' => 'site.example.test'])->assertCreated();
    $domain = SiteDomain::sole();
    $url = '/api/v1/cms/site-factory/domains/'.$domain->id.'/verify';
    $this->postJson($url, ['token' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('token');
    $this->postJson($url, ['token' => $domain->verification_token])->assertOk()->assertJsonPath('data.domain', 'site.example.test');
    expect($domain->fresh()->verified_at)->not->toBeNull();
    $this->postJson($url, ['token' => $domain->verification_token])->assertOk();
});

it('does not disclose or mutate another tenant site', function () {
    Tenant::create(['id' => 2]);
    $foreign = Site::create(['key' => 'private', 'name' => 'Private', 'team_id' => 2]);
    $this->getJson('/api/v1/cms/site-factory')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/cms/site-factory/'.$foreign->id)->assertNotFound();
    $this->patchJson('/api/v1/cms/site-factory/'.$foreign->id, ['name' => 'Attempt'])->assertNotFound();
    $this->deleteJson('/api/v1/cms/site-factory/'.$foreign->id)->assertNotFound();
    $this->postJson('/api/v1/cms/site-factory/sites/private/suspend')->assertNotFound();
    expect(Site::withoutGlobalScopes()->findOrFail($foreign->id)->name)->toBe('Private');
});

it('requires write abilities and validates provisioning input before creating records', function () {
    Sanctum::actingAs($this->tenant, ['site-factory:view'], 'sanctum');
    $this->getJson('/api/v1/cms/site-factory/templates')->assertOk();
    $this->postJson('/api/v1/cms/site-factory', ['key' => 'denied', 'name' => 'Denied'])->assertForbidden();
    Sanctum::actingAs($this->tenant, ['*'], 'sanctum');
    $this->postJson('/api/v1/cms/site-factory', [])->assertUnprocessable()->assertJsonValidationErrors(['key', 'name']);
    expect(Site::count())->toBe(0)->and(SiteTemplate::count())->toBe(0);
});

it('rejects an invalid custom validation result before provisioning', function () {
    $request = new class() extends Request
    {
        public function validate(array $rules, ...$params): mixed
        {
            return null;
        }
    };
    expect(fn () => app(SiteFactoryController::class)->store($request, app(SiteFactoryService::class)))
        ->toThrow(UnexpectedValueException::class, 'Validation did not return an array.');
    expect(Site::count())->toBe(0);
});

it('supports projected tenant models when strict missing attribute checks are enabled', function () {
    $tenant = Tenant::query()->select('id')->findOrFail($this->tenant->id);
    Sanctum::actingAs($tenant, ['*'], 'sanctum');
    $strict = Model::preventsAccessingMissingAttributes();
    Model::preventAccessingMissingAttributes();
    try {
        $this->postJson('/api/v1/cms/site-factory', ['key' => 'projected', 'name' => 'Projected tenant'])->assertCreated();
        $this->postJson('/api/v1/cms/site-factory/templates', ['key' => 'projected', 'name' => 'Projected template'])->assertCreated();
    } finally {
        Model::preventAccessingMissingAttributes($strict);
    }
});
