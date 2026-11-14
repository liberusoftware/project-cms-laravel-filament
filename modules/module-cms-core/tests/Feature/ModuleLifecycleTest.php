<?php

use Illuminate\Support\Facades\File;
use Liberu\Cms\Contracts\Access\PermissionRegistrarInterface;
use Liberu\Cms\Contracts\Module\ModuleInterface;
use Liberu\Cms\Contracts\Module\ModuleManagerInterface;
use Liberu\Cms\Contracts\Module\ModuleRegistryInterface;
use Liberu\Cms\Core\CmsCoreServiceProvider;
use Liberu\Cms\Core\Exceptions\ModuleDependencyException;
use Liberu\Cms\Core\Module\AbstractModule;
use Liberu\Cms\Core\Module\ArrayModuleStateRepository;
use Liberu\Cms\Core\Module\ModuleServiceProvider;
use Liberu\Cms\Core\Tenant\NullTenantResolver;

it('keeps registered modules inert when disabled and loads their assets when enabled', function (bool $disabled) {
    config(['cms.disabled_modules' => $disabled ? ['fixture', 99] : 'invalid']);
    $directory = sys_get_temp_dir().'/cms-lifecycle-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($directory.'/views');
    File::ensureDirectoryExists($directory.'/lang/en');
    File::ensureDirectoryExists($directory.'/migrations');
    File::put($directory.'/config.php', '<?php return ["enabled" => true];');
    File::put($directory.'/routes.php', '<?php Illuminate\\Support\\Facades\\Route::get("/fixture-route", fn () => response("Fixture"));');
    File::put($directory.'/views/index.blade.php', 'Fixture view');
    File::put($directory.'/lang/en/messages.php', '<?php return ["title" => "Fixture title"];');
    $provider = new class(app(), $directory) extends ModuleServiceProvider
    {
        public function __construct($app, private string $directory)
        {
            parent::__construct($app);
        }

        public function module(): AbstractModule
        {
            return new class() extends AbstractModule
            {
                public function key(): string
                {
                    return 'fixture';
                }

                public function name(): string
                {
                    return 'Fixture';
                }

                public function version(): string
                {
                    return '1.0.0';
                }
            };
        }

        protected function registerModule(): void
        {
            $this->mergeModuleConfig($this->directory.'/config.php', 'fixture');
        }

        protected function bootModule(): void
        {
            $this->loadModuleMigrations($this->directory.'/migrations');
            $this->loadModuleRoutesFrom($this->directory.'/missing.php');
            $this->loadModuleRoutesFrom($this->directory.'/routes.php');
            $this->loadModuleViews($this->directory.'/views', 'fixture');
            $this->loadModuleTranslations($this->directory.'/lang', 'fixture');
        }
    };
    try {
        $provider->register();
        $provider->boot();
        expect(config('fixture.enabled'))->toBeTrue()
            ->and(app(ModuleRegistryInterface::class)->has('fixture'))->toBeTrue()
            ->and(app(ModuleManagerInterface::class)->isEnabled('fixture'))->toBe(! $disabled)
            ->and($provider->module()->dependencies())->toBe([]);
        if ($disabled) {
            $this->get('/fixture-route')->assertNotFound();
        } else {
            $this->get('/fixture-route')->assertOk()->assertSee('Fixture');
            expect(view('fixture::index')->render())->toBe('Fixture view')
                ->and(trans('fixture::messages.title'))->toBe('Fixture title');
        }
    } finally {
        File::deleteDirectory($directory);
    }
})->with(['enabled' => [false], 'disabled' => [true]]);

it('registers core owned permissions when a permission backend is supplied', function () {
    $registrar = Mockery::mock(PermissionRegistrarInterface::class);
    $registrar->shouldReceive('register')->once()->withArgs(fn ($group) => $group->key === 'core' && $group->permissions() === ['core.view', 'core.create', 'core.update', 'core.delete']);
    app()->instance(PermissionRegistrarInterface::class, $registrar);
    (new CmsCoreServiceProvider(app()))->boot();
    expect(app(PermissionRegistrarInterface::class))->toBe($registrar);
});

it('reports unknown modules through the public manager boundary', function () {
    $manager = app(ModuleManagerInterface::class);
    expect(fn () => $manager->enable('unknown'))->toThrow(ModuleDependencyException::class, 'Unknown module')
        ->and(fn () => $manager->disable('unknown'))->toThrow(ModuleDependencyException::class, 'Unknown module')
        ->and($manager->dependenciesOf('unknown'))->toBe([]);
});

it('deduplicates dependency declarations and restores forgotten state defaults', function () {
    [$manager] = makeManager([fakeModule('base'), fakeModule('child', ['base', 'base'])]);
    expect($manager->dependenciesOf('child'))->toBe(['base']);
    $state = new ArrayModuleStateRepository(['child' => false]);
    $state->forget('child');
    expect($state->isEnabled('child', true))->toBeTrue();
    $resolver = new NullTenantResolver();
    expect($resolver->tenantModel())->toBeNull()->and($resolver->currentTenantId())->toBeNull();
});

it('supports a declarative module without custom registration or boot behavior', function () {
    $provider = new class(app()) extends ModuleServiceProvider
    {
        public function module(): ModuleInterface
        {
            return fakeModule('declarative');
        }
    };
    $provider->register();
    $provider->boot();
    expect(app(ModuleRegistryInterface::class)->has('declarative'))->toBeTrue()
        ->and(app(ModuleManagerInterface::class)->isEnabled('declarative'))->toBeTrue();
});
