<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\SQLiteConnection;
use Liberu\Cms\Contracts\Access\AccessScope;
use Liberu\Cms\Contracts\Access\PermissionGroup;
use Liberu\Cms\Contracts\Admin\DashboardStat;
use Liberu\Cms\Contracts\Api\ApiEndpoint;
use Liberu\Cms\Contracts\Content\WorkflowState;
use Liberu\Cms\Contracts\Events\Content\ContentPublished;
use Liberu\Cms\Contracts\Events\Content\ContentStateChanged;
use Liberu\Cms\Contracts\Events\Core\ChannelCreated;
use Liberu\Cms\Contracts\Events\Core\ContentIdentityCreated;
use Liberu\Cms\Contracts\Events\Core\SettingChanged;
use Liberu\Cms\Contracts\Events\Core\SiteCreated;
use Liberu\Cms\Contracts\Events\Form\FormSubmitted;
use Liberu\Cms\Contracts\Events\Media\MediaUploaded;
use Liberu\Cms\Contracts\Events\Theme\ThemeActivated;
use Liberu\Cms\Contracts\Fields\FieldTypeDefinition;
use Liberu\Cms\Contracts\Hooks\Filters\AdminFormSchemaFilter;
use Liberu\Cms\Contracts\Hooks\Filters\ApiResourceFilter;
use Liberu\Cms\Contracts\Hooks\Filters\BlockRenderFilter;
use Liberu\Cms\Contracts\Hooks\Filters\ContentQueryFilter;
use Liberu\Cms\Contracts\Media\MediaItemInterface;
use Liberu\Cms\Contracts\Search\SearchResult;
use Liberu\Cms\Contracts\Seo\SitemapUrl;

it('qualifies only the declared permissions and exposes the editorial lifecycle', function () {
    expect((new PermissionGroup('pages', 'Pages', AccessScope::Content, ['view', 'publish']))->permissions())->toBe(['pages.view', 'pages.publish'])
        ->and((new PermissionGroup('empty', 'Empty', AccessScope::Module, []))->permissions())->toBe([])
        ->and(WorkflowState::options())->toBe(['draft' => 'Draft', 'review' => 'Review', 'published' => 'Published', 'archived' => 'Archived']);
});

it('keeps dashboard and field factories lazy until their consumer evaluates them', function () {
    $calls = 0;
    $stat = new DashboardStat('Pages', function () use (&$calls) {
        return ++$calls;
    }, 'document', 'success');
    expect($calls)->toBe(0)->and(($stat->value)())->toBe(1)->and($stat->label)->toBe('Pages');
    $field = new FieldTypeDefinition('text', 'Text', fn (string $path, array $options) => (object) ['path' => $path, 'options' => $options], fn (mixed $value) => is_string($value));
    expect(($field->component)('data.title', ['one'])->path)->toBe('data.title')
        ->and(($field->matches)('Title'))->toBeTrue()->and(($field->matches)(123))->toBeFalse();
});

it('preserves API delivery search and sitemap descriptors', function () {
    $endpoint = new ApiEndpoint('pages', stdClass::class, 'index', 'pages.index');
    $search = new SearchResult('page', '42', 'Welcome', 'welcome');
    $modified = new DateTimeImmutable('2026-01-01');
    $url = new SitemapUrl('https://example.test/welcome', $modified, 'weekly', 0.8);
    expect($endpoint->method)->toBe('GET')->and($endpoint->middleware)->toBe([])
        ->and($search->id)->toBe('42')->and($search->score)->toBe(1.0)
        ->and($url->lastModified)->toBe($modified)->and($url->priority)->toBe(0.8);
});

it('lets extension filters change their payload while preserving their routing context', function () {
    $component = new stdClass();
    $form = new AdminFormSchemaFilter([$component], 'pages');
    $form->components[] = new stdClass();
    $api = new ApiResourceFilter(['title' => 'Original'], $component);
    $api->data['title'] = 'Extended';
    $block = new BlockRenderFilter('<p>Text</p>', 'text', ['text' => 'Text']);
    $block->html = '<section>'.$block->html.'</section>';
    $query = new Builder(new QueryBuilder(new SQLiteConnection(new PDO('sqlite::memory:'))));
    $filter = new ContentQueryFilter('pages.published', $query);
    $filter->query->where('published', true);
    expect($form->name())->toBe('pages.admin.form')->and($form->components)->toHaveCount(2)
        ->and($api->name())->toBe('api.resource')->and($api->model)->toBe($component)->and($api->data['title'])->toBe('Extended')
        ->and($block->name())->toBe('blocks.render')->and($block->html)->toBe('<section><p>Text</p></section>')
        ->and($filter->name())->toBe('pages.published')->and($query->toSql())->toContain('"published" = ?');
});

it('exposes stable extension event identities and immutable payloads', function (Closure $factory, string $name, string $property, mixed $value) {
    $event = $factory();
    expect($event->name())->toBe($name)->and($event->$property)->toBe($value)
        ->and(fn () => $event->$property = $value)->toThrow(Error::class);
})->with([
    'site' => [fn () => new SiteCreated(42, 'main'), 'core.site.created', 'siteId', 42],
    'channel' => [fn () => new ChannelCreated('channel-1', 42, 'web'), 'core.channel.created', 'channelId', 'channel-1'],
    'identity' => [fn () => new ContentIdentityCreated(3, 'page', '9'), 'core.content-identity.created', 'contentId', '9'],
    'setting' => [fn () => new SettingChanged(4, null, 'branding'), 'core.setting.changed', 'siteId', null],
    'published' => [fn () => new ContentPublished('page', 9), 'content.published', 'contentId', 9],
    'state' => [fn () => new ContentStateChanged('page', 9, WorkflowState::Draft, WorkflowState::Review), 'content.state_changed', 'to', WorkflowState::Review],
    'form' => [fn () => new FormSubmitted('contact', 'submission-1', null, ['name' => 'Jane']), 'forms.submitted', 'data', ['name' => 'Jane']],
    'theme' => [fn () => new ThemeActivated('modern', 'classic'), 'theme.activated', 'previousThemeKey', 'classic'],
]);

it('retains the uploaded media contract without importing a concrete media model', function () {
    $media = Mockery::mock(MediaItemInterface::class);
    $event = new MediaUploaded($media);
    expect($event->name())->toBe('media.uploaded')->and($event->media)->toBe($media);
    Mockery::close();
});
