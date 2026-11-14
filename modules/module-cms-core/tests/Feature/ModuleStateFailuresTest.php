<?php

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Liberu\Cms\Core\Module\DatabaseModuleStateRepository;

it('keeps bootstrap defaults safe before the module state table exists', function () {
    Schema::drop('cms_modules');
    $repository = new DatabaseModuleStateRepository(DB::getFacadeRoot());
    expect($repository->isEnabled('fixture', false))->toBeFalse();
    $repository->setEnabled('fixture', true);
    $repository->forget('fixture');
    expect($repository->isEnabled('fixture', false))->toBeFalse();
});

it('falls back when a connection is unavailable or not a database connection', function (bool $throws) {
    $resolver = Mockery::mock(ConnectionResolverInterface::class);
    $expectation = $resolver->shouldReceive('connection');
    $throws ? $expectation->andThrow(new RuntimeException('Connection unavailable')) : $expectation->andReturn(new stdClass());
    $repository = new DatabaseModuleStateRepository($resolver);
    expect($repository->isEnabled('fixture', false))->toBeFalse();
    $repository->setEnabled('fixture', true);
    $repository->forget('fixture');
    expect($repository->isEnabled('fixture', true))->toBeTrue();
})->with(['failed connection' => [true], 'invalid connection' => [false]]);

it('normalizes driver booleans and memoizes a successful state read', function (bool $value) {
    $resolver = Mockery::mock(ConnectionResolverInterface::class);
    $connection = Mockery::mock(Connection::class);
    $schema = Mockery::mock(SchemaBuilder::class);
    $query = Mockery::mock(Builder::class);
    $resolver->shouldReceive('connection')->andReturn($connection);
    $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($schema);
    $schema->shouldReceive('hasTable')->with('cms_modules')->once()->andReturnTrue();
    $connection->shouldReceive('table')->with('cms_modules')->once()->andReturn($query);
    $query->shouldReceive('where')->with('key', 'fixture')->once()->andReturnSelf();
    $query->shouldReceive('value')->with('enabled')->once()->andReturn($value);
    $repository = new DatabaseModuleStateRepository($resolver);
    expect($repository->isEnabled('fixture', ! $value))->toBe($value)
        ->and($repository->isEnabled('fixture', ! $value))->toBe($value);
})->with([true, false]);

it('uses the requested default if a state query fails after schema discovery', function () {
    $resolver = Mockery::mock(ConnectionResolverInterface::class);
    $connection = Mockery::mock(Connection::class);
    $schema = Mockery::mock(SchemaBuilder::class);
    $query = Mockery::mock(Builder::class);
    $resolver->shouldReceive('connection')->andReturn($connection);
    $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($schema);
    $schema->shouldReceive('hasTable')->with('cms_modules')->once()->andReturnTrue();
    $connection->shouldReceive('table')->with('cms_modules')->once()->andReturn($query);
    $query->shouldReceive('where')->with('key', 'fixture')->once()->andReturnSelf();
    $query->shouldReceive('value')->with('enabled')->once()->andThrow(new RuntimeException('Query unavailable'));
    expect((new DatabaseModuleStateRepository($resolver))->isEnabled('fixture', false))->toBeFalse();
});
