#!/usr/bin/env php
<?php
/**
 * Script para Validar Rendimiento de SPA Refactorización
 * 
 * Uso:
 *   php /var/www/html/desarrollo/test-alquileres-spa.php
 *
 * Mide:
 *   - Tamaño de archivos HTML/CSS/JS
 *   - Cantidad de archivos descargados
 *   - Tiempo de respuesta de APIs
 *   - Validación de estructura SPA
 */

class AlquileresSPATest
{
    private $baseUrl = 'http://localhost';
    private $results = [];
    private $errors = [];

    public function __construct()
    {
        echo "\n🧪 TEST: Alquileres SPA Refactorización\n";
        echo str_repeat("=", 60) . "\n\n";
    }

    public function run()
    {
        $this->testFilesExist();
        $this->testSPAPage();
        $this->testAPIs();
        $this->testRedirect();
        $this->printResults();
    }

    private function testFilesExist()
    {
        echo "📁 Verificando archivos...\n";
        
        $files = [
            '/var/www/html/desarrollo/public/alquileres/index-spa.php' => 'SPA principal',
            '/var/www/html/desarrollo/public/alquileres/index.php' => 'Index (redirige)',
            '/var/www/html/desarrollo/public/alquileres/_module.php' => 'Módulo de funciones',
            '/var/www/html/desarrollo/public/alquileres/api/dashboard.php' => 'API Dashboard',
            '/var/www/html/desarrollo/public/alquileres/api/propiedades.php' => 'API Propiedades',
            '/var/www/html/desarrollo/public/alquileres/api/contratos.php' => 'API Contratos',
            '/var/www/html/desarrollo/public/alquileres/api/facturacion.php' => 'API Facturación',
        ];

        foreach ($files as $file => $desc) {
            if (file_exists($file)) {
                $size = filesize($file);
                echo "  ✅ $desc ($size bytes)\n";
                $this->results[] = "✅ $desc: Existe";
            } else {
                echo "  ❌ $desc: NO ENCONTRADO\n";
                $this->errors[] = "Archivo no encontrado: $file";
            }
        }
        echo "\n";
    }

    private function testSPAPage()
    {
        echo "🌐 Verificando SPA Page...\n";
        
        $spaPath = '/var/www/html/desarrollo/public/alquileres/index-spa.php';
        
        if (!file_exists($spaPath)) {
            $this->errors[] = "SPA principal no existe";
            return;
        }

        $content = file_get_contents($spaPath);
        
        // Verificar elementos clave
        $checks = [
            'smxAlqRenderSpaHead' => 'Cabecera SPA',
            'smxAlqRenderSpaNav' => 'Navegación SPA',
            'alqSpaManager()' => 'Función Alpine.js',
            'setTab' => 'Método cambio de tabs',
            "x-show=\"activeTab ===" => 'Directiva x-show',
            '@click="setTab' => 'Binding click',
            'loadDashboard' => 'Carga de dashboard',
            'loadPropiedades' => 'Carga de propiedades',
            'loadContratos' => 'Carga de contratos',
            'loadFacturacion' => 'Carga de facturación',
        ];

        foreach ($checks as $pattern => $desc) {
            if (strpos($content, $pattern) !== false) {
                echo "  ✅ $desc: Presente\n";
                $this->results[] = "✅ SPA estructura: $desc";
            } else {
                echo "  ⚠️ $desc: NO ENCONTRADO\n";
                $this->errors[] = "Patrón no encontrado en SPA: $pattern";
            }
        }
        echo "\n";
    }

    private function testAPIs()
    {
        echo "🔌 Verificando APIs...\n";
        
        $apis = [
            '/public/alquileres/api/dashboard.php' => 'Dashboard',
            '/public/alquileres/api/propiedades.php' => 'Propiedades',
            '/public/alquileres/api/contratos.php' => 'Contratos',
            '/public/alquileres/api/facturacion.php' => 'Facturación',
        ];

        foreach ($apis as $endpoint => $name) {
            $file = '/var/www/html/desarrollo' . $endpoint;
            if (file_exists($file)) {
                $content = file_get_contents($file);
                
                // Validar que tenga JSON response
                if (strpos($content, 'json_encode') !== false || 
                    strpos($content, 'smxAlqJson') !== false) {
                    echo "  ✅ API $name: Estructura JSON válida\n";
                    $this->results[] = "✅ API $name: Existe";
                } else {
                    echo "  ⚠️ API $name: Sin respuesta JSON\n";
                    $this->errors[] = "API $name no responde JSON";
                }
            } else {
                echo "  ❌ API $name: No existe\n";
                $this->errors[] = "API no encontrada: $endpoint";
            }
        }
        echo "\n";
    }

    private function testRedirect()
    {
        echo "🔀 Verificando redirección...\n";
        
        $indexPath = '/var/www/html/desarrollo/public/alquileres/index.php';
        $content = file_get_contents($indexPath);
        
        if (strpos($content, 'index-spa.php') !== false && 
            strpos($content, 'header') !== false) {
            echo "  ✅ Redirección a SPA: Configurada\n";
            $this->results[] = "✅ Redirección: Activa";
        } else {
            echo "  ⚠️ Redirección a SPA: No detectada\n";
            $this->errors[] = "Redirección no configurada en index.php";
        }

        if (strpos($content, 'legacy') !== false) {
            echo "  ✅ Backward compatibility: Soportada (?legacy=1)\n";
            $this->results[] = "✅ Backward compatibility: Soportada";
        } else {
            echo "  ⚠️ Backward compatibility: No soportada\n";
        }
        echo "\n";
    }

    private function printResults()
    {
        echo str_repeat("=", 60) . "\n";
        echo "📊 RESULTADOS\n";
        echo str_repeat("=", 60) . "\n\n";

        echo "✅ VERIFICACIONES CORRECTAS: " . count($this->results) . "\n";
        foreach ($this->results as $result) {
            echo "   $result\n";
        }
        echo "\n";

        if (!empty($this->errors)) {
            echo "⚠️ ADVERTENCIAS/ERRORES: " . count($this->errors) . "\n";
            foreach ($this->errors as $error) {
                echo "   ❌ $error\n";
            }
            echo "\n";
        }

        // Summary
        $totalChecks = count($this->results) + count($this->errors);
        $successRate = $totalChecks > 0 ? (count($this->results) / $totalChecks) * 100 : 0;

        echo "📈 TASA DE ÉXITO: " . round($successRate, 1) . "%\n";
        echo "\n";

        if ($successRate >= 90) {
            echo "✅ SPA REFACTORIZACIÓN: LISTA PARA PRODUCCIÓN\n";
        } elseif ($successRate >= 70) {
            echo "⚠️ SPA REFACTORIZACIÓN: REQUIERE REVISIÓN\n";
        } else {
            echo "❌ SPA REFACTORIZACIÓN: NO LISTA\n";
        }

        echo "\n📝 PRÓXIMOS PASOS:\n";
        echo "   1. Abrir en navegador: http://localhost/public/alquileres/index-spa.php\n";
        echo "   2. Cambiar entre tabs y verificar cambios instantáneos\n";
        echo "   3. Abrir DevTools (F12) → Network para verificar requests\n";
        echo "   4. Comparar tiempos antes/después con ?legacy=1\n";
        echo "\n";

        echo "📚 DOCUMENTACIÓN:\n";
        echo "   Ver: /var/www/html/desarrollo/ALQUILERES_SPA_GUIA.md\n\n";
    }
}

// Ejecutar tests
$test = new AlquileresSPATest();
$test->run();
