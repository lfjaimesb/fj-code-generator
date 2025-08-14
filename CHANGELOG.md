# Changelog

Todos los cambios notables de este proyecto serán documentados en este archivo.

El formato está basado en [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
y este proyecto adhiere a [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2025-01-XX

### Added
- 🔥 Comando `fj:api-resource` para generar recursos API completos
- 🎯 Comando `fj:filament-resource` para generar Resources de Filament
- 🧠 Detección automática de tipos de campo y relaciones
- ⚡ Validaciones inteligentes según tipo de datos
- 🎨 RelationManagers automáticos para relaciones hasMany
- 📱 Componentes Filament optimizados para cada tipo de campo
- 🌮 Mensajes con humor mexicano y actitud Gen Z
- 🛡️ Manejo robusto de errores y validaciones
- 📚 Stubs personalizables para máxima flexibilidad
- 🔧 Configuración completa del paquete

### Features
- Detección automática de campos desde base de datos existente
- Generación manual de campos si no existe la tabla
- Support para foreign keys con relaciones automáticas
- Componentes Filament específicos (TextInput, Select, Toggle, etc.)
- Validaciones API inteligentes (email, password, unique, etc.)
- RelationManagers solo cuando existen relaciones hasMany
- Pages de Filament (List, Create, Edit, View)
- Stubs completamente personalizables
- Configuración por archivo de config
- Support para Laravel 10.x y 11.x
- Compatible con Filament 3.x

## [0.1.0] - 2025-01-XX

### Added
- Versión inicial del proyecto
- Comandos básicos funcionando
- Estructura del paquete