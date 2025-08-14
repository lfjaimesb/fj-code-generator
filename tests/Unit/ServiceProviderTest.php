<?php

namespace FjCodeGenerator\Tests\Unit;

use FjCodeGenerator\Tests\TestCase;
use FjCodeGenerator\Commands\MakeApiResource;
use FjCodeGenerator\Commands\MakeFilamentResource;
use Illuminate\Support\Facades\Artisan;

class ServiceProviderTest extends TestCase
{
    /** @test */
    public function it_registers_commands()
    {
        $this->assertTrue($this->app->bound(MakeApiResource::class));
        $this->assertTrue($this->app->bound(MakeFilamentResource::class));
    }

    /** @test */
    public function it_merges_config()
    {
        $this->assertNotNull(config('fj-code-generator'));
        $this->assertIsArray(config('fj-code-generator.api'));
        $this->assertIsArray(config('fj-code-generator.filament'));
    }

    /** @test */
    public function commands_are_available()
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('fj:api-resource', $commands);
        $this->assertArrayHasKey('fj:filament-resource', $commands);
    }
}
