<?php

use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Liberu\Cms\TranslationManagement\Actions\TranslationManagementService;
use Liberu\Cms\TranslationManagement\Contracts\TranslationVendorInterface;
use Liberu\Cms\TranslationManagement\Models\TranslationJob;
use Liberu\Cms\TranslationManagement\Support\TranslationResult;
use Liberu\Cms\TranslationManagement\Support\TranslationVendorRegistry;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->otherTeam = Team::factory()->create();
    Sanctum::actingAs($this->team, ['content:read', 'content:write'], 'sanctum');
});

it('runs source capture assignment translation review and reconciliation over HTTP', function () {
    app(TranslationVendorRegistry::class)->register(new class implements TranslationVendorInterface
    {
        public function key(): string
        {
            return 'fixture';
        }

        public function translate(string $source, string $sourceLocale, string $targetLocale, array $context = []): TranslationResult
        {
            return new TranslationResult('Bonjour', 'fixture', 'fixture-v1', 0.25, ['request_id' => 'fixture']);
        }
    });
    $job = $this->postJson('/api/v1/cms/translation-management/jobs', ['name' => 'Website copy', 'source_locale' => 'en', 'target_locale' => 'fr'])
        ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
    $this->postJson("/api/v1/cms/translation-management/jobs/$job/queue")->assertOk()->assertJsonPath('data.status', 'queued');
    $change = $this->postJson("/api/v1/cms/translation-management/jobs/$job/source-changes", ['subject_type' => 'page', 'subject_id' => '42', 'field' => 'title', 'source_text' => 'Hello'])
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/cms/translation-management/source-changes/$change/assign", ['assignee_type' => 'user', 'assignee_id' => '7', 'role' => 'reviewer'])
        ->assertCreated()->assertJsonPath('data.status', 'assigned');
    $this->postJson("/api/v1/cms/translation-management/source-changes/$change/translate", ['vendor_key' => 'fixture', 'context' => ['audience' => 'public']])
        ->assertOk()->assertJsonPath('data.translated_text', 'Bonjour')->assertJsonPath('data.status', 'in_review');
    $this->postJson("/api/v1/cms/translation-management/source-changes/$change/review", ['decision' => 'approved', 'notes' => 'Reviewed'])
        ->assertOk()->assertJsonPath('data.status', 'approved');
    $this->postJson("/api/v1/cms/translation-management/jobs/$job/reconcile")->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.completed_units', 1);
    $this->getJson("/api/v1/cms/translation-management/jobs/$job")->assertOk()->assertJsonPath('data.source_changes.0.id', $change);
});

it('manages vendor memory and glossary records through validated HTTP commands', function () {
    $this->postJson('/api/v1/cms/translation-management/vendors', ['key' => 'fixture', 'driver' => 'fixture', 'name' => 'Fixture vendor', 'settings' => ['region' => 'test']])
        ->assertCreated()->assertJsonPath('data.key', 'fixture');
    $this->postJson('/api/v1/cms/translation-management/memory', ['source_locale' => 'en', 'target_locale' => 'fr', 'source_text' => 'Hello', 'translated_text' => 'Bonjour', 'metadata' => ['reviewed' => true]])
        ->assertCreated()->assertJsonPath('data.translated_text', 'Bonjour');
    $this->postJson('/api/v1/cms/translation-management/glossaries', ['source_locale' => 'en', 'target_locale' => 'fr', 'source_term' => 'Home', 'preferred_term' => 'Accueil', 'forbidden_terms' => ['Maison']])
        ->assertCreated()->assertJsonPath('data.preferred_term', 'Accueil');
});

it('filters jobs and cancels an active job', function () {
    $job = TranslationJob::create(['name' => 'Search target', 'source_locale' => 'en', 'target_locale' => 'fr', 'status' => 'queued', 'team_id' => $this->team->id]);
    $this->getJson('/api/v1/cms/translation-management/jobs?search=Search&status=queued&per_page=1')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $job->public_id)->assertJsonPath('meta.per_page', 1);
    $this->postJson("/api/v1/cms/translation-management/jobs/$job->public_id/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
});

it('keeps missing and foreign job and source-change identifiers unavailable', function (bool $foreign) {
    $jobId = 'missing-job';
    $changeId = 999999;
    if ($foreign) {
        $service = app(TranslationManagementService::class);
        $job = $service->createJob(['name' => 'Private job', 'source_locale' => 'en', 'target_locale' => 'fr', 'team_id' => $this->otherTeam->id]);
        $change = $service->addSourceChange($job, ['subject_type' => 'page', 'subject_id' => '7', 'field' => 'title', 'source_text' => 'Private']);
        $jobId = $job->public_id;
        $changeId = $change->id;
    }
    $this->getJson("/api/v1/cms/translation-management/jobs/$jobId")->assertNotFound();
    foreach (['queue', 'cancel', 'reconcile', 'source-changes'] as $operation) {
        $this->postJson("/api/v1/cms/translation-management/jobs/$jobId/$operation", [])->assertNotFound();
    }
    foreach (['translate', 'review', 'assign'] as $operation) {
        $this->postJson("/api/v1/cms/translation-management/source-changes/$changeId/$operation", [])->assertNotFound();
    }
})->with(['missing' => [false], 'other tenant' => [true]]);

it('rejects mutation requests without write ability and invalid locale pairs', function () {
    Sanctum::actingAs($this->team, ['content:read'], 'sanctum');
    $this->postJson('/api/v1/cms/translation-management/jobs', ['name' => 'Denied', 'source_locale' => 'en', 'target_locale' => 'fr'])->assertForbidden();
    Sanctum::actingAs($this->team, ['content:read', 'content:write'], 'sanctum');
    $this->postJson('/api/v1/cms/translation-management/jobs', ['name' => 'Invalid', 'source_locale' => 'en', 'target_locale' => 'en'])->assertUnprocessable()->assertJsonValidationErrors('target_locale');
    expect(TranslationJob::withoutGlobalScopes()->count())->toBe(0);
});
