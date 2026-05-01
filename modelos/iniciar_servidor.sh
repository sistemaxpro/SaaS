#!/bin/bash

# Script para iniciar el servidor SIFEN
# Este script facilita el arranque del servidor HTTP para facturatest.php

echo "=================================="
echo "    Iniciador Servidor SIFEN"
echo "=================================="
echo

# Verificar que PHP esté instalado
if ! command -v php &> /dev/null; then
    echo "Error: PHP no está instalado o no está en el PATH"
    exit 1
fi

# Verificar que los archivos necesarios existan
if [ ! -f "servidor_facturatest.php" ]; then
    echo "Error: No se encontró servidor_facturatest.php"
    exit 1
fi

if [ ! -f "facturatest.php" ]; then
    echo "Error: No se encontró facturatest.php"
    exit 1
fi

# Verificar si el puerto 8000 está en uso
if netstat -tuln 2>/dev/null | grep -q ":8000 "; then
    echo "Advertencia: El puerto 8000 parece estar en uso"
    echo "¿Desea continuar de todos modos? (s/n)"
    read -r respuesta
    if [[ ! $respuesta =~ ^[Ss]$ ]]; then
        echo "Operación cancelada"
        exit 1
    fi
fi

echo "Iniciando servidor SIFEN..."
echo "Para detener el servidor, presiona Ctrl+C"
echo

# Ejecutar el servidor
php servidor_facturatest.php

echo
echo "Servidor detenido"