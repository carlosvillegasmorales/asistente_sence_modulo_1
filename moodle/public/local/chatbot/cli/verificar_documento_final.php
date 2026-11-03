<?php
/**
 * Verifica el documento final en Word con el diseño del ejemplo del cliente («Diseño Final Validado - Ejemplo - Interfases»,
 * pedido del 02-11-2026): titulos de etapa, indice, portada, encabezado y pie, secciones horizontales para las tablas
 * anchas, estilos de tabla, bandas de sesion, formula y datos de la portada.
 *
 * Uso:  php cli/verificar_documento_final.php
 */

require_once(__DIR__ . '/_puro.php');
require_once(__DIR__ . '/../classes/guardrails.php');
require_once(__DIR__ . '/../classes/exante.php');
require_once(__DIR__ . '/../classes/diseno.php');
require_once(__DIR__ . '/../classes/diagrama.php');
require_once(__DIR__ . '/../classes/documento_final.php');

use local_chatbot\diagrama;
use local_chatbot\diseno;
use local_chatbot\documento_final;

$fallidos = 0;
$total = 0;

function local_chatbot_verificar(bool $condicion, string $descripcion): void {
    global $fallidos, $total;
    $total++;
    if (!$condicion) {
        echo "  FALLA  {$descripcion}\n";
        $fallidos++;
    }
}

// --- Piezas puras ---
$e = documento_final::parse_stage_title('Etapa 3: Secuencia instruccional');
local_chatbot_verificar($e === ['etiqueta' => 'ETAPA 3', 'titulo' => 'Secuencia instruccional', 'indice' => 'Etapa 3: Secuencia instruccional',
    'numero' => 3], 'titulo de etapa: etiqueta, titulo, texto del indice y numero');
local_chatbot_verificar(documento_final::parse_stage_title('ETAPA 5 - Plan de evaluación')['titulo'] === 'Plan de evaluación'
    && documento_final::parse_stage_title('Etapa 2')  === null, 'el titulo de etapa admite guion y exige texto');
local_chatbot_verificar(documento_final::parse_stage_title('Anexo: Evaluación técnica del diseño')
    === ['etiqueta' => 'ANEXO', 'titulo' => 'Evaluación técnica del diseño', 'indice' => 'Anexo: Evaluación técnica del diseño', 'numero' => 0],
    'el anexo se reconoce como titulo de nivel de etapa');
local_chatbot_verificar(documento_final::parse_stage_title('PARTE 3: SECUENCIA INSTRUCCIONAL') === ['etiqueta' => 'ETAPA 3',
    'titulo' => 'Secuencia instruccional', 'indice' => 'Etapa 3: Secuencia instruccional', 'numero' => 3],
    'las «PARTE N» del consolidado son las etapas, con el titulo en minuscula normal');
local_chatbot_verificar(documento_final::sentence_case('SUB-FASE 2.1: FUNDAMENTACIÓN TÉCNICA EN AULA (120 MINUTOS)')
    === 'Sub-fase 2.1: Fundamentación técnica en aula (120 minutos)'
    && documento_final::sentence_case('SECCIÓN II: DEPENDENCIA Y CUIDADOR/A (CEs 1.2 y 1.3)') === 'Sección II: Dependencia y cuidador/a (CEs 1.2 y 1.3)'
    && documento_final::sentence_case('NORMAS MCTP Y EPP DEL TALLER') === 'Normas MCTP y EPP del taller',
    'un titulo en mayusculas pasa a minuscula normal y respeta siglas, numeros romanos y lo que sigue a los dos puntos');
local_chatbot_verificar(documento_final::sentence_case('Síntesis diagnóstica') === 'Síntesis diagnóstica'
    && documento_final::sentence_case('MCTP') === 'MCTP', 'un titulo que no esta en mayusculas, o una sigla sola, no cambia');
local_chatbot_verificar(documento_final::number_label('3') === '3.' && documento_final::number_label('3.1') === '3.1'
    && documento_final::number_label('') === '', 'un numero solo se muestra con punto y uno compuesto sin el');
local_chatbot_verificar(documento_final::label_split('Pregunta 1.1: B (2 pts).') === ['Pregunta 1.1', 'B (2 pts).']
    && documento_final::label_split('Texto sin rótulo.') === ['', 'Texto sin rótulo.']
    && documento_final::label_split('☐ B: Vestuario') === ['', '☐ B: Vestuario']
    && documento_final::label_split('Comentarios de la persona evaluadora sobre el desempeño observado en la estación:') === [
        'Comentarios de la persona evaluadora sobre el desempeño observado en la estación', ''],
    'el rotulo de un «Rótulo: valor» se separa del valor');
local_chatbot_verificar(documento_final::is_wide(7, 50) && documento_final::is_wide(6, 696) && !documento_final::is_wide(6, 117)
    && !documento_final::is_wide(5, 900), 'va en horizontal la tabla de siete columnas o la de seis con mucho texto');
local_chatbot_verificar(documento_final::cell_height(0, 2000) === 390 && documento_final::cell_height(900, 2000) > documento_final::cell_height(900, 6000)
    && documento_final::cell_height(100, 2000, 3) > documento_final::cell_height(100, 2000), 'el alto estimado de una celda crece con el texto y los parrafos');
local_chatbot_verificar(documento_final::parse_stage_title('1.1 Síntesis diagnóstica') === null, 'una seccion no es un titulo de etapa');
local_chatbot_verificar(documento_final::split_number('1.2 Resultado de la validación') === ['1.2', 'Resultado de la validación']
    && documento_final::split_number('2.7.3. Detalle') === ['2.7.3', 'Detalle']
    && documento_final::split_number('Fortalezas') === ['', 'Fortalezas'], 'la numeracion de un titulo se separa del texto');
