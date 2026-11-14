<?php

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Liberu\Cms\Contracts\Access\AccessControlInterface;
use Liberu\Cms\Contracts\Content\WorkflowState;
use Liberu\Cms\Contracts\Module\ModuleManagerInterface;
use Liberu\Cms\Core\CmsCoreServiceProvider;
use Liberu\Cms\Core\Exceptions\ModuleDependencyException;
use Liberu\Cms\Core\Filament\Concerns\AuthorizesWithPermissions;
use Liberu\Cms\Core\Http\Concerns\WritesWorkflowContent;
use Liberu\Cms\Core\Models\Site;
use Liberu\Cms\Core\Module\AbstractModule;
use Liberu\Cms\Core\Module\ArrayModuleStateRepository;
use Liberu\Cms\Core\Module\DatabaseModuleStateRepository;
use Liberu\Cms\Core\Module\ModuleManager;
use Liberu\Cms\Core\Module\ModuleRegistry;

uses(RefreshDatabase::class);

it('fails closed without an access backend and maps record access through the owned permission', function () {
    app()->offsetUnset(AccessControlInterface::class);
    $resource = new class
    {
        use AuthorizesWithPermissions;

        protected static function cmsPermissionKey(): string
        {
            return 'sites';
        }
    };
    expect($resource::canViewAny())->toBeFalse();
    $access = Mockery::mock(AccessControlInterface::class);
    $access->shouldReceive('can')->with('sites.view')->once()->andReturnTrue();
    $access->shouldReceive('can')->with('sites.delete')->once()->andReturnFalse();
    app()->instance(AccessControlInterface::class, $access);
    expect($resource::canView(new Site))->toBeTrue()->and($resource::canDeleteAny())->toBeFalse();
});

it('keeps absent and unchanged workflow state out of mass assignment', function () {
    $consumer = new class
    {
        use WritesWorkflowContent;

        public function update(array $data): array
        {
            $next = $this->pullStatus($data);
            if ($this->shouldTransition(WorkflowState::Draft, $next)) {
                $data['status'] = $next->value;
            }

            return $data;
        }
    };
    expect($consumer->update(['title' => 'Original']))->toBe(['title' => 'Original'])
        ->and($consumer->update(['title' => 'Original', 'status' => 'draft']))->toBe(['title' => 'Original']);
});

it('uses the requested module state default when the database is unavailable', function () {
    $resolver = Mockery::mock(ConnectionResolverInterface::class);
    $resolver->shouldReceive('connection')->once()->andThrow(new RuntimeException('Database unavailable'));
    expect((new DatabaseModuleStateRepository($resolver))->isEnabled('optional', false))->toBeFalse();
});

it('tolerates malformed disabled-module configuration and reports unknown dependencies through its public manager', function () {
    config(['cms.disabled_modules' => 'invalid']);
    (new CmsCoreServiceProvider(app()))->register();
    $manager = app(ModuleManagerInterface::class);
    expect(fn () => $manager->enable('unknown'))->toThrow(ModuleDependencyException::class, 'Unknown module')
        ->and($manager->dependenciesOf('unknown'))->toBe([]);
});

it('returns each declared dependency only once', function () {
    $descriptor = fn (string $key, array $dependencies) => new class($key, $dependencies) extends AbstractModule
    {
        public function __construct(private string $moduleKey, private array $moduleDependencies) {}

        public function key(): string
        {
            return $this->moduleKey;
        }

        public function name(): string
        {
            return ucfirst($this->moduleKey);
        }

        public function version(): string
        {
            return '1.0.0';
        }

        public function dependencies(): array
        {
            return $this->moduleDependencies;
        }
    };
    $registry = new ModuleRegistry;
    $registry->register($descriptor('base', []));
    $registry->register($descriptor('child', ['base', 'base']));
    expect((new ModuleManager($registry, new ArrayModuleStateRepository))->dependenciesOf('child'))->toBe(['base']);
});
