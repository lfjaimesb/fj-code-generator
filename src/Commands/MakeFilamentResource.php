<?php

namespace FjCodeGenerator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MakeFilamentResource extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fj:filament-resource {name} {--force : Sobrescribir archivos existentes} {--simple : Crear resource simple sin páginas adicionales}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '🎯 Crea un Resource de Filament inteligente usando el comando nativo + customización automática';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $name = $this->argument('name');
        $force = $this->option('force');
        $simple = $this->option('simple');

        $tableName = Str::plural(Str::snake($name));

        // Verificar que el modelo existe
        $modelPath = app_path("Models/{$name}.php");
        if (!File::exists($modelPath)) {
            $this->error("¡Órale carnal! El modelo '{$name}' no existe. Créalo primero con: php artisan fj:api-resource {$name}");
            return;
        }

        if (!Schema::hasTable($tableName)) {
            $this->error("¡Chales! La tabla '{$tableName}' no existe en la base de datos.");
            return;
        }

        $this->info("🚀 ¡A huevo! Usando Filament nativo + magia FJ para '{$name}'...");

        // Paso 1: Ejecutar comando nativo de Filament
        $this->__executeFilamentCommand($name, $force, $simple);

        // Paso 2: Obtener información de la tabla para customización
        $fields = $this->__getTableColumns($tableName);
        $relations = $this->__getRelations($name, $tableName);

        // Paso 3: Customizar el Resource generado
        $this->__customizeResource($name, $fields, $relations, $force);

        // Paso 4: Crear RelationManagers si existen relaciones hasMany
        if (!empty($relations['has_many'])) {
            $this->__createRelationManagers($name, $relations, $force);
        }

        $this->info("🔥 ¡Ya está, carnal! Resource de Filament '{$name}' creado y customizado exitosamente.");
        $this->info("💡 Las páginas fueron creadas por Filament y el Resource fue optimizado por FJ.");
    }

    /**
     * Ejecutar el comando nativo de Filament
     */
    private function __executeFilamentCommand($name, $force, $simple): void
    {
        $this->info("🎯 Ejecutando comando nativo de Filament...");

        $options = [];
        if ($force) {
            // No hay --force en make:filament-resource, pero podemos validar después
        }
        if ($simple) {
            $options['--simple'] = true;
        }

        // Ejecutar comando de Filament
        $command = $simple ? 'make:filament-resource' : 'make:filament-resource';

        try {
            $this->call($command, [
                'name' => $name,
                '--generate' => true, // Generar automáticamente desde el modelo
                '--view' => true,     // Incluir página de vista
            ] + $options);

            $this->info("✅ Comando nativo de Filament ejecutado exitosamente.");
        } catch (\Exception $e) {
            $this->warn("⚠️  Hubo un problema con el comando nativo: " . $e->getMessage());
            $this->info("🔄 Continuando con generación manual...");
        }
    }

    /**
     * Customizar el Resource generado por Filament
     */
    private function __customizeResource($name, $fields, $relations, $force): void
    {
        $resourcePath = app_path("Filament/Resources/{$name}Resource.php");

        if (!File::exists($resourcePath)) {
            $this->warn("❌ Resource no fue creado por Filament. Creando manualmente...");
            $this->__createResourceManually($name, $fields, $relations);
            return;
        }

        $this->info("🎨 Customizando Resource generado por Filament...");

        $resourceContent = File::get($resourcePath);

        // Customizar formulario
        $resourceContent = $this->__customizeForm($resourceContent, $fields, $relations['belongs_to']);

        // Customizar tabla
        $resourceContent = $this->__customizeTable($resourceContent, $fields, $relations['belongs_to']);

        // Agregar RelationManagers si existen
        $resourceContent = $this->__addRelationManagers($resourceContent, $relations['has_many']);

        // Agregar imports necesarios
        $resourceContent = $this->__addRequiredImports($resourceContent);

        File::put($resourcePath, $resourceContent);
        $this->info("✅ Resource customizado exitosamente.");
    }

    /**
     * Customizar el formulario del Resource
     */
    private function __customizeForm($content, $fields, $belongsToRelations): string
    {
        $formFields = $this->__generateFormFields($fields, $belongsToRelations);

        // Buscar el método form() y reemplazar su contenido
        $pattern = '/public static function form\(Form \$form\): Form\s*\{.*?return \$form.*?;\s*\}/s';

        $replacement = "public static function form(Form \$form): Form
    {
        return \$form
            ->schema([
                Forms\\Components\\Section::make('Información Principal')
                    ->description('Completa los datos del registro')
                    ->schema([
                        {$formFields}
                    ])
                    ->columns(2)
                    ->collapsible(),
            ]);
    }";

        return preg_replace($pattern, $replacement, $content);
    }

    /**
     * Agregar RelationManagers al Resource
     */
    private function __addRelationManagers($content, $hasMany): string
    {
        if (empty($hasMany)) {
            return $content;
        }

        $relationManagers = $this->__generateRelationManagersArray($hasMany);

        // Buscar el método getRelations() y reemplazar
        $pattern = '/public static function getRelations\(\): array\s*\{\s*return \[\s*.*?\];\s*\}/s';

        $replacement = "public static function getRelations(): array
    {
        return [
            {$relationManagers}
        ];
    }";

        return preg_replace($pattern, $replacement, $content);
    }

    /**
     * Agregar imports necesarios
     */
    private function __addRequiredImports($content): string
    {
        $imports = [
            'use Filament\Tables\Columns\TextColumn;',
            'use Illuminate\Support\Facades\Hash;'
        ];

        $existingImports = [];
        foreach ($imports as $import) {
            if (!str_contains($content, $import)) {
                $existingImports[] = $import;
            }
        }

        if (!empty($existingImports)) {
            // Agregar después del primer use statement
            $content = preg_replace(
                '/^(use [^;]+;)/m',
                "$1\n" . implode("\n", $existingImports),
                $content,
                1
            );
        }

        return $content;
    }

    /**
     * Crear Resource manualmente si Filament falló
     */
    private function __createResourceManually($name, $fields, $relations): void
    {
        $resourcePath = app_path("Filament/Resources/{$name}Resource.php");

        // Crear directorio si no existe
        $resourceDir = dirname($resourcePath);
        if (!File::exists($resourceDir)) {
            File::makeDirectory($resourceDir, 0755, true);
        }

        $resourceStub = $this->__getResourceStub();

        // Reemplazos básicos
        $resourceStub = str_replace('{{ class }}', "{$name}Resource", $resourceStub);
        $resourceStub = str_replace('{{ model }}', $name, $resourceStub);
        $resourceStub = str_replace('{{ modelLowerCase }}', strtolower($name), $resourceStub);
        $resourceStub = str_replace('{{ modelPlural }}', Str::plural(strtolower($name)), $resourceStub);
        $resourceStub = str_replace('{{ modelTitle }}', Str::title(str_replace('_', ' ', $name)), $resourceStub);

        // Generar contenido
        $formFields = $this->__generateFormFields($fields, $relations['belongs_to']);
        $tableColumns = $this->__generateTableColumns($fields, $relations['belongs_to']);
        $relationManagers = $this->__generateRelationManagersArray($relations['has_many']);

        $resourceStub = str_replace('{{ formFields }}', $formFields, $resourceStub);
        $resourceStub = str_replace('{{ tableColumns }}', $tableColumns, $resourceStub);
        $resourceStub = str_replace('{{ relationManagers }}', $relationManagers, $resourceStub);

        File::put($resourcePath, $resourceStub);
        $this->info("✅ Resource creado manualmente exitosamente.");

        // Crear páginas manualmente también
        $this->__createPagesManually($name);
    }

    /**
     * Crear páginas manualmente
     */
    private function __createPagesManually($name): void
    {
        $pagesDir = app_path("Filament/Resources/{$name}Resource/Pages");
        if (!File::exists($pagesDir)) {
            File::makeDirectory($pagesDir, 0755, true);
        }

        $pages = [
            'List' => 'ListPage',
            'Create' => 'CreatePage',
            'Edit' => 'EditPage',
            'View' => 'ViewPage'
        ];

        foreach ($pages as $pageType => $stubType) {
            $this->__createPage($name, $pageType, $stubType, true);
        }
    }

    /**
     * Obtener columnas de la tabla con sus tipos y propiedades
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

            $type = Schema::getColumnType($tableName, $column->Field);
            $rawType = $column->Type; // Tipo MySQL crudo (ej: tinyint(1), varchar(255))
            $isNullable = $column->Null === 'YES';
            $hasDefault = $column->Default !== null;
            $isUnique = $column->Key === 'UNI';
            $isForeignKey = Str::endsWith($column->Field, '_id');

            // Detectar tinyint(1) como boolean
            $isBooleanTinyint = $this->__isBooleanTinyint($rawType, $column->Field);
            if ($isBooleanTinyint) {
                $type = 'boolean';
            }

            $fields[$column->Field] = [
                'type' => $type,
                'raw_type' => $rawType,
                'nullable' => $isNullable,
                'default' => $column->Default,
                'unique' => $isUnique,
                'foreign_key' => $isForeignKey,
                'required' => !$isNullable && !$hasDefault,
                'is_boolean_tinyint' => $isBooleanTinyint
            ];
        }

        return $fields;
    }

    /**
     * Detectar si un campo tinyint(1) debe ser tratado como boolean
     */
    private function __isBooleanTinyint($rawType, $fieldName): bool
    {
        // Verificar si es tinyint(1)
        if (preg_match('/^tinyint\(1\)/', $rawType)) {
            return true;
        }

        // Verificar nombres comunes de campos booleanos
        $booleanFieldNames = [
            'active',
            'is_active',
            'enabled',
            'is_enabled',
            'visible',
            'is_visible',
            'published',
            'is_published',
            'verified',
            'is_verified',
            'confirmed',
            'is_confirmed',
            'featured',
            'is_featured',
            'premium',
            'is_premium',
            'public',
            'is_public',
            'private',
            'is_private',
            'deleted',
            'is_deleted',
            'banned',
            'is_banned',
            'approved',
            'is_approved',
            'completed',
            'is_completed',
            'paid',
            'is_paid',
            'free',
            'is_free',
            'online',
            'is_online',
            'available',
            'is_available'
        ];

        $fieldLower = strtolower($fieldName);

        // Verificar coincidencias exactas
        if (in_array($fieldLower, $booleanFieldNames)) {
            return true;
        }

        // Verificar patrones (campos que terminen en estos sufijos)
        $booleanSuffixes = ['_flag', '_status', '_check'];
        foreach ($booleanSuffixes as $suffix) {
            if (Str::endsWith($fieldLower, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Obtener relaciones del modelo
     */
    private function __getRelations($modelName, $tableName): array
    {
        $relations = [
            'belongs_to' => [],
            'has_many' => []
        ];

        // BelongsTo relations (foreign keys en esta tabla)
        if (Schema::hasTable($tableName)) {
            $foreignKeys = Schema::getForeignKeys($tableName);
            foreach ($foreignKeys as $fk) {
                $relatedModel = Str::studly(Str::singular($fk['foreign_table']));
                $relationName = Str::camel(Str::singular($fk['foreign_table']));

                $relations['belongs_to'][] = [
                    'name' => $relationName,
                    'model' => $relatedModel,
                    'foreign_key' => $fk['columns'][0],
                    'related_table' => $fk['foreign_table']
                ];
            }
        }

        // HasMany relations (buscar tablas que referencien a esta)
        $relations['has_many'] = $this->__findHasManyRelations($modelName, $tableName);

        // Debug: Mostrar relaciones detectadas
        if (!empty($relations['has_many'])) {
            $this->info("🔍 Relaciones hasMany detectadas:");
            foreach ($relations['has_many'] as $relation) {
                $this->line("  - {$relation['name']} (tabla: {$relation['related_table']})");
            }
        }

        return $relations;
    }

    /**
     * Encontrar relaciones HasMany de manera más inteligente
     */
    private function __findHasManyRelations($modelName, $tableName): array
    {
        $hasMany = [];
        $tables = DB::select('SHOW TABLES');
        $dbName = DB::getDatabaseName();
        $tablesKey = "Tables_in_{$dbName}";

        foreach ($tables as $table) {
            $currentTable = $table->$tablesKey;

            // Saltar la tabla actual y tablas de sistema/pivote
            if ($currentTable === $tableName || $this->__isPivotTable($currentTable)) {
                continue;
            }

            // Verificar si existe la tabla antes de buscar foreign keys
            if (!Schema::hasTable($currentTable)) {
                continue;
            }

            try {
                $fks = Schema::getForeignKeys($currentTable);

                foreach ($fks as $fk) {
                    // Verificar si esta tabla es referenciada
                    if ($fk['foreign_table'] === $tableName) {

                        // Verificar que la foreign key apunte al campo correcto (usualmente 'id')
                        $expectedLocalKey = 'id';
                        if (!in_array($expectedLocalKey, $fk['foreign_columns'])) {
                            continue; // No es una relación estándar
                        }

                        // Verificar que no sea una tabla pivot (many-to-many)
                        if ($this->__isLikelyManyToManyRelation($currentTable, $fk, $tableName)) {
                            $this->info("⚠️  Saltando relación many-to-many: {$currentTable}");
                            continue;
                        }

                        $relatedModel = Str::studly(Str::singular($currentTable));

                        // Generar nombre de relación en español
                        $relationName = $this->__getSpanishRelationName($currentTable);

                        // Verificar que el modelo existe
                        $modelPath = app_path("Models/{$relatedModel}.php");
                        if (!File::exists($modelPath)) {
                            $this->warn("⚠️  Modelo {$relatedModel} no existe, saltando relación {$relationName}");
                            continue;
                        }

                        $hasMany[] = [
                            'name' => $relationName,
                            'model' => $relatedModel,
                            'foreign_key' => $fk['columns'][0],
                            'related_table' => $currentTable,
                            'english_name' => Str::plural(Str::camel(Str::singular($currentTable))) // Para backup
                        ];

                        break; // Solo una relación por tabla
                    }
                }
            } catch (\Exception $e) {
                $this->warn("⚠️  Error al analizar tabla {$currentTable}: " . $e->getMessage());
                continue;
            }
        }

        return $hasMany;
    }

    /**
     * Obtener nombre de relación en español
     */
    private function __getSpanishRelationName($tableName): string
    {
        // Diccionario de traducciones comunes
        $translations = [
            'users' => 'usuarios',
            'user' => 'usuarios',
            'posts' => 'publicaciones',
            'post' => 'publicaciones',
            'comments' => 'comentarios',
            'comment' => 'comentarios',
            'categories' => 'categorias',
            'category' => 'categorias',
            'products' => 'productos',
            'product' => 'productos',
            'orders' => 'pedidos',
            'order' => 'pedidos',
            'payments' => 'pagos',
            'payment' => 'pagos',
            'invoices' => 'facturas',
            'invoice' => 'facturas',
            'plans' => 'planes',
            'plan' => 'planes',
            'services' => 'servicios',
            'service' => 'servicios',
            'clients' => 'clientes',
            'client' => 'clientes',
            'customers' => 'clientes',
            'customer' => 'clientes',
            'employees' => 'empleados',
            'employee' => 'empleados',
            'companies' => 'empresas',
            'company' => 'empresas',
            'projects' => 'proyectos',
            'project' => 'proyectos',
            'tasks' => 'tareas',
            'task' => 'tareas',
            'files' => 'archivos',
            'file' => 'archivos',
            'images' => 'imagenes',
            'image' => 'imagenes',
            'videos' => 'videos',
            'video' => 'videos',
            'documents' => 'documentos',
            'document' => 'documentos',
            'reports' => 'reportes',
            'report' => 'reportes',
            'notifications' => 'notificaciones',
            'notification' => 'notificaciones',
            'messages' => 'mensajes',
            'message' => 'mensajes',
            'reviews' => 'resenas',
            'review' => 'resenas',
            'ratings' => 'calificaciones',
            'rating' => 'calificaciones',
            'addresses' => 'direcciones',
            'address' => 'direcciones',
            'contacts' => 'contactos',
            'contact' => 'contactos',
            'phones' => 'telefonos',
            'phone' => 'telefonos',
            'emails' => 'correos',
            'email' => 'correos',
            'roles' => 'roles',
            'role' => 'roles',
            'permissions' => 'permisos',
            'permission' => 'permisos',
            'settings' => 'configuraciones',
            'setting' => 'configuraciones',
            'logs' => 'registros',
            'log' => 'registros',
            'sessions' => 'sesiones',
            'session' => 'sesiones',
            'tokens' => 'tokens',
            'token' => 'tokens',
            'subscriptions' => 'suscripciones',
            'subscription' => 'suscripciones'
        ];

        // Buscar traducción exacta
        $tableNameLower = strtolower($tableName);
        if (isset($translations[$tableNameLower])) {
            return $translations[$tableNameLower];
        }

        // Buscar sin el plural en inglés
        $singular = Str::singular($tableNameLower);
        if (isset($translations[$singular])) {
            return $translations[$singular];
        }

        // Intentar detectar patrones y aplicar reglas básicas del español
        return $this->__applySpanishPluralRules($tableName);
    }

    /**
     * Aplicar reglas básicas de pluralización en español
     */
    private function __applySpanishPluralRules($tableName): string
    {
        $name = strtolower(Str::singular($tableName));

        // Convertir snake_case a palabras separadas
        $words = explode('_', $name);
        $spanishWords = [];

        foreach ($words as $word) {
            // Aplicar reglas básicas de español
            if (Str::endsWith($word, 'y')) {
                // company -> empresas (caso especial)
                $spanishWords[] = $word;
            } elseif (Str::endsWith($word, ['a', 'e', 'i', 'o', 'u'])) {
                // Vocal al final: add 's'
                $spanishWords[] = $word . 's';
            } elseif (Str::endsWith($word, ['l', 'r', 'n', 'd', 'j'])) {
                // Consonantes: add 'es'
                $spanishWords[] = $word . 'es';
            } else {
                // Default: add 's'
                $spanishWords[] = $word . 's';
            }
        }

        return implode('', $spanishWords); // Sin guiones para nombres de método
    }

    /**
     * Detectar si una tabla es una tabla pivot (many-to-many)
     */
    private function __isPivotTable($tableName): bool
    {
        // Patrones comunes de tablas pivot
        $pivotPatterns = [
            '/^[a-z]+_[a-z]+$/',           // user_roles, post_tags
            '/^[a-z]+_has_[a-z]+$/',       // user_has_permissions
            '/^[a-z]+_[a-z]+_pivot$/',     // user_role_pivot
            '/^pivot_[a-z]+_[a-z]+$/',     // pivot_user_roles
        ];

        foreach ($pivotPatterns as $pattern) {
            if (preg_match($pattern, $tableName)) {
                return true;
            }
        }

        // Verificar si tiene exactamente 2 foreign keys (típico de pivot)
        try {
            if (Schema::hasTable($tableName)) {
                $fks = Schema::getForeignKeys($tableName);
                $columns = DB::select("SHOW COLUMNS FROM $tableName");

                // Si tiene exactamente 2 foreign keys y pocos campos más, probablemente es pivot
                if (count($fks) >= 2) {
                    $nonForeignKeyColumns = 0;
                    foreach ($columns as $column) {
                        if (
                            !Str::endsWith($column->Field, '_id') &&
                            !in_array($column->Field, ['created_at', 'updated_at', 'id'])
                        ) {
                            $nonForeignKeyColumns++;
                        }
                    }

                    // Si tiene pocas columnas además de las foreign keys, es likely pivot
                    if ($nonForeignKeyColumns <= 2) {
                        return true;
                    }
                }
            }
        } catch (\Exception $e) {
            // Si hay error, asumir que no es pivot
            return false;
        }

        return false;
    }

    /**
     * Detectar si es probable que sea una relación many-to-many
     */
    private function __isLikelyManyToManyRelation($currentTable, $foreignKey, $mainTable): bool
    {
        try {
            // Verificar si la tabla tiene múltiples foreign keys
            $allFks = Schema::getForeignKeys($currentTable);

            if (count($allFks) >= 2) {
                // Verificar si hay otra foreign key que no sea hacia la tabla principal
                $otherFks = array_filter($allFks, function ($fk) use ($mainTable) {
                    return $fk['foreign_table'] !== $mainTable;
                });

                if (count($otherFks) > 0) {
                    $this->info("🔍 Detectada posible relación many-to-many en tabla: {$currentTable}");
                    return true;
                }
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Generar campos del formulario
     */
    private function __generateFormFields($fields, $belongsToRelations): string
    {
        $formFields = [];

        foreach ($fields as $fieldName => $fieldData) {
            $field = $this->__getFilamentFormComponent($fieldName, $fieldData, $belongsToRelations);
            if ($field) {
                $formFields[] = $field;
            }
        }

        return implode(",\n                        ", $formFields);
    }

    /**
     * Obtener el componente de Filament adecuado para cada campo
     */
    private function __getFilamentFormComponent($fieldName, $fieldData, $belongsToRelations): ?string
    {
        $type = $fieldData['type'];
        $rawType = $fieldData['raw_type'] ?? '';
        $required = $fieldData['required'] ? '->required()' : '';
        $label = $this->__getCleanFieldLabel($fieldName);

        // Si es foreign key, usar Select con relación
        if ($fieldData['foreign_key']) {
            $relation = collect($belongsToRelations)->firstWhere('foreign_key', $fieldName);
            if ($relation) {
                $relationName = $relation['name'];

                return "Forms\\Components\\Select::make('{$fieldName}')"
                    . "->label('{$label}')"
                    . "->relationship('{$relationName}', 'name')"
                    . "->searchable()"
                    . "->preload()"
                    . $required;
            }
        }

        // Detectar campos de orden y hacerlos hidden con valor automático
        if ($this->__isOrderField($fieldName)) {
            return $this->__generateOrderField($fieldName, $fieldData);
        }

        // Campos especiales por nombre
        if (Str::contains($fieldName, 'email')) {
            return "Forms\\Components\\TextInput::make('{$fieldName}')"
                . "->label('{$label}')"
                . "->email()"
                . "->maxLength(255)"
                . $required;
        }

        if (Str::contains($fieldName, 'password')) {
            return "Forms\\Components\\TextInput::make('{$fieldName}')"
                . "->label('{$label}')"
                . "->password()"
                . "->dehydrateStateUsing(fn (\$state) => Hash::make(\$state))"
                . "->dehydrated(fn (\$state) => filled(\$state))"
                . "->revealable()"
                . $required;
        }

        if (Str::contains($fieldName, ['phone', 'telefono', 'celular'])) {
            return "Forms\\Components\\TextInput::make('{$fieldName}')"
                . "->label('{$label}')"
                . "->tel()"
                . "->maxLength(255)"
                . $required;
        }

        if (Str::contains($fieldName, ['url', 'website', 'sitio'])) {
            return "Forms\\Components\\TextInput::make('{$fieldName}')"
                . "->label('{$label}')"
                . "->url()"
                . "->maxLength(255)"
                . $required;
        }

        // Por tipo de datos
        switch ($type) {
            case 'text':
            case 'longtext':
                return "Forms\\Components\\Textarea::make('{$fieldName}')"
                    . "->label('{$label}')"
                    . "->columnSpanFull()"
                    . "->rows(3)"
                    . $required;

            case 'boolean':
                // Usar label limpio para campos boolean
                $toggleLabel = $this->__getBooleanToggleLabel($fieldName);

                return "Forms\\Components\\Toggle::make('{$fieldName}')"
                    . "->label('{$toggleLabel}')"
                    . "->inline(false)"
                    . $this->__getBooleanDefaultValue($fieldData)
                    . $required;

            case 'date':
                return "Forms\\Components\\DatePicker::make('{$fieldName}')"
                    . "->label('{$label}')"
                    . "->native(false)"
                    . $required;

            case 'datetime':
            case 'timestamp':
                return "Forms\\Components\\DateTimePicker::make('{$fieldName}')"
                    . "->label('{$label}')"
                    . "->native(false)"
                    . $required;

            case 'decimal':
            case 'float':
            case 'double':
                return "Forms\\Components\\TextInput::make('{$fieldName}')"
                    . "->label('{$label}')"
                    . "->numeric()"
                    . "->step(0.01)"
                    . "->inputMode('decimal')"
                    . $required;

            case 'integer':
            case 'bigint':
            case 'tinyint':
                // Si es tinyint pero no es boolean, tratarlo como número
                if ($fieldData['is_boolean_tinyint'] ?? false) {
                    $toggleLabel = $this->__getBooleanToggleLabel($fieldName);
                    return "Forms\\Components\\Toggle::make('{$fieldName}')"
                        . "->label('{$toggleLabel}')"
                        . "->inline(false)"
                        . $this->__getBooleanDefaultValue($fieldData)
                        . $required;
                }

                return "Forms\\Components\\TextInput::make('{$fieldName}')"
                    . "->label('{$label}')"
                    . "->numeric()"
                    . "->inputMode('numeric')"
                    . $required;

            case 'json':
                return "Forms\\Components\\KeyValue::make('{$fieldName}')"
                    . "->label('{$label}')"
                    . "->columnSpanFull()"
                    . $required;

            default: // string, varchar, etc.
                // Detectar longitud máxima del varchar
                $maxLength = $this->__getVarcharMaxLength($rawType);

                return "Forms\\Components\\TextInput::make('{$fieldName}')"
                    . "->label('{$label}')"
                    . "->maxLength({$maxLength})"
                    . $required;
        }
    }

    /**
     * Detectar si un campo es de orden/posición
     */
    private function __isOrderField($fieldName): bool
    {
        $orderFieldNames = [
            'order',
            'sort',
            'sort_order',
            'display_order',
            'position',
            'sequence',
            'priority',
            'rank',
            'weight',
            'index',
            'orden',
            'posicion',
            'secuencia',
            'prioridad',
            'peso',
            'indice'
        ];

        $fieldLower = strtolower($fieldName);

        // Coincidencias exactas
        if (in_array($fieldLower, $orderFieldNames)) {
            return true;
        }

        // Patrones que terminan con palabras de orden
        $orderSuffixes = ['_order', '_sort', '_position', '_sequence', '_priority', '_rank', '_weight', '_index', '_orden', '_posicion'];
        foreach ($orderSuffixes as $suffix) {
            if (Str::endsWith($fieldLower, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generar campo hidden para orden con valor automático
     */
    private function __generateOrderField($fieldName, $fieldData): string
    {
        $modelName = $this->argument('name');
        $tableName = Str::plural(Str::snake($modelName));

        return "Forms\\Components\\Hidden::make('{$fieldName}')"
            . "->default(function () {"
            . "    return (int) {$modelName}::max('{$fieldName}') + 1;"
            . "})"
            . "->dehydrated()";
    }

    /**
     * Obtener label limpio para campos (quitar prefijos is_, es_, has_, etc.)
     */
    private function __getCleanFieldLabel($fieldName): string
    {
        $cleanName = $fieldName;

        // Quitar prefijos comunes
        $prefixes = ['is_', 'es_', 'has_', 'have_', 'can_', 'should_', 'will_', 'was_', 'were_'];

        foreach ($prefixes as $prefix) {
            if (Str::startsWith($cleanName, $prefix)) {
                $cleanName = Str::after($cleanName, $prefix);
                break;
            }
        }

        // Convertir a título y reemplazar guiones bajos con espacios
        return Str::title(str_replace('_', ' ', $cleanName));
    }

    /**
     * Obtener label apropiado para campos boolean/toggle
     */
    private function __getBooleanToggleLabel($fieldName): string
    {
        // Primero limpiar el nombre
        $cleanName = $fieldName;

        // Quitar prefijos is_, es_, etc.
        $prefixes = ['is_', 'es_', 'has_', 'have_', 'can_', 'should_', 'will_', 'was_', 'were_'];
        foreach ($prefixes as $prefix) {
            if (Str::startsWith($cleanName, $prefix)) {
                $cleanName = Str::after($cleanName, $prefix);
                break;
            }
        }

        // Diccionario de traducciones específicas para campos boolean
        $labels = [
            'active' => 'Activo',
            'activo' => 'Activo',
            'enabled' => 'Habilitado',
            'habilitado' => 'Habilitado',
            'visible' => 'Visible',
            'published' => 'Publicado',
            'publicado' => 'Publicado',
            'verified' => 'Verificado',
            'verificado' => 'Verificado',
            'confirmed' => 'Confirmado',
            'confirmado' => 'Confirmado',
            'featured' => 'Destacado',
            'destacado' => 'Destacado',
            'premium' => 'Premium',
            'public' => 'Público',
            'publico' => 'Público',
            'private' => 'Privado',
            'privado' => 'Privado',
            'approved' => 'Aprobado',
            'aprobado' => 'Aprobado',
            'completed' => 'Completado',
            'completado' => 'Completado',
            'finished' => 'Finalizado',
            'finalizado' => 'Finalizado',
            'paid' => 'Pagado',
            'pagado' => 'Pagado',
            'free' => 'Gratuito',
            'gratuito' => 'Gratuito',
            'online' => 'En línea',
            'available' => 'Disponible',
            'disponible' => 'Disponible',
            'blocked' => 'Bloqueado',
            'bloqueado' => 'Bloqueado',
            'banned' => 'Suspendido',
            'suspendido' => 'Suspendido',
            'deleted' => 'Eliminado',
            'eliminado' => 'Eliminado',
            'archived' => 'Archivado',
            'archivado' => 'Archivado',
            'hidden' => 'Oculto',
            'oculto' => 'Oculto',
            'urgent' => 'Urgente',
            'important' => 'Importante',
            'importante' => 'Importante',
            'special' => 'Especial',
            'popular' => 'Popular',
            'recommended' => 'Recomendado',
            'recomendado' => 'Recomendado',
            'favorite' => 'Favorito',
            'favorito' => 'Favorito',
            'pinned' => 'Fijado',
            'fijado' => 'Fijado',
            'locked' => 'Bloqueado',
            'bloqueado' => 'Bloqueado',
            'readonly' => 'Solo lectura',
            'editable' => 'Editable',
            'required' => 'Requerido',
            'requerido' => 'Requerido',
            'optional' => 'Opcional',
            'opcional' => 'Opcional',
            'automatic' => 'Automático',
            'automatico' => 'Automático',
            'manual' => 'Manual',
            'default' => 'Por defecto',
            'custom' => 'Personalizado',
            'personalizado' => 'Personalizado'
        ];

        $cleanNameLower = strtolower($cleanName);

        return $labels[$cleanNameLower] ?? Str::title(str_replace('_', ' ', $cleanName));
    }

    /**
     * Obtener valor por defecto para campos boolean
     */
    private function __getBooleanDefaultValue($fieldData): string
    {
        $default = $fieldData['default'];

        if ($default === '1' || $default === 1 || $default === true) {
            return '->default(true)';
        } elseif ($default === '0' || $default === 0 || $default === false) {
            return '->default(false)';
        }

        return '';
    }

    /**
     * Obtener longitud máxima de campos varchar
     */
    private function __getVarcharMaxLength($rawType): int
    {
        if (preg_match('/varchar\((\d+)\)/', $rawType, $matches)) {
            return (int) $matches[1];
        }

        // Valores por defecto según el tipo
        if (Str::contains($rawType, 'text')) {
            return 65535; // TEXT
        }

        return 255; // Default para string
    }

    /**
     * Generar columnas de la tabla
     */
    private function __generateTableColumns($fields, $belongsToRelations): string
    {
        $tableColumns = [];

        // Siempre agregar ID
        $tableColumns[] = "Tables\\Columns\\TextColumn::make('id')"
            . "->label('ID')"
            . "->sortable()";

        foreach ($fields as $fieldName => $fieldData) {
            // Saltar campos de orden en la tabla principal (pero mostrar en RelationManagers para reordenar)
            if ($this->__isOrderField($fieldName) && !$this->__isInRelationManager()) {
                continue;
            }

            $column = $this->__getFilamentTableColumn($fieldName, $fieldData, $belongsToRelations);
            if ($column) {
                $tableColumns[] = $column;
            }
        }

        // Agregar timestamps si existen
        $tableName = Str::plural(Str::snake($this->argument('name')));
        if (Schema::hasColumn($tableName, 'created_at')) {
            $tableColumns[] = "Tables\\Columns\\TextColumn::make('created_at')"
                . "->label('Creado')"
                . "->dateTime()"
                . "->sortable()"
                . "->toggleable(isToggledHiddenByDefault: true)";

            $tableColumns[] = "Tables\\Columns\\TextColumn::make('updated_at')"
                . "->label('Actualizado')"
                . "->dateTime()"
                . "->sortable()"
                . "->toggleable(isToggledHiddenByDefault: true)";
        }

        return implode(",\n                ", $tableColumns);
    }

    /**
     * Detectar si estamos generando un RelationManager
     */
    private function __isInRelationManager(): bool
    {
        // Verificar el stack de llamadas para determinar si estamos en un RelationManager
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

        foreach ($backtrace as $trace) {
            if (
                isset($trace['function']) &&
                ($trace['function'] === '__createRelationManager' || $trace['function'] === '__createRelationManagers')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Customizar la tabla del Resource
     */
    private function __customizeTable($content, $fields, $belongsToRelations, $hasSoftDeletes = false): string
    {
        $tableColumns = $this->__generateTableColumns($fields, $belongsToRelations);

        // Verificar si hay campos de orden para agregar reorder functionality
        $hasOrderField = $this->__hasOrderField($fields);
        $reorderConfig = $hasOrderField ? $this->__generateReorderConfig($fields) : '';

        // Configurar filtros según SoftDeletes
        $filters = $hasSoftDeletes ?
            "Tables\\Filters\\TrashedFilter::make()," :
            "// Agregar filtros aquí";

        // Configurar acciones según SoftDeletes
        $actions = $hasSoftDeletes ?
            "Tables\\Actions\\ViewAction::make(),
                Tables\\Actions\\EditAction::make(),
                Tables\\Actions\\DeleteAction::make(),
                Tables\\Actions\\ForceDeleteAction::make(),
                Tables\\Actions\\RestoreAction::make()," :
            "Tables\\Actions\\ViewAction::make(),
                Tables\\Actions\\EditAction::make(),
                Tables\\Actions\\DeleteAction::make(),";

        // Configurar bulk actions según SoftDeletes
        $bulkActions = $hasSoftDeletes ?
            "Tables\\Actions\\DeleteBulkAction::make(),
                    Tables\\Actions\\ForceDeleteBulkAction::make(),
                    Tables\\Actions\\RestoreBulkAction::make()," :
            "Tables\\Actions\\DeleteBulkAction::make(),";

        // Buscar el método table() y reemplazar todo su contenido
        $pattern = '/public static function table\(Table \$table\): Table\s*\{.*?return \$table.*?;\s*\}/s';

        $defaultSort = $hasOrderField ? $this->__getOrderFieldName($fields) : "'id'";
        $sortDirection = $hasOrderField ? "'asc'" : "'desc'";

        $replacement = "public static function table(Table \$table): Table
    {
        return \$table
            ->columns([
                {$tableColumns}
            ])
            ->filters([
                {$filters}
            ])
            ->actions([
                {$actions}
            ])
            ->bulkActions([
                Tables\\Actions\\BulkActionGroup::make([
                    {$bulkActions}
                ]),
            ])
            ->emptyStateActions([
                Tables\\Actions\\CreateAction::make(),
            ]){$reorderConfig}
            ->defaultSort({$defaultSort}, {$sortDirection});
    }";

        return preg_replace($pattern, $replacement, $content);
    }

    /**
     * Verificar si hay campos de orden
     */
    private function __hasOrderField($fields): bool
    {
        foreach ($fields as $fieldName => $fieldData) {
            if ($this->__isOrderField($fieldName)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Obtener el nombre del campo de orden
     */
    private function __getOrderFieldName($fields): string
    {
        foreach ($fields as $fieldName => $fieldData) {
            if ($this->__isOrderField($fieldName)) {
                return "'{$fieldName}'";
            }
        }
        return "'id'";
    }

    /**
     * Generar configuración de reorder para tablas con campos de orden
     */
    private function __generateReorderConfig($fields): string
    {
        $orderField = null;

        foreach ($fields as $fieldName => $fieldData) {
            if ($this->__isOrderField($fieldName)) {
                $orderField = $fieldName;
                break;
            }
        }

        if (!$orderField) {
            return '';
        }

        return "\n            ->reorderable('{$orderField}')";
    }

    /**
     * Obtener columna de tabla de Filament adecuada
     */
    private function __getFilamentTableColumn($fieldName, $fieldData, $belongsToRelations): ?string
    {
        $type = $fieldData['type'];
        $label = $this->__getCleanFieldLabel($fieldName);

        // Si es foreign key, mostrar la relación
        if ($fieldData['foreign_key']) {
            $relation = collect($belongsToRelations)->firstWhere('foreign_key', $fieldName);
            if ($relation) {
                $relationName = $relation['name'];
                return "Tables\\Columns\\TextColumn::make('{$relationName}.name')"
                    . "->label('{$label}')"
                    . "->sortable()"
                    . "->searchable()";
            }
        }

        switch ($type) {
            case 'boolean':
                // Usar labels apropiados para campos boolean comunes
                $booleanLabel = $this->__getBooleanToggleLabel($fieldName);

                return "Tables\\Columns\\IconColumn::make('{$fieldName}')"
                    . "->label('{$booleanLabel}')"
                    . "->boolean()"
                    . "->trueIcon('heroicon-o-check-badge')"
                    . "->falseIcon('heroicon-o-x-mark')"
                    . "->trueColor('success')"
                    . "->falseColor('gray')";

            case 'date':
                return "Tables\\Columns\\TextColumn::make('{$fieldName}')"
                    . "->label('{$label}')"
                    . "->date()"
                    . "->sortable()";

            case 'datetime':
            case 'timestamp':
                return "Tables\\Columns\\TextColumn::make('{$fieldName}')"
                    . "->label('{$label}')"
                    . "->dateTime()"
                    . "->sortable()";

            case 'text':
            case 'longtext':
                return "Tables\\Columns\\TextColumn::make('{$fieldName}')"
                    . "->label('{$label}')"
                    . "->limit(50)"
                    . "->tooltip(function (TextColumn \$column): ?string {"
                    . "    \$state = \$column->getState();"
                    . "    if (strlen(\$state) <= 50) return null;"
                    . "    return \$state;"
                    . "})"
                    . "->searchable()";

            case 'decimal':
            case 'float':
            case 'double':
                return "Tables\\Columns\\TextColumn::make('{$fieldName}')"
                    . "->label('{$label}')"
                    . "->numeric()"
                    . "->sortable()";

            case 'integer':
            case 'bigint':
                // Si es tinyint(1) boolean, usar IconColumn
                if ($fieldData['is_boolean_tinyint'] ?? false) {
                    $booleanLabel = $this->__getBooleanToggleLabel($fieldName);

                    return "Tables\\Columns\\IconColumn::make('{$fieldName}')"
                        . "->label('{$booleanLabel}')"
                        . "->boolean()"
                        . "->trueIcon('heroicon-o-check-badge')"
                        . "->falseIcon('heroicon-o-x-mark')"
                        . "->trueColor('success')"
                        . "->falseColor('gray')";
                }

                return "Tables\\Columns\\TextColumn::make('{$fieldName}')"
                    . "->label('{$label}')"
                    . "->numeric()"
                    . "->sortable()";

            case 'tinyint':
                // Siempre verificar si es boolean para tinyint
                if ($fieldData['is_boolean_tinyint'] ?? false) {
                    $booleanLabel = $this->__getBooleanToggleLabel($fieldName);

                    return "Tables\\Columns\\IconColumn::make('{$fieldName}')"
                        . "->label('{$booleanLabel}')"
                        . "->boolean()"
                        . "->trueIcon('heroicon-o-check-badge')"
                        . "->falseIcon('heroicon-o-x-mark')"
                        . "->trueColor('success')"
                        . "->falseColor('gray')";
                }

                return "Tables\\Columns\\TextColumn::make('{$fieldName}')"
                    . "->label('{$label}')"
                    . "->numeric()"
                    . "->sortable()";

            default:
                return "Tables\\Columns\\TextColumn::make('{$fieldName}')"
                    . "->label('{$label}')"
                    . "->searchable()"
                    . "->sortable()";
        }
    }

    /**
     * Crear RelationManagers para relaciones hasMany
     */
    private function __createRelationManagers($name, $relations, $force): void
    {
        foreach ($relations['has_many'] as $relation) {
            $this->__createRelationManager($name, $relation, $force);
        }
    }

    /**
     * Crear un RelationManager individual
     */
    private function __createRelationManager($parentName, $relation, $force): void
    {
        $relationName = Str::studly($relation['name']);
        $managerPath = app_path("Filament/Resources/{$parentName}Resource/RelationManagers/{$relationName}RelationManager.php");

        if (File::exists($managerPath) && !$force) {
            $this->warn("⚠️  RelationManager '{$relationName}RelationManager' ya existe. Usa --force para sobrescribirlo.");
            return;
        }

        // Crear directorio si no existe
        $managerDir = dirname($managerPath);
        if (!File::exists($managerDir)) {
            File::makeDirectory($managerDir, 0755, true);
        }

        // Verificar si la tabla relacionada tiene SoftDeletes
        $hasSoftDeletes = $this->__tableHasSoftDeletes($relation['related_table']);

        $relationStub = $this->__getRelationManagerStub($hasSoftDeletes);

        // Reemplazos
        $relationStub = str_replace('{{ parentClass }}', $parentName, $relationStub);
        $relationStub = str_replace('{{ relationName }}', $relationName, $relationStub);
        $relationStub = str_replace('{{ relationNameLower }}', strtolower($relation['name']), $relationStub);
        $relationStub = str_replace('{{ relatedModel }}', $relation['model'], $relationStub);

        // Obtener campos de la tabla relacionada
        $relatedFields = $this->__getTableColumns($relation['related_table']);
        $formFields = $this->__generateFormFields($relatedFields, []);
        $tableColumns = $this->__generateTableColumns($relatedFields, []);

        $relationStub = str_replace('{{ formFields }}', $formFields, $relationStub);
        $relationStub = str_replace('{{ tableColumns }}', $tableColumns, $relationStub);

        File::put($managerPath, $relationStub);
        $this->info("✅ RelationManager '{$relationName}RelationManager' creado exitosamente.");
    }

    /**
     * Verificar si una tabla tiene SoftDeletes (deleted_at)
     */
    private function __tableHasSoftDeletes($tableName): bool
    {
        if (!Schema::hasTable($tableName)) {
            return false;
        }

        return Schema::hasColumn($tableName, 'deleted_at');
    }

    /**
     * Obtener stub del RelationManager según si tiene SoftDeletes o no
     */
    private function __getRelationManagerStub($hasSoftDeletes = false): string
    {
        $softDeleteImports = $hasSoftDeletes ?
            "use Illuminate\Database\Eloquent\Builder;\nuse Illuminate\Database\Eloquent\SoftDeletingScope;" : '';

        $softDeleteFilters = $hasSoftDeletes ?
            "Tables\\Filters\\TrashedFilter::make()," : "// Agregar filtros aquí";

        $softDeleteActions = $hasSoftDeletes ?
            "Tables\\Actions\\ViewAction::make(),
                Tables\\Actions\\EditAction::make(),
                Tables\\Actions\\DeleteAction::make(),
                Tables\\Actions\\ForceDeleteAction::make(),
                Tables\\Actions\\RestoreAction::make()," :
            "Tables\\Actions\\ViewAction::make(),
                Tables\\Actions\\EditAction::make(),
                Tables\\Actions\\DeleteAction::make(),";

        $softDeleteBulkActions = $hasSoftDeletes ?
            "Tables\\Actions\\DeleteBulkAction::make(),
                    Tables\\Actions\\ForceDeleteBulkAction::make(),
                    Tables\\Actions\\RestoreBulkAction::make()," :
            "Tables\\Actions\\DeleteBulkAction::make(),";

        $softDeleteQuery = $hasSoftDeletes ?
            "->modifyQueryUsing(fn (Builder \$query) => \$query->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]))" : "";

        return "<?php

namespace App\\Filament\\Resources\\{{ parentClass }}Resource\\RelationManagers;

use Filament\\Forms;
use Filament\\Forms\\Form;
use Filament\\Resources\\RelationManagers\\RelationManager;
use Filament\\Tables;
use Filament\\Tables\\Table;
use Filament\\Tables\\Columns\\TextColumn;
{$softDeleteImports}

class {{ relationName }}RelationManager extends RelationManager
{
    protected static string \$relationship = '{{ relationNameLower }}';

    protected static ?string \$recordTitleAttribute = 'name';

    public function form(Form \$form): Form
    {
        return \$form
            ->schema([
                Forms\\Components\\Section::make()
                    ->schema([
                        {{ formFields }}
                    ])
                    ->columns(2),
            ]);
    }

    public function table(Table \$table): Table
    {
        return \$table
            ->recordTitleAttribute('name')
            ->columns([
                {{ tableColumns }}
            ])
            ->filters([
                {$softDeleteFilters}
            ])
            ->headerActions([
                Tables\\Actions\\CreateAction::make(),
            ])
            ->actions([
                {$softDeleteActions}
            ])
            ->bulkActions([
                Tables\\Actions\\BulkActionGroup::make([
                    {$softDeleteBulkActions}
                ]),
            ])
            ->emptyStateActions([
                Tables\\Actions\\CreateAction::make(),
            ]){$softDeleteQuery};
    }
}
";
    }

    /**
     * Generar array de RelationManagers para el Resource
     */
    private function __generateRelationManagersArray($hasMany): string
    {
        if (empty($hasMany)) {
            return '';
        }

        $managers = [];
        foreach ($hasMany as $relation) {
            $relationName = Str::studly($relation['name']);
            $managers[] = "RelationManagers\\{$relationName}RelationManager::class";
        }

        return implode(",\n            ", $managers);
    }

    /**
     * Crear una página individual
     */
    private function __createPage($name, $pageType, $stubType, $force): void
    {
        $pagePath = app_path("Filament/Resources/{$name}Resource/Pages/{$pageType}{$name}s.php");

        if (File::exists($pagePath) && !$force) {
            return;
        }

        $pageStub = $this->__getStub("filament/pages/{$stubType}");
        $pageStub = str_replace('{{ resourceClass }}', "{$name}Resource", $pageStub);
        $pageStub = str_replace('{{ pageClass }}', "{$pageType}{$name}s", $pageStub);
        $pageStub = str_replace('{{ model }}', $name, $pageStub);

        File::put($pagePath, $pageStub);
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
     * Obtener stub del Resource básico
     */
    private function __getResourceStub(): string
    {
        return '<?php

namespace App\Filament\Resources;

use App\Filament\Resources\{{ class }}\Pages;
use App\Filament\Resources\{{ class }}\RelationManagers;
use App\Models\{{ model }};
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\Hash;

class {{ class }} extends Resource
{
    protected static ?string $model = {{ model }}::class;

    protected static ?string $navigationIcon = "heroicon-o-rectangle-stack";

    protected static ?string $navigationLabel = "{{ modelTitle }}s";

    protected static ?string $pluralModelLabel = "{{ modelTitle }}s";

    protected static ?string $modelLabel = "{{ modelTitle }}";

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\\Components\\Section::make("Información Principal")
                    ->schema([
                        {{ formFields }}
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                {{ tableColumns }}
            ])
            ->filters([
                // Agregar filtros aquí
            ])
            ->actions([
                Tables\\Actions\\ViewAction::make(),
                Tables\\Actions\\EditAction::make(),
                Tables\\Actions\\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\\Actions\\BulkActionGroup::make([
                    Tables\\Actions\\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            {{ relationManagers }}
        ];
    }

    public static function getPages(): array
    {
        return [
            "index" => Pages\\List{{ model }}s::route("/"),
            "create" => Pages\\Create{{ model }}::route("/create"),
            "view" => Pages\\View{{ model }}::route("/{record}"),
            "edit" => Pages\\Edit{{ model }}::route("/{record}/edit"),
        ];
    }
}
';
    }

    /**
     * Obtener stubs por defecto para casos de fallback
     */
    private function __getDefaultStub(string $stubName): string
    {
        switch ($stubName) {
            case 'filament/relation-manager':
                // Este stub ya no se usa, se genera dinámicamente
                return $this->__getRelationManagerStub(false);

            case 'filament/pages/ListPage':
                return '<?php

namespace App\Filament\Resources\{{ resourceClass }}\Pages;

use App\Filament\Resources\{{ resourceClass }};
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class {{ pageClass }} extends ListRecords
{
    protected static string $resource = {{ resourceClass }}::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
';

            case 'filament/pages/CreatePage':
                return '<?php

namespace App\Filament\Resources\{{ resourceClass }}\Pages;

use App\Filament\Resources\{{ resourceClass }};
use Filament\Resources\Pages\CreateRecord;

class {{ pageClass }} extends CreateRecord
{
    protected static string $resource = {{ resourceClass }}::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl("index");
    }
}
';

            case 'filament/pages/EditPage':
                return '<?php

namespace App\Filament\Resources\{{ resourceClass }}\Pages;

use App\Filament\Resources\{{ resourceClass }};
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class {{ pageClass }} extends EditRecord
{
    protected static string $resource = {{ resourceClass }}::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl("index");
    }
}
';

            case 'filament/pages/ViewPage':
                return '<?php

namespace App\Filament\Resources\{{ resourceClass }}\Pages;

use App\Filament\Resources\{{ resourceClass }};
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class {{ pageClass }} extends ViewRecord
{
    protected static string $resource = {{ resourceClass }}::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
';

            default:
                return '';
        }
    }
}