local_chatbot_verificar(documento_final::parse_session('SESIÓN 2: Demostración guiada (180 minutos)') === ['SESIÓN 2', 'Demostración guiada (180 minutos)']
    && documento_final::parse_session('Sesión 3 Circuito práctico') === ['SESIÓN 3', 'Circuito práctico']
    && documento_final::parse_session('Sesiones de apoyo') === null, 'banda de sesion: «SESIÓN N» y el resto');
local_chatbot_verificar(documento_final::is_formula('Porcentaje de logro (%) = (Puntaje total obtenido ÷ Puntaje ideal total) × 100')
    && !documento_final::is_formula('El puntaje = 5 puntos.') && !documento_final::is_formula('Texto con × sin igual'),
    'una formula de calculo se reconoce por el igual y el × o ÷');
$w = documento_final::column_widths([8, 60, 20, 12], 9640);
local_chatbot_verificar(array_sum($w) === 9640 && min($w) >= 700 && $w[1] === max($w), 'los anchos de columna suman el ancho util y respetan el minimo');
local_chatbot_verificar(array_sum(documento_final::column_widths([1, 1, 1], 13240)) === 13240 && documento_final::column_widths([], 100) === [],
    'anchos iguales y sin columnas');
local_chatbot_verificar(documento_final::esc("a<b>&\x01") === 'a&lt;b&gt;&amp;', 'el texto se escapa y pierde los caracteres de control');

// --- Bloques preformateados del consolidado: tablas y arboles dibujados, fichas de instrumentos ---
$pad = static function(string $t, int $n): string {
    return $t . str_repeat(' ', max(0, $n - mb_strlen($t)));
};
$caja = implode("\n", [
    '┌' . str_repeat('─', 41) . '┐',
    '│' . $pad('  CIRCUITO DE ESTACIONES', 41) . '│',
    '├' . str_repeat('─', 20) . '┬' . str_repeat('─', 20) . '┤',
    '│' . $pad(' ESTACIÓN A', 20) . '│' . $pad(' ESTACIÓN B', 20) . '│',
    '│' . $pad(' • Aseo en cama', 20) . '│' . $pad(' • Explicación', 20) . '│',
    // El texto corre la barra un caracter: el corte sigue a la barra, no a la posicion del cruce.
    '│' . ' • Acondicionamientos' . '│' . $pad(' • Traslado', 19) . '│',
    '│' . $pad('', 20) . '│' . $pad(' (CE 1.5)', 20) . '│',
    '│' . $pad(' Coevalua-', 20) . '│' . $pad('', 20) . '│',
    '│' . $pad(' ción entre pares', 20) . '│' . $pad('', 20) . '│',
    '├' . str_repeat('─', 20) . '┴' . str_repeat('─', 20) . '┤',
    '│' . $pad(' ESTACIÓN D — Aula', 41) . '│',
    '└' . str_repeat('─', 41) . '┘',
]);
local_chatbot_verificar(diagrama::art_rows($caja) === [
    [['CIRCUITO DE ESTACIONES']],
    [['ESTACIÓN A', '• Aseo en cama', '• Acondicionamientos', 'Coevaluación entre pares'], ['ESTACIÓN B', '• Explicación', '• Traslado (CE 1.5)']],
    [['ESTACIÓN D — Aula']],
], 'tabla dibujada: filas por los bordes, columnas por los cruces, parrafos por celda y palabras cortadas con guion unidas');
$th = (string) diagrama::art_table_html($caja);
local_chatbot_verificar(strpos($th, '<th colspan="2"><p><strong>CIRCUITO DE ESTACIONES</strong></p></th>') !== false
    && strpos($th, '<ul><li>Aseo en cama</li><li>Acondicionamientos</li></ul>') !== false && strpos($th, '<td colspan="2"><p>ESTACIÓN D') !== false,
    'la tabla dibujada vuelve a ser tabla: el titulo como encabezado, viñetas como lista y la fila final a todo el ancho');
local_chatbot_verificar(diagrama::art_table_html("┌────┐\n│ Caja │\n└────┘") === null && diagrama::art_rows("Texto\n│ a │") === null,
    'una caja sola o un texto que no es tabla no se convierten');
$arbol = "[MOMENTO 1: INICIO] (60 min)\n   ├── Encuadre instruccional\n   └── Lluvia de ideas (CE 1.1)\n           ↓\n"
    . "[MOMENTO 2: DESARROLLO] (420 min)\n   └── Sub-momento 2.3: Taller en estaciones\n           ├── Estación A: Aseo en cama\n"
    . "           └── Estación B: Vestuario";
local_chatbot_verificar(diagrama::tree_items($arbol) === [[0, 'MOMENTO 1: INICIO (60 min)'], [1, 'Encuadre instruccional'],
    [1, 'Lluvia de ideas (CE 1.1)'], [0, 'MOMENTO 2: DESARROLLO (420 min)'], [1, 'Sub-momento 2.3: Taller en estaciones'],
    [2, 'Estación A: Aseo en cama'], [2, 'Estación B: Vestuario']], 'arbol dibujado: cada rama con su nivel, sin flechas ni corchetes');
local_chatbot_verificar(diagrama::tree_items($caja) === null && diagrama::tree_items("uno\ndos") === null, 'una tabla o un texto no son arboles');
$ficha = "==========\nINSTITUTO / OTEC: Capacitación Ejemplo SpA\nEVALUACIÓN DE DESEMPEÑO - LISTA DE COTEJO\n\n"
    . 'Nombre de la persona participante: ' . str_repeat('_', 30) . "\nRUT: ____________  Fecha: ________\n"
    . "- Puntaje Obtenido: _____ puntos.   - Porcentaje de Logro: _____ %\n[  ] APROBADO: cumple\n"
    . 'Comentarios: ' . str_repeat('_', 60) . "\n" . str_repeat('_', 40) . "\n==========";
