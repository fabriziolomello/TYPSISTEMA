<?php
// app/controllers/base_datos/productos/ejemplo_importar.php

require_once __DIR__ . '/../../../config/seguridad.php';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="ejemplo_importar_productos.csv"');

$out = fopen('php://output', 'w');

// BOM para que Excel abra bien el UTF-8
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

// Cabecera
fputcsv($out, ['Nombre', 'Código producto', 'Categoría', 'Sub Categoría', 'Proveedor', 'Costo', 'Precio minorista', 'Precio mayorista', 'Color', 'Talle', 'Código variante', 'Stock', 'Estado'], ';');

// Ejemplos
$ejemplos = [
    ['Pelota Yuka N5',    '7891234567890', 'Fútbol',      '',         'Proveedor Ejemplo', '5000,00',  '8500,00',  '7000,00',  '',      '',    '',              '10', 'Activo'],
    ['Remera deportiva',  '',              'Indumentaria', 'Remeras',  '',                  '2000,00',  '3500,00',  '3000,00',  'Rojo',  'S',   '',              '5',  'Activo'],
    ['Remera deportiva',  '',              'Indumentaria', 'Remeras',  '',                  '2000,00',  '3500,00',  '3000,00',  'Rojo',  'M',   '',              '8',  'Activo'],
    ['Remera deportiva',  '',              'Indumentaria', 'Remeras',  '',                  '2000,00',  '3500,00',  '3000,00',  'Azul',  'M',   '',              '3',  'Activo'],
    ['Zapatillas Running','7899876543210', 'Calzado',     'Running',  'Proveedor Ejemplo', '15000,00', '25000,00', '22000,00', '',      '40',  '7899876543211', '2',  'Activo'],
    ['Zapatillas Running','7899876543210', 'Calzado',     'Running',  'Proveedor Ejemplo', '15000,00', '25000,00', '22000,00', '',      '41',  '7899876543212', '4',  'Activo'],
];

foreach ($ejemplos as $fila) {
    fputcsv($out, $fila, ';');
}

fclose($out);
exit;
