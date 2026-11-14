<?php

use Illuminate\Http\Request;
use Liberu\Cms\Core\Support\ApiPagination;
use Liberu\Cms\Core\Support\SearchScoring;

it('paginates array results and preserves the requested query in navigation links', function () {
    app()->instance('request', Request::create('https://example.test/pages?per_page=2&page=2&search=guide'));
    $page = ApiPagination::fromArray(['a', 'b', 'c', 'd', 'e']);
    expect($page->items())->toBe(['c', 'd'])->and($page->total())->toBe(5)
        ->and($page->currentPage())->toBe(2)->and($page->nextPageUrl())->toContain('search=guide', 'page=3');
});

it('clamps requested sizes and safely falls back for invalid pagination configuration', function (array $config, string $query, int $expected) {
    config($config);
    app()->instance('request', Request::create('https://example.test/pages'.$query));
    expect(ApiPagination::fromArray(range(1, 20))->perPage())->toBe($expected);
})->with([
    'default' => [['cms-api.pagination.default' => 3], '', 3],
    'cap' => [['cms-api.pagination.max' => 4], '?per_page=100', 4],
    'lower bound' => [[], '?per_page=0', 1],
    'invalid config' => [['cms-api.pagination.default' => [], 'cms-api.pagination.max' => []], '', 15],
]);

it('ranks title matches above body matches and bounds each source contribution', function () {
    expect(SearchScoring::score('Welcome Guide', 'guide'))->toBe(2.0)
        ->and(SearchScoring::score('Welcome', 'guide'))->toBe(1.0);
    config(['cms-search.per_source_limit' => 0]);
    expect(SearchScoring::perSourceLimit())->toBe(1);
    config(['cms-search.per_source_limit' => 'invalid']);
    expect(SearchScoring::perSourceLimit())->toBe(50);
});