$fl = documento_final::form_lines($ficha);
local_chatbot_verificar(array_column($fl, 'tipo') === ['campo', 'titulo', 'espacio', 'campo', 'campo', 'vineta', 'vineta', 'casilla',
    'campo', 'linea', 'linea'], 'ficha: separadores fuera y cada linea con su tipo (campo, titulo, viñeta, casilla, linea para escribir)');
local_chatbot_verificar($fl[0]['partes'] === [['INSTITUTO / OTEC', 'Capacitación Ejemplo SpA']]
    && $fl[4]['partes'] === [['RUT', '____________'], ['Fecha', '________']] && $fl[6]['partes'] === [['Porcentaje de Logro', '_____ %']]
    && $fl[7]['partes'] === [['APROBADO', 'cumple']] && $fl[8]['partes'] === [['Comentarios', '']],
    'ficha: varios campos en una linea, dos viñetas en una linea, la casilla y el rotulo de una linea larga para escribir');

// --- Datos de la portada ---
$c = diseno::final_cover(['plan' => 'Servicios de cuidados (PF0960).', 'modulo' => 'Módulo N.º 1: Asistencia (MA02517)', 'aprendizaje' => '1. Asistir a las personas mayores.',
    'tiempo_experiencia' => '540'], 'Taller práctico', ['total' => 35, 'banda' => ['texto' => 'Aprobado con alto estándar técnico']], '02-11-2026');
local_chatbot_verificar($c['titulo'] === 'Taller práctico' && $c['etiqueta'] === 'Diseño final validado'
    && $c['lineas'][0] === ['Plan formativo', 'Servicios de cuidados (PF0960)']
    && $c['lineas'][3] === ['Duración de la experiencia', '540 minutos']
    && $c['lineas'][4] === ['Validación técnica', '35 / 36 · Aprobado con alto estándar técnico · 02-11-2026'],
    'portada: plan, modulo, aprendizaje, duracion y resultado de la evaluacion');
local_chatbot_verificar(count(diseno::final_cover([], 'T', [], '')['lineas']) === 0, 'sin ficha ni evaluacion la portada no inventa datos');

// --- El documento ---
[, $tablas] = diagrama::extract("```diagrama\ntitulo: Rotación de subgrupos\nA / uno\nB / dos\n```");
$ancha = '<table><thead><tr><th>Aprendizaje</th><th>Criterio</th><th>Evidencia</th><th>Instrumento</th><th>Indicador</th><th>Momento</th><th>Responsable</th></tr></thead><tbody>'
    . '<tr><td>1. Asistir</td><td>1.1 Reconoce</td><td>Pauta</td><td>Cuestionario</td><td>Identifica 3 derechos</td><td>Sesión 1</td><td>Facilitadora</td></tr>'
    . '<tr><td>1. Asistir</td><td>1.2 Identifica</td><td>Hoja</td><td>Lista de cotejo</td><td>Diferencia</td><td>Sesión 1</td><td>Pares</td></tr></tbody></table>';
$html = '<h1>Etapa 1: Análisis diagnóstico</h1><h2>1.1 Síntesis diagnóstica</h2><p>Texto con <strong>negrita</strong> y <em>cursiva</em>.</p>'
    . '<ol><li><strong>Primero:</strong> uno</li><li>Segundo</li></ol><ul><li>Viñeta<ul><li>Subviñeta</li></ul></li></ul><hr>'
    . '<h1>Etapa 3: Secuencia instruccional</h1><h3>SESIÓN 1: Fundamentos (180 minutos)</h3><p><strong>Porcentaje de logro (%) = (A ÷ B) × 100</strong></p>'
    . '<table><thead><tr><th>Sesión</th><th>Tiempo</th></tr></thead><tbody><tr><td>Sesión 1</td><td>180 min</td></tr><tr><td>Sesión 2</td><td>180 min</td></tr></tbody></table>'
    . "<h2>3.3 Matriz de coherencia instruccional</h2>" . $ancha . $tablas[0]
    . '<table><thead><tr><th>Vacío de información</th><th>Solución adoptada</th></tr></thead><tbody><tr><td>Vacío uno</td><td>Solución uno</td></tr></tbody></table>'
    . '<pre>┌──────┐' . "\n" . '│ Caja │' . "\n" . '└──────┘</pre>'
    . '<h1>Anexo: Evaluación técnica del diseño</h1><table><thead><tr><th>Criterio</th><th>Puntaje</th></tr></thead><tbody><tr><td>1. Coherencia</td><td>4 / 4</td></tr>'
    . '<tr><th>Puntaje total</th><th>35 / 36</th></tr></tbody></table>';
$meta = ['etiqueta' => 'Diseño final validado', 'titulo' => 'Taller práctico de simulación', 'subtitulo' => 'Diseño por competencias',
    'lineas' => [['Plan formativo', 'Servicios (PF0960)'], ['Validación técnica', '35 / 36']]];
$logo = __DIR__ . '/../pix/logo_interfases.png';
$ruta = sys_get_temp_dir() . '/m2_final_' . getmypid() . '.docx';
local_chatbot_verificar(documento_final::build($ruta, $meta, $html, $logo), 'el documento final se genera');

$zip = new ZipArchive();
$zip->open($ruta);
$partes = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $partes[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
}
$zip->close();
@unlink($ruta);

$malos = [];
foreach ($partes as $nombre => $contenido) {
    if (substr($nombre, -4) === '.xml' || substr($nombre, -5) === '.rels') {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        if (!$dom->loadXML($contenido)) {
            $malos[] = $nombre;
        }
        libxml_clear_errors();
    }
}
local_chatbot_verificar($malos === [], 'todas las partes del Word son XML bien formado');
foreach (['[Content_Types].xml', '_rels/.rels', 'word/document.xml', 'word/styles.xml', 'word/numbering.xml', 'word/settings.xml',
        'word/header1.xml', 'word/header2.xml', 'word/footer1.xml', 'word/media/logo.png', 'word/_rels/document.xml.rels',
        'word/_rels/header1.xml.rels'] as $parte) {
    local_chatbot_verificar(isset($partes[$parte]), 'el paquete trae ' . $parte);
}
$doc = $partes['word/document.xml'] ?? '';
local_chatbot_verificar(substr_count($doc, '<w:sectPr>') === 3 && substr_count($doc, 'w:orient="landscape"') === 1,
    'la tabla de siete columnas va en una seccion horizontal entre dos verticales');
