<?php

namespace FjCodeGenerator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MakeApiResource extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fj:api-resource {name} {--force : Sobrescribir archivos existentes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '🔥 Crea un recurso de API completo (modelo, controlador, request, rutas) - ¡Ya te la sabes!';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $name = $this->argument('name');
        $force = $this->option('force');

        $this->info("🚀 ¡Órale carnal! Generando recurso API para '{$name}'...");

        $tableName = Str::plural(Str::snake($name));

        if (Schema::hasTable($tableName)) {
            $this->info("✅ La tabla '$tableName' existe. Obteniendo campos automáticamente...");
            $fields = $this->__getTableColumns($tableName);
        } else {
            $this->warn("⚠️  La tabla '$tableName' no existe. Definiendo campos manualmente...");
            $fields = $this->__getParams($name);
        }

        // Crear los archivos
        $this->__request($name, $fields, $force);
        $this->__model($name, $fields, $force);
        $this->__controller($name, $force);
        $this->__route($name);

        $this->info("🎉 ¡A huevo! Recurso de API '$name' creado exitosamente, carnal!");
    }

    /**
     * Obtener columnas de la tabla automáticamente
     */
    private function __getTableColumns($tableName): array
    {
        $fields = [];
        $columns = DB::select("SHOW COLUMNS FROM $tableName");
        $excludedColumns = config('fj-code-generator.exclusions.columns', ['id', 'created_at', 'updated_at', 'deleted_at']);

        foreach ($columns as $column) {
            if (in_array($column->Field, $excludedColumns)) {
                continue;
            }

            $fields[$column->Field] = [
                'type' => Schema::getColumnType($tableName, $column->Field),
                'rules' => [
                    'nullable' => $column->Null === 'YES',
                    'default' => $column->Default,
                    'key' => $column->Key,
                ],
            ];
        }

        return $fields;
    }

    /**
     * Obtener campos manualmente si no existe la tabla
     */
    private function __getParams(string $name): array
    {
        $fields = [];

        $this->info("💬 Vamos a definir los campos para el modelo '$name':");

        while (true) {
            $fieldName = $this->ask("🔤 Nombre del campo (Enter para finalizar)");

            if (empty($fieldName)) {
                break;
            }

            $fieldType = $this->choice("🎯 Tipo de campo para '$fieldName'", [
                'string',
                'text',
                'integer',
                'float',
                'boolean',
                'date',
                'datetime',
                'email',
                'password',
                'file'
            ], 0);

            $additionalRules = [];

            if ($fieldType === 'password') {
                $additionalRules[] = 'min:8';
                $fields[$fieldName] = [
                    'type' => $fieldType,
                    'rules' => $additionalRules,
                ];

                // Campo de confirmación
                $confirmFieldName = 'confirm_' . $fieldName;
                $fields[$confirmFieldName] = [
                    'type' => 'password',
                    'rules' => ['required', 'same:' . $fieldName],
                ];
            } else {
                // Preguntar por validaciones adicionales
                if ($this->confirm("🛡️  ¿Agregar validaciones para '$fieldName'?")) {
                    if ($this->confirm("🔒 ¿Campo único?")) {
                        $additionalRules[] = 'unique:' . Str::plural(strtolower($name));
                    }
                    if ($this->confirm("❓ ¿Puede ser nulo?")) {
                        $additionalRules[] = 'nullable';
                    }
                    if (in_array($fieldType, ['string', 'text'])) {
                        $minLength = $this->ask("📏 Longitud mínima (opcional)");
                        if (!empty($minLength)) {
                            $additionalRules[] = "min:$minLength";
                        }
                        $maxLength = $this->ask("📏 Longitud máxima (opcional)");
                        if (!empty($maxLength)) {
                            $additionalRules[] = "max:$maxLength";
                        }
                    }
                }

                $fields[$fieldName] = [
                    'type' => $fieldType,
                    'rules' => $additionalRules,
                ];
            }
        }

        return $fields;
    }

    /**
     * Crear el modelo
     */
    private function __model(string $name, array $fields, bool $force): void
    {
        $modelPath = app_path("Models/{$name}.php");

        if (File::exists($modelPath) && !$force) {
            $this->warn("⚠️  El modelo '{$name}' ya existe. Usa --force para sobrescribirlo.");
            return;
        }

        $modelStub = $this->__getStub('api/model');
        $modelStub = str_replace('{{ class }}', $name, $modelStub);

        // Campos fillable
        $fillableFields = array_map(function ($field) {
            return "'$field'";
        }, array_keys($fields));

        $fillableFields = implode(', ', $fillableFields);
        $modelStub = str_replace('{{ fillable }}', $fillableFields, $modelStub);

        // Relaciones
        $tableName = Str::plural(Str::snake($name));
        $foreignKeys = $this->__getForeignKeys($tableName);

        $imports = [];
        $relationsContent = "";

        if (count($foreignKeys) > 0) {
            $imports[] = 'Illuminate\Database\Eloquent\Relations\BelongsTo';

            foreach ($foreignKeys as $foreignKey) {
                $relatedModel = Str::studly(Str::singular($foreignKey['related_table']));
                $relationName = Str::camel(Str::singular($foreignKey['related_table']));

                $relationStub = $this->__getStub('api/relation');
                $relationStub = str_replace('{{ relationName }}', $relationName, $relationStub);
                $relationStub = str_replace('{{ relationType }}', 'BelongsTo', $relationStub);
                $relationStub = str_replace('{{ relationMethod }}', 'belongsTo', $relationStub);
                $relationStub = str_replace('{{ relatedModel }}', $relatedModel, $relationStub);
                $relationStub = str_replace('{{ foreignKey }}', $foreignKey['local_column'], $relationStub);
                $relationStub = str_replace('{{ ownerKey }}', $foreignKey['related_column'], $relationStub);

                $relationsContent .= "\n" . $relationStub;

                $this->__inverseRelation($name, $relatedModel, $foreignKey['local_column']);
            }
        }

        $importsContent = '';
        foreach ($imports as $import) {
            $importsContent .= "use $import;\n";
        }

        $modelStub = str_replace('{{ relations }}', $relationsContent, $modelStub);
        $modelStub = str_replace('{{ imports }}', $importsContent, $modelStub);

        File::put($modelPath, $modelStub);
        $this->info("✅ Modelo '{$name}' creado exitosamente.");
    }

    /**
     * Crear el controlador
     */
    private function __controller(string $name, bool $force): void
    {
        $controllerPath = app_path("Http/Controllers/Api/{$name}Controller.php");

        if (File::exists($controllerPath) && !$force) {
            $this->warn("⚠️  El controlador '{$name}Controller' ya existe. Usa --force para sobrescribirlo.");
            return;
        }

        // Crear directorio si no existe
        $controllerDir = dirname($controllerPath);
        if (!File::exists($controllerDir)) {
            File::makeDirectory($controllerDir, 0755, true);
        }

        $controllerStub = $this->__getStub('api/controller');
        $controllerStub = str_replace('{{ class }}', "{$name}Controller", $controllerStub);
        $controllerStub = str_replace('{{ model }}', $name, $controllerStub);
        $controllerStub = str_replace('{{ modelLowerCase }}', strtolower($name), $controllerStub);

        File::put($controllerPath, $controllerStub);
        $this->info("✅ Controlador '{$name}Controller' creado exitosamente.");
    }

    /**
     * Crear el request
     */
    private function __request(string $name, array $fields, bool $force): void
    {
        $requestPath = app_path("Http/Requests/Api/{$name}Request.php");

        if (File::exists($requestPath) && !$force) {
            $this->warn("⚠️  La request '{$name}Request' ya existe. Usa --force para sobrescribirla.");
            return;
        }

        // Crear directorio si no existe
        $requestDir = dirname($requestPath);
        if (!File::exists($requestDir)) {
            File::makeDirectory($requestDir, 0755, true);
        }

        $requestStub = $this->__getStub('api/request');
        $requestStub = str_replace('{{ class }}', "{$name}Request", $requestStub);

        // Generar validaciones
        $validationRules = [];
        foreach ($fields as $fieldName => $fieldData) {
            $rules = [];
            $fieldType = $fieldData['type'];
            $additionalRules = $fieldData['rules'] ?? [];

            // Determinar si es requerido
            $nullable = $additionalRules['nullable'] ?? false;
            $hasDefault = $additionalRules['default'] ?? false;

            if (!$nullable && !$hasDefault) {
                $rules[] = 'required';
            }

            // Reglas específicas por tipo o nombre
            if (Str::endsWith($fieldName, '_id')) {
                $rules[] = 'integer';
                $rules[] = 'exists:' . Str::plural(Str::beforeLast($fieldName, '_id')) . ',id';
            } elseif (Str::contains($fieldName, 'email')) {
                $rules[] = 'email';
            } elseif (Str::contains($fieldName, 'password')) {
                $rules[] = 'confirmed';
                $rules[] = 'min:8';
            } elseif (Str::contains($fieldName, 'file')) {
                $rules[] = 'file';
            } else {
                // Reglas por tipo de datos
                switch ($fieldType) {
                    case in_array($fieldType, ['integer', 'bigint', 'tinyint', 'mediumint'], true):
                        $rules[] = 'integer';
                        break;
                    case in_array($fieldType, ['float', 'double', 'decimal'], true):
                        $rules[] = 'numeric';
                        break;
                    case 'boolean':
                        $rules[] = 'boolean';
                        break;
                    case 'date':
                        $rules[] = 'date';
                        break;
                    case in_array($fieldType, ['datetime', 'timestamp'], true):
                        $rules[] = 'date_format:Y-m-d H:i:s';
                        break;
                    default:
                        $rules[] = 'string';
                        break;
                }
            }

            // Regla de unique si aplica
            if (($additionalRules['key'] ?? '') === 'UNI') {
                $rules[] = 'unique:' . Str::plural(Str::snake($name));
            }

            $validationRules[] = "'$fieldName' => '" . implode('|', $rules) . "'";
        }

        $validationRules = implode(",\n            ", $validationRules);
        $requestStub = str_replace('{{ rules }}', $validationRules, $requestStub);

        File::put($requestPath, $requestStub);
        $this->info("✅ Request '{$name}Request' creada exitosamente.");
    }

    /**
     * Agregar ruta a api.php
     */
    private function __route(string $name): void
    {
        $routeName = Str::plural(strtolower($name));
        $routeContent = "Route::apiResource('{$routeName}', {$name}Controller::class);\n";
        $routesPath = base_path('routes/api.php');

        if (!File::exists($routesPath)) {
            $this->warn("⚠️  Archivo routes/api.php no encontrado.");
            return;
        }

        if (str_contains(File::get($routesPath), $routeContent)) {
            $this->warn("⚠️  La ruta para '{$routeName}' ya existe.");
            return;
        }

        $import = "App\\Http\\Controllers\\Api\\{$name}Controller";
        $contentRoute = File::get($routesPath);

        if (strpos($contentRoute, $import) === false) {
            $contentRoute = str_replace(
                'use Illuminate\Support\Facades\Route;',
                "use Illuminate\Support\Facades\Route;\nuse {$import};",
                $contentRoute
            );
            File::put($routesPath, $contentRoute);
        }

        File::append($routesPath, $routeContent);
        $this->info("✅ Ruta '/api/{$routeName}' agregada exitosamente.");
    }

    /**
     * Obtener foreign keys de una tabla
     */
    private function __getForeignKeys($tableName): array
    {
        if (!Schema::hasTable($tableName)) {
            return [];
        }

        $foreignKeys = [];
        $relations = Schema::getForeignKeys($tableName);

        foreach ($relations as $foreignKey) {
            $foreignKeys[] = [
                'local_column' => $foreignKey['columns'][0],
                'related_table' => $foreignKey['foreign_table'],
                'related_column' => $foreignKey['foreign_columns'][0],
            ];
        }

        return $foreignKeys;
    }

    /**
     * Agregar relación inversa HasMany
     */
    private function __inverseRelation(string $modelName, string $relatedModelName, string $foreignKey): void
    {
        $modelPath = app_path("Models/{$relatedModelName}.php");

        if (!File::exists($modelPath)) {
            $this->warn("⚠️  El modelo '{$relatedModelName}' no existe para la relación inversa.");
            return;
        }

        $modelContent = File::get($modelPath);
        $relationName = Str::plural(Str::camel($modelName));

        if (strpos($modelContent, "public function {$relationName}()") !== false) {
            return; // Ya existe la relación
        }

        $relationStub = $this->__getStub('api/relation');
        $relationStub = str_replace('{{ relationName }}', $relationName, $relationStub);
        $relationStub = str_replace('{{ relationType }}', 'HasMany', $relationStub);
        $relationStub = str_replace('{{ relationMethod }}', 'hasMany', $relationStub);
        $relationStub = str_replace('{{ relatedModel }}', $modelName, $relationStub);
        $relationStub = str_replace('{{ foreignKey }}', $foreignKey, $relationStub);
        $relationStub = str_replace('{{ ownerKey }}', 'id', $relationStub);

        // Agregar import HasMany
        $import = 'Illuminate\Database\Eloquent\Relations\HasMany';
        if (strpos($modelContent, $import) === false) {
            $modelContent = str_replace(
                'use Illuminate\Database\Eloquent\Model;',
                "use Illuminate\Database\Eloquent\Model;\nuse {$import};",
                $modelContent
            );
        }

        // Insertar relación antes del último }
        $lastClosingBracePos = strrpos($modelContent, '}');
        if ($lastClosingBracePos !== false) {
            $modelContent = substr_replace(
                $modelContent,
                "\n{$relationStub}\n",
                $lastClosingBracePos,
                0
            );

            File::put($modelPath, $modelContent);
            $this->info("✅ Relación '{$relationName}' agregada al modelo '{$relatedModelName}'.");
        }
    }

    /**
     * Obtener stub desde el paquete
     */
    private function __getStub(string $stubName): string
    {
        $stubPath = __DIR__ . "/../Stubs/{$stubName}.stub";

        // Si no existe el stub del paquete, usar uno por defecto
        if (!File::exists($stubPath)) {
            return $this->__getDefaultStub($stubName);
        }

        return File::get($stubPath);
    }

    /**
     * Obtener stubs por defecto si no existen los del paquete
     */
    private function __getDefaultStub(string $stubName): string
    {
        switch ($stubName) {
            case 'api/model':
                return '<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
{{ imports }}

class {{ class }} extends Model
{
    use HasFactory;

    protected $fillable = [
        {{ fillable }}
    ];

    protected $casts = [
        // Agregar casts aquí
    ];
    {{ relations }}
}
';

            case 'api/controller':
                return '<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\{{ class }}Request;
use App\Models\{{ model }};
use Illuminate\Http\JsonResponse;

class {{ class }} extends Controller
{
    public function index(): JsonResponse
    {
        ${{ modelLowerCase }}s = {{ model }}::with([])->paginate(15);
        
        return response()->json(${{ modelLowerCase }}s);
    }

    public function store({{ model }}Request $request): JsonResponse
    {
        ${{ modelLowerCase }} = {{ model }}::create($request->validated());
        
        return response()->json([
            "message" => "{{ model }} creado exitosamente",
            "data" => ${{ modelLowerCase }}
        ], 201);
    }

    public function show({{ model }} ${{ modelLowerCase }}): JsonResponse
    {
        return response()->json(${{ modelLowerCase }}->load([]));
    }

    public function update({{ model }}Request $request, {{ model }} ${{ modelLowerCase }}): JsonResponse
    {
        ${{ modelLowerCase }}->update($request->validated());
        
        return response()->json([
            "message" => "{{ model }} actualizado exitosamente",
            "data" => ${{ modelLowerCase }}
        ]);
    }

    public function destroy({{ model }} ${{ modelLowerCase }}): JsonResponse
    {
        ${{ modelLowerCase }}->delete();
        
        return response()->json([
            "message" => "{{ model }} eliminado exitosamente"
        ]);
    }
}
';

            case 'api/request':
                return '<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class {{ class }} extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            {{ rules }}
        ];
    }

    public function messages(): array
    {
        return [
            // Mensajes personalizados aquí
        ];
    }
}
';

            case 'api/relation':
                return '
    /**
     * Relación {{ relationName }}
     */
    public function {{ relationName }}(): {{ relationType }}
    {
        return $this->{{ relationMethod }}({{ relatedModel }}::class, "{{ foreignKey }}", "{{ ownerKey }}");
    }';

            default:
                return '';
        }
    }
}
