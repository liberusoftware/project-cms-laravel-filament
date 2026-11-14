<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Liberu\Cms\TranslationManagement\Actions\TranslationManagementService;
use Liberu\Cms\TranslationManagement\Contracts\TranslationVendorInterface;
use Liberu\Cms\TranslationManagement\Models\TranslationGlossary;
use Liberu\Cms\TranslationManagement\Models\TranslationSourceChange;
use Liberu\Cms\TranslationManagement\Models\TranslationVendor;
use Liberu\Cms\TranslationManagement\Support\TranslationResult;
use Liberu\Cms\TranslationManagement\Support\TranslationVendorRegistry;

uses(RefreshDatabase::class);

it('validates direct translation commands before persisting changes and preserves assignment ownership', function () {
    $service = app(TranslationManagementService::class);
    $job = $service->createJob(['name' => 'Docs', 'source_locale' => 'en', 'target_locale' => 'fr']);
    $change = $service->addSourceChange($job, ['subject_type' => 'page', 'subject_id' => '1', 'field' => 'title', 'source_text' => 'Hello']);
    expect(fn () => $service->addSourceChange($job, ['field' => '', 'source_text' => 'Hello']))->toThrow(ValidationException::class)
        ->and(fn () => $service->review($change, 'invalid'))->toThrow(ValidationException::class)
        ->and(fn () => $service->addVendor([]))->toThrow(ValidationException::class)
        ->and(fn () => $service->glossary(['source_locale' => 'en', 'target_locale' => 'fr', 'source_term' => 'Home']))->toThrow(ValidationException::class);
    $assignment = $service->assign($change, 'user', '7', 'reviewer');
    expect($assignment->job->is($job))->toBeTrue()
        ->and($assignment->sourceChange->is($change))->toBeTrue()
        ->and($job->assignments->sole()->is($assignment))->toBeTrue()
        ->and(TranslationSourceChange::count())->toBe(1)
        ->and($change->fresh()->status)->toBe('pending')
        ->and(TranslationVendor::count())->toBe(0)->and(TranslationGlossary::count())->toBe(0);
});

it('reuses an in-review translation and prevents mutation after cancellation', function () {
    $vendor = new class implements TranslationVendorInterface
    {
        public int $calls = 0;

        public function key(): string
        {
            return 'fixture-idempotent';
        }

        public function translate(string $source, string $sourceLocale, string $targetLocale, array $context = []): TranslationResult
        {
            $this->calls++;

            return new TranslationResult('Bonjour', $this->key(), 'fixture', 0.1);
        }
    };
    $registry = app(TranslationVendorRegistry::class);
    $registry->register($vendor);
    $service = app(TranslationManagementService::class);
    $job = $service->createJob(['name' => 'Docs', 'source_locale' => 'en', 'target_locale' => 'fr']);
    $change = $service->addSourceChange($job, ['subject_type' => 'page', 'subject_id' => '1', 'field' => 'title', 'source_text' => 'Hello']);
    $first = $service->translate($change, $vendor->key());
    $second = $service->translate($change->fresh(), $vendor->key());
    expect($vendor->calls)->toBe(1)->and($second->translated_hash)->toBe($first->translated_hash);
    $service->cancel($job->fresh());
    expect(fn () => $service->queue($job->fresh()))->toThrow(ValidationException::class)
        ->and(fn () => $registry->resolve('unknown-vendor'))->toThrow(InvalidArgumentException::class)
        ->and($job->fresh()->status)->toBe('cancelled');
});
