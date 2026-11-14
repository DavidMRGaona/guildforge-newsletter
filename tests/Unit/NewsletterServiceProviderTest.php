<?php

declare(strict_types=1);

namespace Modules\Newsletter\Tests\Unit;

use App\Application\Modules\DTOs\PermissionDTO;
use Modules\Newsletter\NewsletterServiceProvider;
use Tests\TestCase;

final class NewsletterServiceProviderTest extends TestCase
{
    public function test_it_names_the_module(): void
    {
        $this->assertSame('newsletter', (new NewsletterServiceProvider($this->app))->moduleName());
    }

    public function test_it_declares_the_configure_permission_for_no_default_role_and_not_admin_only(): void
    {
        $this->app['translator']->addNamespace('newsletter', dirname(__DIR__, 2).'/lang');

        $permissions = (new NewsletterServiceProvider($this->app))->registerPermissions();

        $this->assertCount(1, $permissions);
        $this->assertContainsOnlyInstancesOf(PermissionDTO::class, $permissions);
        $this->assertSame('newsletter.configure', $permissions[0]->name);
        $this->assertSame('newsletter', $permissions[0]->module);
        $this->assertSame([], $permissions[0]->roles);
        $this->assertFalse($permissions[0]->adminOnly);
        $this->assertSame('Configurar el boletín', $permissions[0]->label);
        $this->assertSame('Boletín', $permissions[0]->group);
    }

    public function test_the_manifest_matches_the_provider(): void
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/module.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('newsletter', $manifest['name']);
        $this->assertSame('Boletín', $manifest['displayName']);
        $this->assertTrue($manifest['storesUtc']);
        $this->assertSame(NewsletterServiceProvider::class, $manifest['namespace'].'\\'.$manifest['provider']);
    }
}
