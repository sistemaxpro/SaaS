<?php
require_once __DIR__ . '/../config/bootstrap.php';

// Requerir login
Session::requireLogin('/public/login.php');

// Contenido de la página
$pageTitle = 'Ejemplos de Botones';
$content = <<<HTML
<div class="max-w-6xl mx-auto">
    <!-- Header -->
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-900 dark:text-slate-100 mb-2">
            <i class="fas fa-palette mr-2"></i>Sistema de Botones
        </h1>
        <p class="text-gray-600 dark:text-slate-400">Botones tipo línea (outline) sin gradientes - toman color al hover</p>
    </div>

    <!-- Botones Primarios -->
    <div class="bg-white dark:bg-slate-900 dark:border dark:border-slate-800 rounded-lg shadow-lg p-6 mb-6">
        <h2 class="text-xl font-bold text-gray-900 dark:text-slate-100 mb-4">Botones Primarios</h2>
        <div class="flex flex-wrap gap-4">
            <button class="btn-primary">
                <i class="fas fa-check mr-2"></i>Primario Normal
            </button>
            <button class="btn-primary btn-sm">
                <i class="fas fa-check mr-2"></i>Primario Pequeño
            </button>
            <button class="btn-primary btn-lg">
                <i class="fas fa-check mr-2"></i>Primario Grande
            </button>
        </div>
        <div class="mt-4">
            <button class="btn-primary btn-block">
                <i class="fas fa-check mr-2"></i>Primario Ancho Completo
            </button>
        </div>
    </div>

    <!-- Botones Secundarios -->
    <div class="bg-white dark:bg-slate-900 dark:border dark:border-slate-800 rounded-lg shadow-lg p-6 mb-6">
        <h2 class="text-xl font-bold text-gray-900 dark:text-slate-100 mb-4">Botones Secundarios</h2>
        <div class="flex flex-wrap gap-4">
            <button class="btn-secondary">
                <i class="fas fa-cog mr-2"></i>Secundario Normal
            </button>
            <button class="btn-secondary btn-sm">
                <i class="fas fa-cog mr-2"></i>Secundario Pequeño
            </button>
            <button class="btn-secondary btn-lg">
                <i class="fas fa-cog mr-2"></i>Secundario Grande
            </button>
        </div>
    </div>

    <!-- Botones de Estado -->
    <div class="bg-white dark:bg-slate-900 dark:border dark:border-slate-800 rounded-lg shadow-lg p-6 mb-6">
        <h2 class="text-xl font-bold text-gray-900 dark:text-slate-100 mb-4">Botones de Estado</h2>
        <div class="flex flex-wrap gap-4">
            <button class="btn-success">
                <i class="fas fa-check-circle mr-2"></i>Éxito
            </button>
            <button class="btn-danger">
                <i class="fas fa-times-circle mr-2"></i>Peligro
            </button>
            <button class="btn-warning">
                <i class="fas fa-exclamation-triangle mr-2"></i>Advertencia
            </button>
            <button class="btn-info">
                <i class="fas fa-info-circle mr-2"></i>Información
            </button>
        </div>
    </div>

    <!-- Ejemplos de Uso -->
    <div class="bg-white dark:bg-slate-900 dark:border dark:border-slate-800 rounded-lg shadow-lg p-6 mb-6">
        <h2 class="text-xl font-bold text-gray-900 dark:text-slate-100 mb-4">Ejemplos de Uso Común</h2>
        
        <!-- Formulario de ejemplo -->
        <div class="border border-gray-200 dark:border-slate-700 rounded-lg p-4 mb-4">
            <h3 class="font-semibold text-gray-700 dark:text-slate-300 mb-3">Formulario</h3>
            <div class="flex flex-wrap gap-3">
                <button class="btn-primary">
                    <i class="fas fa-save mr-2"></i>Guardar
                </button>
                <button class="btn-secondary">
                    <i class="fas fa-times mr-2"></i>Cancelar
                </button>
                <button class="btn-danger">
                    <i class="fas fa-trash mr-2"></i>Eliminar
                </button>
            </div>
        </div>

        <!-- Acciones de tabla -->
        <div class="border border-gray-200 dark:border-slate-700 rounded-lg p-4 mb-4">
            <h3 class="font-semibold text-gray-700 dark:text-slate-300 mb-3">Acciones de Tabla</h3>
            <div class="flex flex-wrap gap-2">
                <button class="btn-info btn-sm">
                    <i class="fas fa-eye mr-1"></i>Ver
                </button>
                <button class="btn-primary btn-sm">
                    <i class="fas fa-edit mr-1"></i>Editar
                </button>
                <button class="btn-danger btn-sm">
                    <i class="fas fa-trash mr-1"></i>Eliminar
                </button>
                <button class="btn-success btn-sm">
                    <i class="fas fa-download mr-1"></i>Exportar
                </button>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="border border-gray-200 dark:border-slate-700 rounded-lg p-4">
            <h3 class="font-semibold text-gray-700 dark:text-slate-300 mb-3">Barra de Herramientas</h3>
            <div class="flex flex-wrap gap-3 items-center">
                <button class="btn-primary">
                    <i class="fas fa-plus mr-2"></i>Nuevo
                </button>
                <button class="btn-success">
                    <i class="fas fa-file-export mr-2"></i>Exportar
                </button>
                <button class="btn-info">
                    <i class="fas fa-filter mr-2"></i>Filtrar
                </button>
                <button class="btn-secondary">
                    <i class="fas fa-sync mr-2"></i>Actualizar
                </button>
            </div>
        </div>
    </div>

    <!-- Código de Ejemplo -->
    <div class="bg-white dark:bg-slate-900 dark:border dark:border-slate-800 rounded-lg shadow-lg p-6">
        <h2 class="text-xl font-bold text-gray-900 dark:text-slate-100 mb-4">
            <i class="fas fa-code mr-2"></i>Código de Ejemplo
        </h2>
        <div class="bg-gray-100 dark:bg-slate-950 rounded-lg p-4 overflow-x-auto">
            <pre class="text-sm text-gray-800 dark:text-slate-300"><code>&lt;!-- Botón Primario --&gt;
&lt;button class="btn-primary"&gt;
    &lt;i class="fas fa-check mr-2"&gt;&lt;/i&gt;Aceptar
&lt;/button&gt;

&lt;!-- Botón Secundario Pequeño --&gt;
&lt;button class="btn-secondary btn-sm"&gt;
    &lt;i class="fas fa-times mr-2"&gt;&lt;/i&gt;Cancelar
&lt;/button&gt;

&lt;!-- Botón de Éxito Grande --&gt;
&lt;button class="btn-success btn-lg"&gt;
    &lt;i class="fas fa-save mr-2"&gt;&lt;/i&gt;Guardar
&lt;/button&gt;

&lt;!-- Botón de Peligro Ancho Completo --&gt;
&lt;button class="btn-danger btn-block"&gt;
    &lt;i class="fas fa-trash mr-2"&gt;&lt;/i&gt;Eliminar Todo
&lt;/button&gt;</code></pre>
        </div>
    </div>

    <!-- Navegación -->
    <div class="mt-8 flex gap-3">
        <a href="index.php" class="btn-secondary">
            <i class="fas fa-arrow-left mr-2"></i>Volver al Dashboard
        </a>
        <a href="test.php" class="btn-info">
            <i class="fas fa-vial mr-2"></i>Ir a Test
        </a>
    </div>
</div>
HTML;

// Renderizar con layout
ob_start();
echo $content;
$content = ob_get_clean();

include __DIR__ . '/../src/Layouts/base.php';
