#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');

const rootDir = path.resolve(__dirname, '..');
const sourceFile = path.join(rootDir, 'node_modules', '@iconify-json', 'material-symbols', 'icons.json');
const outFile = path.join(rootDir, 'public', 'assets', 'js', 'material-symbols-map.js');

if (!fs.existsSync(sourceFile)) {
  console.error('No se encontro:', sourceFile);
  console.error('Instala @iconify-json/material-symbols primero con: npm install @iconify-json/material-symbols');
  process.exit(1);
}

const iconData = JSON.parse(fs.readFileSync(sourceFile, 'utf8'));
const icons = iconData.icons || {};
const map = {};
const options = [];

function toTitle(slug) {
  return slug
    .split('-')
    .map((w) => (w ? w.charAt(0).toUpperCase() + w.slice(1) : w))
    .join(' ');
}

function inferCategory(name) {
  name = name.toLowerCase();
  if (name.includes('arrow') || name.includes('chevron') || name.includes('corner') || name.includes('bracket')) return 'general';
  if (name.includes('shopping') || name.includes('cart') || name.includes('receipt') || name.includes('tag') || name.includes('barcode') || name.includes('qr')) return 'ventas';
  if (name.includes('chart') || name.includes('graph') || name.includes('trending') || name.includes('coin') || name.includes('wallet') || name.includes('credit') || name.includes('percent') || name.includes('money') || name.includes('trending')) return 'finanzas';
  if (name.includes('package') || name.includes('box') || name.includes('truck') || name.includes('warehouse') || name.includes('layout') || name.includes('grid') || name.includes('inbox')) return 'inventario';
  if (name.includes('user') || name.includes('profile') || name.includes('account') || name.includes('person') || name.includes('male') || name.includes('female') || name.includes('group')) return 'usuarios';
  if (name.includes('shield') || name.includes('lock') || name.includes('key') || name.includes('eye') || name.includes('checkbox') || name.includes('alert') || name.includes('warning') || name.includes('security') || name.includes('verified')) return 'sistema';
  return 'general';
}

function isValidSvgBody(body) {
  if (!body || typeof body !== 'string') return false;

  // Detectar números malformados con múltiples puntos decimales (ej: -.128.87)
  if (/[\d]\.\d+\.\d+|[\s\-]\.\d+\.|\.\d+\./.test(body)) return false;

  // Detectar paths incompletos o valores inválidos
  if (body.includes('NaN') || body.includes('Infinity')) return false;

  // Validar path data
  const pathMatch = body.match(/d="([^"]*)"/);
  if (pathMatch) {
    const pathData = pathMatch[1];

    // Detectar comandos incompletos al final (m, l, h, v, c, s, q, t, a sin suficientes parámetros)
    // Un path válido no debe terminar con un comando seguido de números incompletos
    if (/[mlhvcsqtaz]\s*-?\d+(?:\s|$)/i.test(pathData.slice(-20))) {
      // Verificar si el path termina de manera incompleta
      const lastCommand = pathData.match(/([mlhvcsqtaz])[^mlhvcsqtaz]*$/i);
      if (lastCommand) {
        const cmd = lastCommand[1].toLowerCase();
        const afterCmd = pathData.slice(pathData.lastIndexOf(cmd) + 1).trim();

        // Validar cantidad de parámetros según el comando
        const paramCounts = {
          m: [2, 2], l: [2, 2], h: [1, 1], v: [1, 1], // moveto, lineto, lineto-horizontal, lineto-vertical
          c: [6, 6], s: [4, 4], q: [4, 4], t: [2, 2],  // curve commands
          a: [7, 7], z: [0, 0]                          // arc, closepath
        };

        if (paramCounts[cmd]) {
          const params = afterCmd.split(/[\s,]+/).filter(p => p && !isNaN(p));
          if (params.length > 0 && params.length < paramCounts[cmd][0]) {
            return false;
          }
        }
      }
    }

    // Detectar rutas incompletas que terminan con números sueltos
    if (/\s\d+\s*$/.test(pathData) && pathData.split(/\s+/).pop().match(/^\d+$/)) {
      return false;
    }
  }

  return true;
}

for (const [iconName, iconData] of Object.entries(icons)) {
  const svgBody = iconData.body || '';

  if (!isValidSvgBody(svgBody)) {
    continue;
  }

  const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">${svgBody}</svg>`;

  map[iconName] = svg;
  options.push({
    name: toTitle(iconName),
    class: iconName,
    category: inferCategory(iconName),
    source: 'material-symbols'
  });
}

options.sort((a, b) => a.name.localeCompare(b.name, 'es'));

const output =
  '/* Auto-generated from @iconify-json/material-symbols */\n' +
  'window.MATERIAL_SYMBOLS_MAP = ' + JSON.stringify(map) + ';\n' +
  'window.MATERIAL_SYMBOLS_OPTIONS = ' + JSON.stringify(options) + ';\n';

fs.writeFileSync(outFile, output);
console.log(`Generado ${outFile} con ${Object.keys(icons).length} iconos Material Symbols.`);