local_chatbot_verificar(substr_count($doc, '<w:titlePg/>') === 1 && strpos($doc, '<w:titlePg/>') < strpos($doc, '</w:sectPr>'),
    'solo la primera seccion tiene primera pagina distinta (la portada no lleva encabezado)');
local_chatbot_verificar(substr_count($doc, '<w:pageBreakBefore/>') === 3 && substr_count($doc, 'w:val="Heading1"') === 3,
    'cada etapa y el anexo empiezan en pagina nueva');
local_chatbot_verificar(strpos($doc, 'ETAPA 1') !== false && strpos($doc, 'ETAPA 3') !== false && strpos($doc, 'ANEXO') !== false,
    'los titulos de etapa llevan su etiqueta');
preg_match_all('/<w:bookmarkStart w:id="\d+" w:name="(toc_\d+)"/', $doc, $marcas);
preg_match_all('/w:anchor="(toc_\d+)"/', $doc, $anclas);
preg_match_all('/PAGEREF (toc_\d+)/', $doc, $campos);
local_chatbot_verificar(count($marcas[1]) === 5 && $anclas[1] === $marcas[1] && $campos[1] === $marcas[1],
    'el indice tiene una entrada por cada titulo de etapa y de seccion, con su enlace y su numero de pagina');
local_chatbot_verificar(strpos($doc, 'Tabla de contenidos') !== false && strpos($doc, 'Tabla de contenidos') < strpos($doc, 'ETAPA 1'),
    'el indice va entre la portada y la primera etapa');
local_chatbot_verificar(strpos($doc, 'Taller práctico de simulación') !== false && strpos($doc, 'DISEÑO FINAL VALIDADO') !== false
    && strpos($doc, 'Plan formativo: ') !== false && strpos($doc, 'r:embed="rId4"') !== false, 'portada con logo, etiqueta, titulo y datos');
local_chatbot_verificar(strpos($doc, 'w:fill="0B2447"') !== false && strpos($doc, 'w:val="FFFFFF"') !== false && strpos($doc, '<w:tblHeader/>') !== false,
    'las tablas llevan el encabezado azul oscuro con texto blanco, repetido en cada pagina');
local_chatbot_verificar(strpos($doc, 'w:fill="EAEFF6"') !== false && strpos($doc, 'w:fill="F7F9FC"') !== false,
    'primera columna sombreada y filas alternadas');
local_chatbot_verificar(strpos($doc, 'SESIÓN 1  ') !== false && strpos($doc, 'w:sz="36" w:space="8"') !== false,
    'la sesion va en una banda con barra azul');
local_chatbot_verificar(strpos($doc, 'Porcentaje de logro (%) = (A ÷ B) × 100') !== false && strpos($doc, '<w:jc w:val="center"/>') !== false,
    'la formula va en un recuadro centrado');
local_chatbot_verificar(strpos($doc, '➜') !== false, 'la tabla «Vacío / Solución» lleva su columna de flecha');
preg_match_all('/<w:tcW w:w="(\d+)"/', $doc, $tcw);
local_chatbot_verificar($tcw[1] !== [] && min(array_map('intval', $tcw[1])) >= 600, 'ninguna celda queda sin ancho (la columna «Solución» incluida)');
preg_match_all('/<w:tbl><w:tblPr><w:tblW w:w="(\d+)"[^>]*\/>.*?<w:tblGrid>(.*?)<\/w:tblGrid>/s', $doc, $grillas, PREG_SET_ORDER);
$cuadran = $grillas !== [];
foreach ($grillas as $g) {
    preg_match_all('/w:w="(\d+)"/', $g[2], $cols);
    $cuadran = $cuadran && array_sum(array_map('intval', $cols[1])) === (int) $g[1];
}
local_chatbot_verificar($cuadran, 'en cada tabla las columnas suman exactamente el ancho de la tabla');
$sect1 = strpos($doc, '<w:sectPr>');
local_chatbot_verificar($sect1 !== false && strrpos($doc, 'Matriz de coherencia') > $sect1 && strrpos($doc, 'Matriz de coherencia') < strpos($doc, 'w:orient="landscape"'),
    'el titulo de la tabla ancha pasa a la pagina horizontal junto con ella');
$minimos = documento_final::column_widths([8, 60, 8], 9640, [700, 700, 1435]);
local_chatbot_verificar($minimos[2] >= 1435 && array_sum($minimos) === 9640, 'una columna angosta no baja del ancho de su palabra mas larga');
$apretado = documento_final::column_widths([1, 1, 1], 3000, [2000, 2000, 2000]);
local_chatbot_verificar(array_sum($apretado) === 3000 && min($apretado) > 0, 'si los minimos no caben, se reducen y la tabla no se sale de la pagina');
local_chatbot_verificar(strpos($doc, 'w:fill="E8F0F7"') !== false && strpos($doc, '→') !== false, 'el diagrama de cajas y flechas conserva sus colores');
local_chatbot_verificar(preg_match('/<w:p>(?:(?!<\/w:p>).)*Rotación de subgrupos(?:(?!<\/w:p>).)*<\/w:p>/s', $doc, $tit) === 1 && strpos($tit[0], '<w:keepNext/>') !== false,
    'el titulo de un diagrama queda en la misma pagina que el diagrama');
local_chatbot_verificar(preg_match('/[│─┌┐└┘▼]/u', $doc) === 0 && strpos($doc, 'Caja') !== false, 'un dibujo de caracteres sale como lista, sin trazos');
local_chatbot_verificar(strpos($doc, '<w:t xml:space="preserve">1.</w:t>') !== false && strpos($doc, '<w:ilvl w:val="1"/><w:numId w:val="1"/>') !== false,
    'listas: numerada con el numero en azul y viñetas de dos niveles');
