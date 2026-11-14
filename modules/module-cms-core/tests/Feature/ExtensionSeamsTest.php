<?php

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Liberu\Cms\Contracts\Access\AccessControlInterface;
use Liberu\Cms\Contracts\Content\WorkflowInterface;
use Liberu\Cms\Contracts\Content\WorkflowState;
use Liberu\Cms\Contracts\Hooks\Filters\ApiResourceFilter;
use Liberu\Cms\Contracts\Hooks\Filters\ContentQueryFilter;
use Liberu\Cms\Contracts\Hooks\HookBusInterface;
use Liberu\Cms\Contracts\Media\MediaItemInterface;
use Liberu\Cms\Contracts\Tenancy\TenantContextInterface;
use Liberu\Cms\Core\Database\Concerns\FiltersContentQueries;
use Liberu\Cms\Core\Filament\Concerns\AuthorizesWithPermissions;
use Liberu\Cms\Core\Http\Concerns\EmbedsFeaturedMedia;
use Liberu\Cms\Core\Http\Concerns\FiltersApiResource;
use Liberu\Cms\Core\Http\Concerns\WritesWorkflowContent;
use Liberu\Cms\Core\Models\Site;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('reasserts tenant isolation after a query extension removes global scopes', function () {
    Site::create(['key' => 'mine', 'name' => 'Mine', 'team_id' => 1]);
    Site::create(['key' => 'other', 'name' => 'Other', 'team_id' => 2]);
    app(HookBusInterface::class)->listen(ContentQueryFilter::class, function (ContentQueryFilter $filter) {
        $filter->query->withoutGlobalScopes();
    });
    $repository = new class()
    {
        use FiltersContentQueries;

        public function sites(): array
        {
            return $this->filterContentQuery('sites.visible', Site::query())->pluck('key')->all();
        }
    };
    app(TenantContextInterface::class)->setTenantId(1);
    expect($repository->sites())->toBe(['mine']);
    app(TenantContextInterface::class)->setTenantId(null);
    expect($repository->sites())->toBe(['mine', 'other']);
});

it('allows a resource extension to reshape its serialized public payload', function () {
    app(HookBusInterface::class)->listen(ApiResourceFilter::class, function (ApiResourceFilter $filter) {
        $filter->data['extended'] = $filter->model->key;
    });
    $resource = new class((object) ['key' => 'main']) extends JsonResource
    {
        use FiltersApiResource;

        public function toArray(Request $request): array
        {
            return $this->withApiResourceFilter(['title' => 'Public']);
        }
    };
    expect($resource->resolve(request()))->toBe(['title' => 'Public', 'extended' => 'main']);
});

it('embeds media through its public contract and keeps missing images nullable', function () {
    $media = Mockery::mock(MediaItemInterface::class);
    $media->shouldReceive('url')->twice()->andReturn('https://example.test/image.png');
    $media->shouldReceive('metadata')->twice()->andReturn(['alt' => 'Description'], []);
    $resource = new class($media) extends JsonResource
    {
        use EmbedsFeaturedMedia;

        public function toArray(Request $request): array
        {
            return ['image' => $this->featuredMediaPayload($this->resource)];
        }
    };
    expect($resource->resolve(request())['image'])->toBe(['url' => 'https://example.test/image.png', 'alt' => 'Description'])
        ->and($resource->resolve(request())['image'])->toBe(['url' => 'https://example.test/image.png', 'alt' => null]);
    $class = $resource::class;
    expect((new $class(null))->resolve(request())['image'])->toBeNull();
});

it('denies resource access without an authority and maps every action to its owned permission', function () {
    $resource = new class()
    {
        use AuthorizesWithPermissions;

        protected static function cmsPermissionKey(): string
        {
            return 'sites';
        }
    };
    expect($resource::canViewAny())->toBeFalse();
    $access = Mockery::mock(AccessControlInterface::class);
    foreach (['view' => true, 'create' => false, 'update' => false, 'delete' => false] as $ability => $allowed) {
        $access->shouldReceive('can')->with('sites.'.$ability)->andReturn($allowed);
    }
    app()->instance(AccessControlInterface::class, $access);
    $site = new Site();
    expect($resource::canViewAny())->toBeTrue()->and($resource::canView($site))->toBeTrue()
        ->and($resource::canCreate())->toBeFalse()->and($resource::canEdit($site))->toBeFalse()
        ->and($resource::canDelete($site))->toBeFalse()->and($resource::canDeleteAny())->toBeFalse();
});

it('removes mass assignable workflow state and authorizes transitions through the workflow contract', function () {
    $workflow = Mockery::mock(WorkflowInterface::class);
    $workflow->shouldReceive('canTransition')->with(WorkflowState::Draft, WorkflowState::Review)->once()->andReturnTrue();
    $workflow->shouldReceive('canTransition')->with(WorkflowState::Review, WorkflowState::Published)->once()->andReturnFalse();
    app()->instance(WorkflowInterface::class, $workflow);
    $consumer = new class()
    {
        use WritesWorkflowContent;

        public function update(array $data, WorkflowState $from): array
        {
            $to = $this->pullStatus($data);
            if ($this->shouldTransition($from, $to)) {
                $data['applied_status'] = $to->value;
            }

            return $data;
        }
    };
    expect($consumer->update(['title' => 'A'], WorkflowState::Draft))->toBe(['title' => 'A'])
        ->and($consumer->update(['status' => 'draft'], WorkflowState::Draft))->toBe([])
        ->and($consumer->update(['status' => 'review'], WorkflowState::Draft))->toBe(['applied_status' => 'review'])
        ->and(fn () => $consumer->update(['status' => 'published'], WorkflowState::Review))->toThrow(HttpException::class, 'Illegal status transition.');
});
