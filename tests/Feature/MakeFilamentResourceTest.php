<?php

namespace FjCodeGenerator\Tests\Feature;

use FjCodeGenerator\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class MakeFilamentResourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Crear directorios necesarios
        File::makeDirectory(app_path('Models'), 0755, true, true);
        File::makeDirectory(app_path('Filament/Resources'), 0755, true, true);

        // Crear tabla de prueba
        Schema::create('test_models', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // Crear modelo de prueba
        File::put(app_path('Models/TestModel.php'), '<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TestModel extends Model
{
    protected $fillable = [\'name\', \'email\', \'active\'];
}
');
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('test_models');
        File::deleteDirectory(app_path('Models'));
        File::deleteDirectory(app_path('Filament'));

        parent::tearDown();
    }

    /** @test */
    public function it_can_generate_filament_resource()
    {
        $this->artisan('fj:filament-resource', ['name' => 'TestModel'])
            ->assertExitCode(0);

        $this->assertTrue(File::exists(app_path('Filament/Resources/TestModelResource.php')));
        $this->assertTrue(File::exists(app_path('Filament/Resources/TestModelResource/Pages')));

        $content = File::get(app_path('Filament/Resources/TestModelResource.php'));
        $this->assertStringContainsString('class TestModelResource extends Resource', $content);
        $this->assertStringContainsString('TextInput::make(\'name\')', $content);
        $this->assertStringContainsString('TextInput::make(\'email\')', $content);
        $this->assertStringContainsString('Toggle::make(\'active\')', $content);
    }

    /** @test */
    public function it_requires_existing_model()
    {
        File::delete(app_path('Models/TestModel.php'));

        $this->artisan('fj:filament-resource', ['name' => 'NonExistentModel'])
            ->expectsOutput('¡Órale carnal! El modelo \'NonExistentModel\' no existe. Créalo primero con: php artisan fj:api-resource NonExistentModel')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_can_force_overwrite_filament_resources()
    {
        // Crear resource existente
        File::makeDirectory(app_path('Filament/Resources'), 0755, true, true);
        File::put(app_path('Filament/Resources/TestModelResource.php'), '<?php // existing resource');

        $this->artisan('fj:filament-resource', ['name' => 'TestModel', '--force' => true])
            ->assertExitCode(0);

        $content = File::get(app_path('Filament/Resources/TestModelResource.php'));
        $this->assertStringContainsString('class TestModelResource extends Resource', $content);
    }
}
