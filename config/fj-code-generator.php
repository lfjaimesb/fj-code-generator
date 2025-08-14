<?php

return [
    /*
    |--------------------------------------------------------------------------
    | FJ Code Generator Configuration
    |--------------------------------------------------------------------------
    |
    | Configuración para el generador de código más chingón de Laravel
    |
    */

    'api' => [
        'namespace' => 'App\\Http\\Controllers\\Api',
        'model_namespace' => 'App\\Models',
        'request_namespace' => 'App\\Http\\Requests\\Api',
        'default_validation_rules' => [
            'string' => 'required|string|max:255',
            'text' => 'required|string',
            'integer' => 'required|integer',
            'boolean' => 'required|boolean',
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]
    ],

    'filament' => [
        'namespace' => 'App\\Filament\\Resources',
        'generate_pages' => true,
        'generate_relation_managers' => true,
        'default_icon' => 'heroicon-o-rectangle-stack',
    ],

    'stubs' => [
        'path' => base_path('stubs/fj'),
        'publish_on_install' => true,
    ],

    'exclusions' => [
        'columns' => ['id', 'created_at', 'updated_at', 'deleted_at'],
        'tables' => ['migrations', 'password_resets', 'failed_jobs'],
    ]
];
