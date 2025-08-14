<?php

namespace FjCodeGenerator\Tests\Feature;

use FjCodeGenerator\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;

class MakeApiResourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Crear directorios necesarios
        File::makeDirectory(app_path('Models'), 0755, true, true);
        File::makeDirectory(app_path('Http/Controllers/Api'), 0755, true, true);
        File::makeDirectory(app_path('Http/Requests/Api'), 0755, true, true);
    }

    protected function tearDown(): void
    {
        // Limpiar archivos creados
        File::deleteDirectory(app_path('Models'));
        File::deleteDirectory(app_path('Http'));

        parent::tearDown();
    }

    /** @test */
    public function it_can_generate_api_resource_files()
    {
        $this->artisan('fj:api-resource', ['name' => 'TestModel'])
            ->expectsQuestion('🔤 Nombre del campo (Enter para finalizar)', 'name')
            ->expectsQuestion('🎯 Tipo de campo para \'name\'', 'string')
            ->expectsQuestion('🛡️  ¿Agregar validaciones para \'name\'?', false)
            ->expectsQuestion('🔤 Nombre del campo (Enter para finalizar)', '')
            ->assertExitCode(0);

        // Verificar que los archivos fueron creados
        $this->assertTrue(File::exists(app_path('Models/TestModel.php')));
        $this->assertTrue(File::exists(app_path('Http/Controllers/Api/TestModelController.php')));
        $this->assertTrue(File::exists(app_path('Http/Requests/Api/TestModelRequest.php')));
    }

    /** @test */
    public function it_shows_warning_for_existing_files()
    {
        // Crear archivo modelo existente
        File::put(app_path('Models/TestModel.php'), '<?php // existing file');

        $this->artisan('fj:api-resource', ['name' => 'TestModel'])
            ->expectsQuestion('🔤 Nombre del campo (Enter para finalizar)', '')
            ->expectsOutput('⚠️  El modelo \'TestModel\' ya existe. Usa --force para sobrescribirlo.')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_can_force_overwrite_existing_files()
    {
        File::put(app_path('Models/TestModel.php'), '<?php // existing file');

        $this->artisan('fj:api-resource', ['name' => 'TestModel', '--force' => true])
            ->expectsQuestion('🔤 Nombre del campo (Enter para finalizar)', '')
            ->assertExitCode(0);

        $content = File::get(app_path('Models/TestModel.php'));
        $this->assertStringContainsString('class TestModel extends Model', $content);
    }
}
