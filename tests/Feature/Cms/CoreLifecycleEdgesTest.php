<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Liberu\Cms\Contracts\Module\ModuleInterface;
use Liberu\Cms\Contracts\Module\ModuleManagerInterface;
use Liberu\Cms\Contracts\Module\ModuleRegistryInterface;
use Liberu\Cms\Core\Module\AbstractModule;
use Liberu\Cms\Core\Module\ModuleServiceProvider;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

function withCmsCoreArtifactDirectory(Closure $callback): void
{
    $original = app()->basePath();
    $directory = sys_get_temp_dir().'/cms-core-artifacts-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($directory.'/modules');
    File::put($directory.'/composer.json', '{"require":{}}');
    app()->setBasePath($directory);
    try {
        $callback($directory);
    } finally {
        app()->setBasePath($original);
        File::deleteDirectory($directory);
    }
}

it('generates valid PHP artifacts and preserves existing files', function (string $command, string $path) {
    withCmsCoreArtifactDirectory(function (string $directory) use ($command, $path) {
        $this->artisan($command, ['module' => 'portfolio', 'name' => 'Quote'])->assertFailed();
        $this->artisan('cms:make-module', ['name' => 'Portfolio'])->assertSuccessful();
        $this->artisan($command, ['module' => 'portfolio', 'name' => 'Quote'])->assertSuccessful();
        $file = $directory.'/modules/cms-portfolio/'.$path;
        (new Process([PHP_BINARY, '-l', $file]))->mustRun();
        $original = File::get($file);
        $this->artisan($command, ['module' => 'portfolio', 'name' => 'Quote'])->assertFailed();
        expect(File::get($file))->toBe($original);
    });
})->with([
    'field' => ['cms:make-field-type', 'src/Fields/QuoteFieldType.php'],
    'hook' => ['cms:make-hook', 'src/Hooks/QuoteFilter.php'],
]);

it('preserves an occupied hook listener when its filter is absent', function () {
    withCmsCoreArtifactDirectory(function (string $directory) {
        $this->artisan('cms:make-module', ['name' => 'Portfolio'])->assertSuccessful();
        $listener = $directory.'/modules/cms-portfolio/src/Hooks/QuoteListener.php';
        File::ensureDirectoryExists(dirname($listener));
        File::put($listener, '<?php // existing listener');
        $this->artisan('cms:make-hook', ['module' => 'portfolio', 'name' => 'Quote'])->assertFailed();
        expect(File::get($listener))->toBe('<?php // existing listener');
    });
});

it('loads module owned translations through the provider lifecycle', function () {
    $directory = sys_get_temp_dir().'/cms-core-translations-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($directory.'/en');
    File::put($directory.'/en/messages.php', '<?php return ["title" => "Module title"];');
    $provider = new class(app(), $directory) extends ModuleServiceProvider
    {
        public function __construct($app, private string $directory)
        {
            parent::__construct($app);
        }

        public function module(): ModuleInterface
        {
            return new class extends AbstractModule
            {
                public function key(): string
                {
                    return 'coverage-translations';
                }

                public function name(): string
                {
                    return 'Translations';
                }

                public function version(): string
                {
                    return '1.0.0';
                }

                public function isFoundational(): bool
                {
                    return true;
                }
            };
        }

        protected function bootModule(): void
        {
            $this->loadModuleTranslations($this->directory, 'coverage-core');
        }
    };
    try {
        $provider->register();
        $provider->boot();
        expect(trans('coverage-core::messages.title'))->toBe('Module title');
    } finally {
        File::deleteDirectory($directory);
    }
});

it('boots a declarative module without requiring custom lifecycle hooks', function () {
    $provider = new class(app()) extends ModuleServiceProvider
    {
        public function module(): ModuleInterface
        {
            return new class extends AbstractModule
            {
                public function key(): string
                {
                    return 'coverage-declarative';
                }

                public function name(): string
                {
                    return 'Declarative';
                }

                public function version(): string
                {
                    return '1.0.0';
                }

                public function isFoundational(): bool
                {
                    return true;
                }
            };
        }
    };
    $provider->register();
    $provider->boot();
    expect(app(ModuleRegistryInterface::class)->has('coverage-declarative'))->toBeTrue()
        ->and(app(ModuleManagerInterface::class)->isEnabled('coverage-declarative'))->toBeTrue();
});
