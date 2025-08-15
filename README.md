# 🔥 FJ Code Generator

[![Tests](https://github.com/lfjaimesb/fj-code-generator/workflows/tests/badge.svg)](https://github.com/lfjaimesb/fj-code-generator)

> ¡El generador de código más chingón para Laravel! 🚀 Con actitud Gen Z y humor mexicano.

Automatiza la creación de API Resources y Filament Resources detectando automáticamente tipos de campo, relaciones y validaciones. ¡Ya no más código repetitivo!

## ✨ Características

- 🔥 **API Resources automáticos**: Genera Models, Controllers, Requests y Routes
- 🎯 **Filament Resources inteligentes**: Formularios y tablas automáticas
- 🧠 **Detección inteligente**: Reconoce tipos de campo y relaciones
- ⚡  **Validaciones automáticas**: Reglas según el tipo de campo
- 🎨 **RelationManagers**: Solo si existen relaciones hasMany
- 📱 **Responsive**: Componentes optimizados para mobile
- 🌮 **Con sabor mexicano**: Mensajes divertidos y motivadores

## 📦 Instalación

```bash
composer config repositories.fj-code-generator vcs https://github.com/lfjaimesb/fj-code-generator
composer require lfjaimesb/fj-code-generator --dev

php artisan fj:filament-resource Product