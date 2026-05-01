<?php
// Script para extraer solo el rDE del XML SOAP

$xmlContent = file_get_contents(__DIR__ . '/xml_firmado_nr_20260128080437.xml');

// Buscar y extraer solo la parte <rDE>...</rDE>
if (preg_match('/<rDE.*?<\/rDE>/s', $xmlContent, $matches)) {
    $rdeXml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $matches[0];

    $outputFile = __DIR__ . '/xml_rde_validador.xml';
    file_put_contents($outputFile, $rdeXml);

    echo "Archivo creado: $outputFile\n";
    echo "Tamaño: " . filesize($outputFile) . " bytes\n";
} else {
    echo "No se pudo encontrar el elemento rDE\n";
}
