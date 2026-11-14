<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

it('generates module artifacts without replacing existing files', function (string $command, string $path, string $marker) {
    withTemporaryModuleBase(function (string $base) use ($command, $path, $marker) {
        $this->artisan($command, ['module' => 'portfolio', 'name' => 'Quote'])->assertFailed();
        $this->artisan('cms:make-module', ['name' => 'Portfolio'])->assertSuccessful();
        $this->artisan($command, ['module' => 'portfolio', 'name' => 'Quote'])->assertSuccessful();
        $file = $base.'/modules/cms-portfolio/'.$path;
        $source = File::get($file);
        (new Process([PHP_BINARY, '-l', $file]))->mustRun();
        expect($source)->toContain($marker)->not->toContain('{{');
        $this->artisan($command, ['module' => 'portfolio', 'name' => 'Quote'])->assertFailed();
        expect(File::get($file))->toBe($source);
    });
})->with([
    'block' => ['cms:make-block', 'src/Blocks/QuoteBlock.php', 'class QuoteBlock'],
    'field' => ['cms:make-field-type', 'src/Fields/QuoteFieldType.php', 'class QuoteFieldType'],
    'hook' => ['cms:make-hook', 'src/Hooks/QuoteFilter.php', 'class QuoteFilter'],
]);

it('preserves an existing hook listener when only its filter is absent', function () {
    withTemporaryModuleBase(function (string $base) {
        $this->artisan('cms:make-module', ['name' => 'Portfolio'])->assertSuccessful();
        $listener = $base.'/modules/cms-portfolio/src/Hooks/QuoteListener.php';
        File::ensureDirectoryExists(dirname($listener));
        File::put($listener, '<?php // existing listener');
        $this->artisan('cms:make-hook', ['module' => 'portfolio', 'name' => 'Quote'])->assertFailed();
        expect(File::get($listener))->toBe('<?php // existing listener');
    });
});