local_chatbot_verificar(strpos($doc, '<w:t xml:space="preserve">1.1  </w:t>') !== false, 'los titulos de seccion llevan el numero aparte, en azul');
$h1 = $partes['word/header1.xml'] ?? '';
$h2 = $partes['word/header2.xml'] ?? '';
local_chatbot_verificar(strpos($h1, 'w:pos="9640"') !== false && strpos($h2, 'w:pos="13240"') !== false
    && strpos($h1, 'Diseño de experiencia de aprendizaje · Diseño final validado') !== false,
    'el encabezado lleva el logo y el texto a la derecha, con el ancho de cada orientacion');
local_chatbot_verificar(strpos($partes['word/footer1.xml'] ?? '', 'PAGE') !== false && strpos($partes['word/footer1.xml'] ?? '', 'NUMPAGES') !== false,
    'el pie dice «Página X de Y»');
local_chatbot_verificar(strpos($partes['word/settings.xml'] ?? '', '<w:updateFields w:val="true"/>') !== false,
    'Word actualiza el indice y los numeros de pagina al abrir');
local_chatbot_verificar(strpos($doc, 'w:rsid') === false && strpos($doc, "\x01") === false, 'sin restos de edicion ni caracteres de control');

// --- La estructura real del consolidado: titulo general, «DATOS GENERALES», «PARTE N», secciones numeradas «1.» ---
$cotejo = '<table><thead><tr><th>N.º</th><th>Indicador</th><th>Criterio</th><th>Cumple</th><th>No cumple</th><th>Observaciones</th></tr></thead><tbody>'
    . '<tr><td><strong>DIMENSIÓN 1: COMUNICACIÓN</strong></td><td></td><td></td><td></td><td></td><td></td></tr>'
    . '<tr><td>1</td><td>Saluda a la persona mayor.</td><td>CE 1.5</td><td></td><td></td><td></td></tr></tbody></table>';
$html2 = '<h1>DOCUMENTO CONSOLIDADO DE DISEÑO INSTRUCCIONAL POR COMPETENCIAS</h1><hr>'
    . '<h1>DATOS GENERALES DE LA EXPERIENCIA DE APRENDIZAJE</h1><p>Datos.</p>'
    . '<h1>PARTE 2: DISEÑO DE LA EXPERIENCIA</h1><h2>1. Propósito</h2><p>Texto.</p>'
    . "<p>Momentos de la experiencia:\n1. <strong>Inicio:</strong> encuadre.\n   * <em>Estación A:</em> aseo.\n2. <strong>Cierre:</strong> plenaria.</p>"
    . '<h2>3. Recursos</h2><h3>3.1. EQUIPAMIENTO TÉCNICO</h3><h3>Instrucciones generales:</h3>'
    . '<ul><li>[ ] A) Derecho a la alimentación.</li><li>Aspectos logrados: ' . str_repeat('_', 40) . '</li></ul>'
    . '<pre>' . htmlspecialchars($arbol) . '</pre><pre>' . htmlspecialchars($caja) . '</pre><pre>' . htmlspecialchars($ficha) . '</pre>' . $cotejo;
$ruta3 = sys_get_temp_dir() . '/m2_final3_' . getmypid() . '.docx';
local_chatbot_verificar(documento_final::build($ruta3, $meta, $html2, $logo), 'el documento con la estructura del consolidado se genera');
$z3 = new ZipArchive();
$z3->open($ruta3);
$doc2 = (string) $z3->getFromName('word/document.xml');
$z3->close();
@unlink($ruta3);
libxml_use_internal_errors(true);
local_chatbot_verificar((new DOMDocument())->loadXML($doc2) === true, 'el documento del consolidado es XML bien formado');
libxml_clear_errors();
local_chatbot_verificar(strpos($doc2, 'DOCUMENTO CONSOLIDADO') === false && substr_count($doc2, 'w:val="Heading1"') === 2,
    'el titulo general del consolidado no se repite (ya esta en la portada)');
local_chatbot_verificar(strpos($doc2, '>Datos generales de la experiencia de aprendizaje<') !== false
    && strpos($doc2, '>ETAPA 2<') !== false && strpos($doc2, '>Diseño de la experiencia<') !== false
    && strpos($doc2, '>Etapa 2: Diseño de la experiencia<') !== false, '«PARTE 2» sale como «ETAPA 2», con los titulos en minuscula normal');
local_chatbot_verificar(strpos($doc2, '>2.1  <') !== false && strpos($doc2, '>2.3  <') !== false && strpos($doc2, '>2.1 Propósito<') !== false
    && strpos($doc2, '>2.3.1  <') !== false && strpos($doc2, '>Equipamiento técnico<') !== false,
    'las secciones «1.» y «3.1.» de la etapa 2 se numeran «2.1» y «2.3.1», como en el ejemplo');
local_chatbot_verificar(strpos($doc2, 'Instrucciones generales:') === false && strpos($doc2, '>Instrucciones generales<') !== false,
    'los titulos pierden los dos puntos del final');
local_chatbot_verificar(preg_match('/<w:pStyle w:val="ListParagraph"\/><w:keepLines\/><w:numPr><w:ilvl w:val="1"\/>(?:(?!<\/w:p>).)*>Estación A:<(?:(?!<\/w:p>).)*> aseo\.</s', $doc2) === 1
    && strpos($doc2, '>Inicio:<') !== false && preg_match('/\*\s/', $doc2) === 0,
    'una lista escrita dentro de un parrafo se arma como lista, con su nivel');
