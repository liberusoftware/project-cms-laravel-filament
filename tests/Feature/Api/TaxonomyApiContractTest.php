<?php

use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Liberu\Cms\Taxonomy\Models\Taxonomy;
use Liberu\Cms\Taxonomy\Models\Term;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->otherTeam = Team::factory()->create();
    Sanctum::actingAs($this->team, ['*'], 'sanctum');
});

it('maintains a vocabulary and its ordered hierarchy through the API', function () {
    $created = $this->postJson('/api/v1/cms/taxonomies', ['key' => 'topics', 'name' => 'Topics', 'description' => 'Editorial topics'])
        ->assertCreated()->assertJsonPath('data.name', 'Topics');
    $id = $created->json('data.id');
    $this->getJson('/api/v1/cms/taxonomies')->assertOk()->assertJsonPath('data.0.key', 'topics');
    $this->getJson("/api/v1/cms/taxonomies/$id")->assertOk()->assertJsonPath('data.description', 'Editorial topics');
    $this->patchJson("/api/v1/cms/taxonomies/$id", ['name' => 'Updated topics'])->assertOk()->assertJsonPath('data.name', 'Updated topics');
    $parent = $this->postJson("/api/v1/cms/taxonomies/$id/terms", ['name' => 'Parent', 'slug' => 'parent', 'synonyms' => ['Ancestor'], 'translations' => ['fr' => 'Parent']])
        ->assertCreated()->json('data.id');
    $child = $this->postJson("/api/v1/cms/taxonomies/$id/terms", ['name' => 'Child', 'parent_id' => (int) $parent, 'position' => 2])
        ->assertCreated()->assertJsonPath('data.parent_id', $parent)->json('data.id');
    $this->getJson("/api/v1/cms/taxonomies/$id/terms?search=Child")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'child');
    $this->postJson("/api/v1/cms/taxonomy-terms/$child/move", ['parent_id' => null, 'position' => 0])
        ->assertOk()->assertJsonPath('data.parent_id', null)->assertJsonPath('data.position', 0);
    $this->getJson("/api/v1/cms/taxonomies/$id/terms")->assertOk()->assertJsonCount(2, 'data');
    $this->deleteJson("/api/v1/cms/taxonomies/$id")->assertNoContent();
    expect(Taxonomy::withoutGlobalScopes()->count())->toBe(0)->and(Term::withoutGlobalScopes()->count())->toBe(0);
});

it('searches vocabulary names keys and related terms while enforcing pagination limits', function (mixed $maximum, int $expected) {
    config(['cms-api.pagination.max' => $maximum]);
    $taxonomy = Taxonomy::create(['key' => 'topics', 'name' => 'Topics', 'team_id' => $this->team->id]);
    Taxonomy::create(['key' => 'other', 'name' => 'Other', 'team_id' => $this->team->id]);
    $taxonomy->terms()->create(['name' => 'Needle', 'slug' => 'needle', 'team_id' => $this->team->id]);
    foreach (['Topics', 'Needle'] as $search) {
        $this->getJson('/api/v1/cms/taxonomies?search='.$search)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', (string) $taxonomy->id);
    }
    $this->getJson('/api/v1/cms/taxonomies?key=topics')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/cms/taxonomies?per_page=100')->assertOk()->assertJsonPath('meta.per_page', $expected);
})->with(['configured limit' => [1, 1], 'invalid configuration fallback' => ['invalid', 100]]);

it('does not disclose or mutate absent and foreign vocabularies and terms', function (bool $foreign) {
    $taxonomyId = 99999;
    $termId = 99999;
    if ($foreign) {
        $taxonomy = Taxonomy::create(['key' => 'private', 'name' => 'Private', 'team_id' => $this->otherTeam->id]);
        $term = $taxonomy->terms()->create(['name' => 'Private term', 'slug' => 'private', 'team_id' => $this->otherTeam->id]);
        $taxonomyId = $taxonomy->id;
        $termId = $term->id;
    }
    $this->getJson("/api/v1/cms/taxonomies/$taxonomyId")->assertNotFound();
    $this->patchJson("/api/v1/cms/taxonomies/$taxonomyId", ['name' => 'Attempt'])->assertNotFound();
    $this->deleteJson("/api/v1/cms/taxonomies/$taxonomyId")->assertNotFound();
    $this->getJson("/api/v1/cms/taxonomies/$taxonomyId/terms")->assertNotFound();
    $this->postJson("/api/v1/cms/taxonomies/$taxonomyId/terms", ['name' => 'Attempt'])->assertNotFound();
    $this->postJson("/api/v1/cms/taxonomy-terms/$termId/move", ['parent_id' => null])->assertNotFound();
    if ($foreign) {
        expect($taxonomy->fresh()->name)->toBe('Private')->and($term->fresh()->name)->toBe('Private term');
    }
})->with(['missing' => [false], 'other team' => [true]]);

it('rejects unauthorized and invalid vocabulary mutations', function () {
    Sanctum::actingAs($this->team, ['content:read'], 'sanctum');
    $this->postJson('/api/v1/cms/taxonomies', ['key' => 'denied', 'name' => 'Denied'])->assertForbidden();
    Sanctum::actingAs($this->team, ['*'], 'sanctum');
    $this->postJson('/api/v1/cms/taxonomies', [])->assertUnprocessable()->assertJsonValidationErrors(['key', 'name']);
    $this->getJson('/api/v1/cms/taxonomies?per_page=101')->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
    expect(Taxonomy::withoutGlobalScopes()->count())->toBe(0);
});
