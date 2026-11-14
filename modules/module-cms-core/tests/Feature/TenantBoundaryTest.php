<?php

use Liberu\Cms\Contracts\Tenancy\TenantContextInterface;
use Liberu\Cms\Contracts\Tenancy\TenantModelResolverInterface;
use Liberu\Cms\Core\Models\Site;
use Liberu\Cms\Core\Tests\Tenant;

it('assigns request tenancy and resolves relationships through the hosts tenant model contract', function () {
    $tenant = Tenant::create([]);
    $other = Tenant::create([]);
    Site::create(['key' => 'other', 'name' => 'Other', 'team_id' => $other->id]);
    $context = app(TenantContextInterface::class);
    app()->instance(TenantModelResolverInterface::class, new class($context) implements TenantModelResolverInterface
    {
        public function __construct(private TenantContextInterface $context) {}

        public function tenantModel(): ?string
        {
            return Tenant::class;
        }

        public function currentTenantId(): int|string|null
        {
            return $this->context->tenantId();
        }
    });
    $context->setTenantId($tenant->id);
    $site = Site::create(['key' => 'mine', 'name' => 'Mine']);
    expect($site->team_id)->toBe($tenant->id)->and($site->team->is($tenant))->toBeTrue()
        ->and(Site::count())->toBe(1)->and(Site::withoutGlobalScopes()->count())->toBe(2);
});