local_chatbot_verificar(strpos($doc2, '☐ A) Derecho a la alimentación.') !== false, 'las casillas «[ ]» se muestran como casillas');
local_chatbot_verificar(preg_match('/<w:jc w:val="left"\/><\/w:pPr>(?:(?!<\/w:p>).)*Aspectos logrados/s', $doc2) === 1,
    'un item con una linea para escribir no se justifica (no separa sus palabras)');
local_chatbot_verificar(strpos($doc2, '>Momento 1: Inicio (60 min)<') !== false && strpos($doc2, '>Sub-momento 2.3: <') !== false,
    'el arbol dibujado sale como lista: titulos en negrita y ramas con su rotulo');
local_chatbot_verificar(preg_match('/[│─┌┐└┘├┤┬┴┼↓]/u', $doc2) === 0 && strpos($doc2, '<w:gridSpan w:val="2"/>') !== false
    && strpos($doc2, '>Coevaluación entre pares<') !== false, 'la tabla dibujada sale como tabla de Word, sin trazos');
local_chatbot_verificar(strpos($doc2, '<w:top w:val="single" w:sz="18" w:space="0" w:color="2E5A88"/>') !== false
    && strpos($doc2, '>INSTITUTO / OTEC: <') !== false && strpos($doc2, '>☐<') !== false && strpos($doc2, '=====') === false,
    'el encabezado del instrumento sale como ficha, con rotulos en negrita y casillas, sin las lineas de =====');
local_chatbot_verificar(strpos($doc2, '<w:gridSpan w:val="6"/>') !== false && strpos($doc2, 'w:orient="landscape"') === false,
    'la fila de grupo («DIMENSIÓN 1») va a todo el ancho y la lista de cotejo de seis columnas cortas queda en vertical');

// Dos tablas anchas separadas solo por un titulo comparten la seccion horizontal; las filas altas pueden partirse.
$alta = '<table><thead><tr><th>Actividad</th><th>Criterios</th><th>Descripción</th><th>Recursos</th><th>Evidencias</th></tr></thead><tbody>';
for ($i = 1; $i <= 3; $i++) {
    $alta .= '<tr><td>Actividad ' . $i . '</td><td>CE 1.' . $i . '</td><td>' . str_repeat('Descripción extensa de la actividad. ', 30)
        . '</td><td>Recursos</td><td>Evidencias</td></tr>';
}
$alta .= '</tbody></table>';
$ruta4 = sys_get_temp_dir() . '/m2_final4_' . getmypid() . '.docx';
documento_final::build($ruta4, $meta, '<h1>Etapa 1: A</h1><p>x</p>' . $ancha . '<h2>1.2 Otra matriz</h2>' . $ancha . '<p>Fin.</p>' . $alta, '');
$z4 = new ZipArchive();
$z4->open($ruta4);
$doc4 = (string) $z4->getFromName('word/document.xml');
$z4->close();
@unlink($ruta4);
local_chatbot_verificar(substr_count($doc4, '<w:sectPr>') === 3 && substr_count($doc4, 'w:orient="landscape"') === 1,
    'dos tablas anchas separadas solo por un titulo van en la misma seccion horizontal (sin pagina vertical vacia)');
local_chatbot_verificar(strpos($doc4, '<w:tr><w:tc>') !== false && strpos($doc4, '<w:trPr><w:cantSplit/><w:tblHeader/></w:trPr>') !== false,
    'en una tabla de filas altas las filas pueden partirse entre paginas y el encabezado se repite');
// Etapa 3 de Roberto (02-11): cada fase con su texto corto entre dos tablas anchas dejaba una pagina vertical casi vacia.
$ruta10 = sys_get_temp_dir() . '/m2_final10_' . getmypid() . '.docx';
documento_final::build($ruta10, $meta, '<h1>Etapa 3: Secuencia</h1><p>Introducción.</p><h2>1. Fase de inicio</h2>' . $ancha
    . '<p>Subtotal de la fase de inicio: 90 minutos.</p><h2>2. Fase de desarrollo</h2><p><strong>Alcance:</strong> práctica guiada.</p>'
    . $ancha . '<p>' . str_repeat('Texto largo de cierre de la etapa con su análisis. ', 30) . '</p>' . $ancha, '');
$z10 = new ZipArchive();
$z10->open($ruta10);
$doc10 = (string) $z10->getFromName('word/document.xml');
$z10->close();
@unlink($ruta10);
local_chatbot_verificar(substr_count($doc10, 'w:orient="landscape"') === 2 && substr_count($doc10, '<w:sectPr>') === 4,
    'un texto corto entre dos tablas anchas se queda en la pagina horizontal; uno largo vuelve a la vertical');

// --- Casos de la revision de codigo ---
local_chatbot_verificar(documento_final::parse_stage_title('Parte 2.1 Resultados') === null
    && documento_final::parse_stage_title('Etapa 12.3 Detalle') === null && documento_final::parse_stage_title('Etapa 3. Secuencia')['numero'] === 3,
    '«Parte 2.1» es una seccion, no una etapa');
local_chatbot_verificar(documento_final::label_split('09:00 - Bienvenida') === ['', '09:00 - Bienvenida']
    && documento_final::label_split('Horario 10:30: Taller') === ['Horario 10:30', 'Taller'], 'la hora no se toma como rotulo');
$giro = "┌──────┬──────┐\n│ A    │ B    │\n├──┬───┼──────┤\n│A1│A2 │ B2   │\n└──┴───┴──────┘";
$tg = (string) diagrama::art_table_html($giro);
local_chatbot_verificar(strpos($tg, '<td colspan="2"><p>A</p></td><td><p>B</p></td>') !== false
    && strpos($tg, '<td><p>A1</p></td><td><p>A2</p></td><td><p>B2</p></td>') !== false,
    'tabla dibujada: cada celda abarca las columnas entre sus bordes, aunque la division este a la izquierda');
$construir = static function(string $html) use ($meta): string {
    $ruta = sys_get_temp_dir() . '/m2_final_r_' . getmypid() . '_' . md5($html) . '.docx';
    documento_final::build($ruta, $meta, $html, '');
    $z = new ZipArchive();
    $z->open($ruta);
    $doc = (string) $z->getFromName('word/document.xml');
    $z->close();
    @unlink($ruta);
    return $doc;
};
$d5 = $construir('<h1>Etapa 1: A</h1><p>x</p>' . $ancha);
local_chatbot_verificar(substr_count($d5, '<w:sectPr>') === 2 && preg_match('/<\/w:p><w:sectPr>.*w:orient="landscape".*<\/w:sectPr><\/w:body>/s', $d5) === 1,
    'un documento que termina en una tabla ancha no deja una pagina vertical vacia al final');
$d6 = $construir('<h1>Etapa 1: A</h1><p>x</p><h1>Etapa 2: Matrices</h1><h2>1. Matriz</h2>' . $ancha);
$corte = strpos($d6, 'w:orient="landscape"');
local_chatbot_verificar($corte !== false && strpos($d6, '>ETAPA 2<') > strpos($d6, '</w:sectPr></w:pPr></w:p>')
    && substr_count($d6, '<w:pageBreakBefore/>') === 1, 'el titulo de etapa que precede a una tabla ancha pasa con ella a la pagina horizontal');
$d7 = $construir('<h1>Etapa 1: A</h1><table><tr><th colspan="2">Vacío y solución</th></tr><tr><td>Vacío uno</td><td>Solución uno</td></tr></table>');
local_chatbot_verificar(strpos($d7, '➜') === false && strpos($d7, '<w:gridSpan w:val="2"/>') !== false,
    'una tabla «Vacío / Solución» con una fila a todo el ancho se arma como tabla comun');
$d8 = $construir('<h1>Etapa 1: A</h1><blockquote><p>Nota</p><ul><li>ITEM CITA</li></ul></blockquote>'
    . '<ul><li>Uno<table><tr><td>CELDA EN ITEM</td><td>b</td></tr></table></li><ul><li>SUELTA</li></ul></ul>');
local_chatbot_verificar(strpos($d8, 'ITEM CITA') !== false && strpos($d8, 'CELDA EN ITEM') !== false && strpos($d8, 'SUELTA') !== false,
    'no se pierden las listas de una cita, las tablas dentro de un item ni una lista dentro de otra sin item');

// --- Word de una etapa (interruptor «docetapa»): mismo diseño, sin portada ni indice ---
$prod = '<h2>Etapa 2: Diseño de la experiencia</h2><h3>1. Nombre de la experiencia</h3><p>Taller.</p>'
    . '<h3>7. Recursos</h3><h4>7.1. Equipamiento técnico</h4><ul><li>Cama clínica</li></ul>'
    . '<h2>Referencias consultadas</h2><ul><li>Plan Formativo PF0960</li></ul>';
local_chatbot_verificar(diseno::stage_document_html('Etapa 2: Diseño de la experiencia', $prod) === $prod
    && strpos(diseno::stage_document_html('Etapa 2: Diseño', '<p>Solo texto</p>'), '<h1>Etapa 2: Diseño</h1>') === 0,
    'el Word de la etapa usa el titulo que trae el producto, o lo agrega si no lo trae');
$ruta9 = sys_get_temp_dir() . '/m2_etapa_' . getmypid() . '.docx';
documento_final::build($ruta9, ['portada' => false, 'encabezado' => 'Etapa 2 · Curso'], $prod, $logo);
$z9 = new ZipArchive();
$z9->open($ruta9);
$e9 = (string) $z9->getFromName('word/document.xml');
$h9 = (string) $z9->getFromName('word/header1.xml');
$z9->close();
@unlink($ruta9);
local_chatbot_verificar(strpos($e9, 'Tabla de contenidos') === false && strpos($e9, '<w:titlePg/>') === false
    && strpos($e9, '<w:pageBreakBefore/>') === false && strpos($e9, 'DISEÑO FINAL VALIDADO') === false && strpos($h9, 'Etapa 2 · Curso') !== false,
    'Word de etapa: sin portada, indice ni pagina en blanco al comienzo, con el encabezado de la etapa');
local_chatbot_verificar(strpos($e9, '>ETAPA 2<') !== false && strpos($e9, '>2.1  <') !== false && strpos($e9, '>2.7  <') !== false
    && strpos($e9, '>2.7.1  <') !== false && substr_count($e9, 'w:val="Heading2"') === 3
    && substr_count($e9, 'w:val="Heading1"') === 1 && strpos($e9, '>Referencias consultadas<') !== false,
    'Word de etapa: el titulo h2 de la etapa y sus secciones h3 se numeran como en el documento final; «Referencias consultadas» queda como seccion de la etapa, sin pagina nueva');

// Sin logo el documento sale igual, solo que sin imagen.
$ruta2 = sys_get_temp_dir() . '/m2_final2_' . getmypid() . '.docx';
local_chatbot_verificar(documento_final::build($ruta2, $meta, '<h1>Etapa 1: A</h1><p>x</p>', ''), 'sin logo el documento se genera');
$z2 = new ZipArchive();
$z2->open($ruta2);
local_chatbot_verificar($z2->getFromName('word/media/logo.png') === false && strpos((string) $z2->getFromName('word/document.xml'), 'r:embed') === false,
    'sin logo no queda ninguna referencia a la imagen');
$z2->close();
@unlink($ruta2);

// --- Pedido del 03-11: numeracion que sigue y tablas anchas con su introduccion ---
// La verificacion sin numero sigue a la ultima seccion ya numerada con el numero de la etapa («3.4» -> «3.5», no «3.1»).
$d11 = $construir('<h1>Etapa 3: Secuencia instruccional</h1><h2>3.1 Estructura general</h2><p>a</p><h2>3.2 Desglose</h2><p>b</p>'
    . '<h2>3.3 Matriz</h2><p>c</p><h2>3.4 Vacíos</h2><p>d</p><h2>Verificación de calidad de la etapa</h2><p>e</p>');
local_chatbot_verificar(strpos($d11, '3.5  ') !== false && substr_count($d11, '3.1  ') === 1,
    'la verificacion sin numero sigue la numeracion de la etapa (3.5) y no repite el 3.1');
$d12 = $construir('<h1>Etapa 2: A</h1><h2>1. Uno</h2><p>a</p><h2>2. Dos</h2><p>b</p><h2>Verificación de calidad de la etapa</h2><p>e</p>');
local_chatbot_verificar(strpos($d12, '2.3  ') !== false, 'con secciones «1.», «2.» la verificacion sigue siendo la «2.3»');

// El titulo de la etapa, el de la seccion y su introduccion corta van a la pagina horizontal con la tabla ancha.
$d13 = $construir('<h1>Etapa 1: A</h1><p>texto largo de la etapa</p><h1>Etapa 5: Plan de evaluación</h1><h2>5.1 Matriz del plan</h2>'
    . '<p>La presente matriz operacionaliza el sistema evaluativo.</p><p>Se estructura una fila por cada criterio:</p>' . $ancha);
$corte13 = strpos($d13, 'w:orient="landscape"');
local_chatbot_verificar($corte13 !== false && strrpos($d13, 'Matriz del plan') > strpos($d13, '</w:sectPr></w:pPr></w:p>')
    && strpos($d13, '>ETAPA 5<') > strpos($d13, '</w:sectPr></w:pPr></w:p>') && strpos($d13, 'Se estructura una fila') > strpos($d13, '</w:sectPr></w:pPr></w:p>'),
    'la introduccion corta de una tabla ancha viaja con su titulo y el de la etapa: no queda una pagina vertical casi vacia');
// Con un texto largo antes de la tabla, el texto se queda donde esta (solo se lleva el titulo).
$largo = '<p>' . str_repeat('Texto largo de la introduccion de la seccion. ', 40) . '</p>';
$d14 = $construir('<h1>Etapa 5: Plan</h1><h2>5.1 Matriz</h2>' . $largo . '<h2>5.2 Otra</h2>' . $ancha);
local_chatbot_verificar(strpos($d14, 'Texto largo de la introduccion') < strpos($d14, '</w:sectPr></w:pPr></w:p>')
    && strpos($d14, '5.2  ') > strpos($d14, '</w:sectPr></w:pPr></w:p>'),
    'un texto largo antes de la tabla no se mueve a la pagina horizontal; solo el titulo que la presenta');
// Un parrafo suelto sin titulo no se lleva: la tabla va sola.
$d15 = $construir('<h1>Etapa 5: Plan</h1><h2>5.1 Matriz</h2><p>Primero va esto.</p><p>Y luego lo otro.</p><p>Y otro mas.</p><p>Cuarto.</p><p>Quinto.</p>' . $ancha);
local_chatbot_verificar(strpos($d15, 'Quinto') < strpos($d15, '</w:sectPr></w:pPr></w:p>'),
    'con mas de cuatro parrafos entre el titulo y la tabla, nada se mueve');

// El indice se compacta solo cuando no cabe en una pagina (el de 52 entradas de un diseño real se pasaba unas lineas).
local_chatbot_verificar(documento_final::toc_level(8, 36) === 0, 'un indice de 44 entradas va con el tamaño normal');
local_chatbot_verificar(documento_final::toc_level(7, 45) === 1, 'uno de 52 entradas (el que se desbordaba) pasa al primer nivel compacto');
local_chatbot_verificar(documento_final::toc_level(10, 80) >= 2 && documento_final::toc_level(10, 400) === count(documento_final::TOC_NIVELES) - 1,
    'cuantas mas entradas, mas compacto; uno enorme usa el ultimo nivel y sigue en la pagina siguiente');
$antes = 0;
foreach ([[8, 36], [7, 45], [9, 45], [8, 50]] as [$a, $b]) {
    $t = documento_final::TOC_NIVELES[documento_final::toc_level($a, $b)];
    local_chatbot_verificar($a * $t['alto1'] + $b * $t['alto2'] <= documento_final::TOC_ALTO, "el indice de $a titulos y $b secciones cabe en una pagina");
}
$d16 = $construir('<h1>Etapa 1: A</h1>' . str_repeat('<h2>1. Sec</h2><p>x</p>', 1));
local_chatbot_verificar(strpos($d16, 'w:lineRule="auto"') !== false, 'un indice corto conserva el espacio normal entre lineas');
// Las entradas del indice van dentro de un campo TOC de Word: al actualizarlo al abrir, Word pagina antes de calcular los numeros.
// Con campos PAGEREF sueltos dejaba un «1» en cada entrada.
local_chatbot_verificar(substr_count($doc, ' TOC \o "1-2" \h \z \u ') === 1, 'el indice es un campo TOC de Word, uno solo');
$posini = strpos($doc, ' TOC \o');
$posfin = strrpos(substr($doc, 0, strpos($doc, 'ETAPA 1')), 'w:fldCharType="end"');
local_chatbot_verificar($posini !== false && $posfin !== false && $posfin > $posini && strpos($doc, 'Tabla de contenidos') < $posini,
    'el campo TOC empieza en la primera entrada y termina en la ultima, antes de la primera etapa');
local_chatbot_verificar(substr_count($partes['word/settings.xml'] ?? '', '<w:updateFields w:val="true"/>') === 1
    && strpos($partes['word/styles.xml'] ?? '', 'w:styleId="TOC1"') !== false && strpos($partes['word/styles.xml'] ?? '', 'w:styleId="TOC2"') !== false,
    'el Word se actualiza al abrir y trae los estilos TOC 1 y TOC 2 por si se actualiza la tabla');
echo "Documento final conforme al ejemplo del cliente: {$total} casos verificados | Fallidos: {$fallidos}\n";
exit($fallidos > 0 ? 1 : 0);
