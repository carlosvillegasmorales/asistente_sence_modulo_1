<?php
/**
 * Verificador de los materiales de la experiencia de continuidad del Modulo 3 (ajuste de Interfases del 26-11-2026):
 * reglas de la Etapa 6 (bloques 1, 2, 3 y 5), produccion P1 a P4 (bloque 4) con el principio 3 modificado (busqueda web
 * conforme a la normativa chilena vigente, «Editar» y «Aceptar» de cada fragmento y de cada marca [REVISAR]) y la lectura
 * del repertorio del Modulo 2 (bloque 6). Solo funciones puras: corre sin base de datos.
 *
 * Uso: php local/chatbot/cli/verificar_produccion_m3.php
 */

require_once(__DIR__ . '/_puro.php');
require_once(__DIR__ . '/../classes/ux.php');
require_once(__DIR__ . '/../classes/guardrails.php');
require_once(__DIR__ . '/../classes/diagrama.php');
require_once(__DIR__ . '/../classes/documento_final.php');
require_once(__DIR__ . '/../classes/exante.php');
require_once(__DIR__ . '/../classes/diseno.php');
require_once(__DIR__ . '/../classes/implementacion.php');
require_once(__DIR__ . '/../classes/calidad.php');
require_once(__DIR__ . '/../classes/expost.php');
require_once(__DIR__ . '/../classes/recurso_export.php');
require_once(__DIR__ . '/../classes/centro_recursos.php');
require_once(__DIR__ . '/../classes/produccion_m3.php');

use local_chatbot\calidad;
use local_chatbot\centro_recursos;
use local_chatbot\produccion_m3;
use local_chatbot\ux;

$total = 0;
$fallidos = 0;

/**
 * @param bool $condicion
 * @param string $descripcion
 */
function local_chatbot_verificar(bool $condicion, string $descripcion): void {
    global $fallidos, $total;
    $total++;
    if (!$condicion) {
        echo "  FALLA  {$descripcion}\n";
        $fallidos++;
    }
}

$tiene = static fn(string $texto, string $buscado): bool => strpos($texto, $buscado) !== false;

// --- Interruptores y textos del cliente ---
local_chatbot_verificar(ux::DEFAULTS['materialesm3'] === 1 && ux::DEFAULTS['produccionm3'] === 1,
    'la Etapa 6 con reglas de materiales y la producción P1 a P4 tienen su interruptor, encendido');
$reglas = implode("\n", produccion_m3::REGLAS);
foreach (['REGLA 1. CLASIFICACIÓN DE CADA MATERIAL', 'REGLA 2. ORDEN DE PREFERENCIA', 'REGLA 3. AJUSTES FUNDADOS EN EVIDENCIA',
        'REGLA 4. MATERIALES POR TRAYECTORIA', 'REGLA 5. MODOS DE PRODUCCIÓN Y ECONOMÍA DE RECURSOS DE IA',
        'REGLA 6. MATERIALES E INSTRUMENTOS', 'REGLA 7. ESPECIFICACIÓN LISTA PARA PRODUCIR', 'REGLA 8. CÓDIGOS',
        'REGLA 9. RELACIÓN CON LAS ETAPAS 4 Y 5', 'REGISTRO OBLIGATORIO EN LA ETAPA 6'] as $titulo) {
    local_chatbot_verificar($tiene($reglas, $titulo), "bloque 3: «{$titulo}» está textual");
}
local_chatbot_verificar($tiene($reglas, 'Material ajustado: código del diseño seguido de «-C» (por ejemplo, M3-C)')
        && $tiene($reglas, 'Material nuevo: código MC seguido de un número correlativo (MC1, MC2…).'),
    'regla 8: códigos M1, M3-C y MC1 como en el documento del cliente');
local_chatbot_verificar($tiene(implode("\n", produccion_m3::CATALOGO), 'La curaduría (Modo C) tiene un costo similar al del tipo 1')
        && $tiene(implode("\n", produccion_m3::RECUPERACION), 'INVENTARIO DE MATERIALES DEL DISEÑO (OBLIGATORIO)')
        && $tiene(implode("\n", produccion_m3::RECUPERACION), '[REVISAR: material no encontrado en el diseño]')
        && count(produccion_m3::VERIFICACIONES_ETAPA6) === 7 && count(produccion_m3::VERIFICACIONES_PRODUCCION) === 5,
    'bloques 1, 2 y 5: catálogo, recuperación con inventario obligatorio y verificaciones (7 de la Etapa 6 y 5 de P1 a P4)');

// --- Principio 3 modificado (pedido del 26-11-2026) ---
$principios = implode("\n", produccion_m3::PRINCIPIOS);
local_chatbot_verificar(count(produccion_m3::PRINCIPIOS) === 9
        && $tiene($principios, 'búscalos en internet: solo son válidos si están de acuerdo con la normativa técnica, las '
            . 'disposiciones de los códigos y la legislación vigentes en Chile')
        && $tiene($principios, 'La persona facilitadora puede aceptar o editar cada marca [REVISAR] y cada fragmento obtenido '
            . 'de internet en cada material')
        && $tiene($principios, 'Las marcas [REVISAR] del material de origen que no se resolvieron se mantienen')
        && $tiene($principios, 'Nunca inventes normas, valores límite, capacidades ni procedimientos de seguridad')
        && !$tiene($principios, 'usa solo valores técnicos'),
    'principio 3: busca en la web lo que falta conforme a la normativa chilena vigente y permite aceptar o editar marcas y fragmentos');
local_chatbot_verificar($tiene($principios, '"' . produccion_m3::RESPUESTA_NO_MODIFICA . '"')
        && produccion_m3::RESPUESTA_NO_MODIFICA === 'Ese cambio modifica la experiencia de continuidad consolidada. Puedo '
            . 'ajustar la redacción, los ejemplos o el formato del material dentro de lo especificado.',
    'principio 1: respuesta textual ante un cambio que altera la experiencia consolidada');
[$fuentesWeb, $extraWeb] = produccion_m3::web_args();
$reglasWeb = implode("\n", centro_recursos::web_prompt_rules(false, $fuentesWeb, $extraWeb));
local_chatbot_verificar($tiene($reglasWeb, 'Usa primero la experiencia de continuidad consolidada, el material de origen')
        && $tiene($reglasWeb, produccion_m3::REGLA_NORMATIVA_CHILE) && $tiene($reglasWeb, 'Fuentes chilenas')
        && $tiene($reglasWeb, '[[WEB 1 | Título de la fuente | https://enlace-exacto]]')
        && !$tiene(implode("\n", centro_recursos::web_prompt_rules()), produccion_m3::REGLA_NORMATIVA_CHILE),
    'la búsqueda web de la continuidad nombra sus fuentes y exige la normativa chilena vigente (el Centro del Módulo 2 no cambia)');

// --- Claves del plan y categorías ---
local_chatbot_verificar(produccion_m3::plan_key(15) === -15 && produccion_m3::impl_from_key(-15) === 15
        && produccion_m3::impl_from_key(15) === 0,
    'el plan de la continuidad usa el id negativo de la ficha de implementación y no se cruza con los diseños del Módulo 2');
local_chatbot_verificar(produccion_m3::normalize_category('Reutilizado') === 'reutilizado'
        && produccion_m3::normalize_category('Ajustada') === 'ajustado' && produccion_m3::normalize_category('NUEVO') === 'nuevo'
        && produccion_m3::normalize_category('otro') === null && produccion_m3::category_label('ajustado') === 'Ajustado',
    'regla 1: una sola categoría por material (reutilizado, ajustado o nuevo)');

// --- Regla 8 ---
local_chatbot_verificar(produccion_m3::code_issue('M1', 'reutilizado', 'M1') === null
        && produccion_m3::code_issue('M3-C', 'ajustado', 'M3') === null
        && produccion_m3::code_issue('MC2', 'nuevo') === null
        && produccion_m3::code_issue('M3C', 'ajustado', 'M3') !== null
        && produccion_m3::code_issue('M3-R', 'ajustado', 'M3') !== null
        && produccion_m3::code_issue('M3-V2', 'reutilizado', 'M3') !== null
        && produccion_m3::code_issue('M4-C', 'ajustado', 'M3') !== null
        && produccion_m3::code_issue('N1', 'nuevo') !== null
        && produccion_m3::code_issue('M3-C', 'reutilizado', 'M3') !== null,
    'regla 8: la plataforma revisa los códigos y señala los sufijos no permitidos');

// --- Repertorio del Módulo 2: estado, versión e inventario ---
$m2 = [
    (object) ['id' => 1, 'codigo' => 'M1', 'nombre' => 'Guía de inicio', 'modo' => 'A', 'formato' => 'HTML', 'estado' => 'entregado',
        'version' => 1, 'contenido' => '<h2>Uno</h2><p>a</p>', 'estructurado' => null, 'html_moodle' => '', 'seleccionados' => null,
        'fecha_verificacion' => '', 'refs_usadas' => '[]', 'revisar_count' => 1, 'timemodified' => 10, 'entregado' => null],
    (object) ['id' => 2, 'codigo' => 'M2', 'nombre' => 'Videos', 'modo' => 'C', 'formato' => 'Ficha de recomendación',
        'estado' => 'borrador', 'version' => 2, 'contenido' => 'nuevo borrador', 'estructurado' => '{}', 'html_moodle' => '',
        'seleccionados' => null, 'fecha_verificacion' => '', 'revisar_count' => 0, 'timemodified' => 20,
        'entregado' => json_encode(['version' => 2, 'estado' => 'entregado', 'modo' => 'C', 'contenido' => 'ficha',
            'estructurado' => json_encode(['opciones' => [['titulo' => 'Video A', 'enlace' => 'https://www.youtube.com/watch?v=aaa'],
                ['titulo' => 'Video B', 'enlace' => 'https://caido.example/b']]]), 'seleccionados' => '[0,1]',
            'fecha_verificacion' => '01-11-2026', 'revisar_count' => 0, 'html_moodle' => ''])],
    (object) ['id' => 3, 'codigo' => 'M3', 'nombre' => 'Caso', 'modo' => 'B', 'formato' => 'Word', 'estado' => 'pendiente',
        'version' => 0, 'contenido' => null, 'estructurado' => null, 'revisar_count' => 0, 'timemodified' => 0, 'entregado' => null],
    (object) ['id' => 4, 'codigo' => 'M4', 'nombre' => 'Lista de cotejo', 'modo' => 'B', 'formato' => 'Word', 'estado' => 'instrumento',
        'version' => 0, 'contenido' => null, 'estructurado' => null, 'revisar_count' => 0, 'timemodified' => 0, 'entregado' => null],
    (object) ['id' => 5, 'codigo' => 'M5 (ant.)', 'nombre' => 'Viejo', 'modo' => 'A', 'formato' => 'HTML', 'estado' => 'entregado',
        'version' => 1, 'contenido' => 'x', 'estructurado' => null, 'revisar_count' => 0, 'timemodified' => 0, 'entregado' => null],
];
local_chatbot_verificar(produccion_m3::m2_status_label($m2[0]) === 'Entregado' && produccion_m3::m2_status_label($m2[1]) === 'Entregado'
        && produccion_m3::m2_status_label($m2[2]) === 'Pendiente'
        && produccion_m3::m2_status_label($m2[3]) === 'Instrumento de evaluación del diseño',
    'bloque 2: estado en el centro de cada material (un borrador de la v3 sigue teniendo vigente la v2 entregada)');
$inv = produccion_m3::inventory_text($m2, [(object) ['filename' => 'manual.pdf']]);
local_chatbot_verificar($tiene($inv, 'M1 | Guía de inicio | A | HTML | Entregado | v1 | 1 | —')
        && $tiene($inv, 'M2 | Videos | C | Ficha de recomendación | Entregado | v2')
        && $tiene($inv, 'Video A — https://www.youtube.com/watch?v=aaa') && $tiene($inv, '(verificados el 01-11-2026)')
        && $tiene($inv, 'M3 | Caso | B | Word | Pendiente | — | —') && !$tiene($inv, 'Viejo')
        && !$tiene($inv, '<h2>Uno</h2>') && $tiene($inv, 'manual.pdf'),
    'inventario del repertorio: una línea por material, sin su contenido (lectura económica), con enlaces de Modo C y referencias');
local_chatbot_verificar($tiene(produccion_m3::inventory_text([]), 'El diseño no se trabajó en el centro de producción'),
    'un diseño sin uso del centro se toma desde la especificación de la Etapa 4');
local_chatbot_verificar(produccion_m3::origin_row($m2, 'm2')->id === 2 && produccion_m3::origin_row($m2, 'M5') === null
        && produccion_m3::origin_row($m2, 'M9') === null,
    'el material de origen se busca por su código del diseño (sin los «(ant.)»)');

// --- Etapa P1: plan de producción ---
$jsonPlan = json_encode(['materiales' => [
    ['codigo' => 'M1', 'nombre' => 'Guía de inicio', 'categoria' => 'Reutilizado', 'origen_codigo' => 'M1', 'modo' => 'B',
        'formato' => 'Word', 'bloque' => 'Inicio', 'trayectoria' => 'Ambas'],
    ['codigo' => 'M2', 'nombre' => 'Videos', 'categoria' => 'reutilizado', 'modo' => 'C', 'formato' => 'Ficha'],
    ['codigo' => 'M3-C', 'nombre' => 'Caso ajustado', 'categoria' => 'Ajustado', 'modo' => 'B', 'formato' => 'Word',
        'especificacion' => 'Sección 2 se modifica con pasos guiados.'],
    ['codigo' => 'MC1', 'nombre' => 'Pauta de autochequeo', 'categoria' => 'Nuevo', 'modo' => 'A', 'formato' => 'HTML'],
    ['codigo' => 'M9-C', 'nombre' => 'Inexistente', 'categoria' => 'Ajustado', 'modo' => 'A', 'formato' => 'HTML'],
    ['codigo' => 'MC1', 'nombre' => 'Duplicado', 'categoria' => 'Nuevo'],
    ['codigo' => 'X', 'nombre' => 'Sin categoría', 'categoria' => 'otra'],
], 'recursos_entorno' => ['Taller de soldadura', '']], JSON_UNESCAPED_UNICODE);
$plan = produccion_m3::parse_plan_json($jsonPlan);
local_chatbot_verificar(count($plan['materiales']) === 5 && $plan['materiales'][1]['origen_codigo'] === 'M2'
        && $plan['materiales'][2]['origen_codigo'] === 'M3' && $plan['materiales'][3]['origen_codigo'] === ''
        && $plan['recursos_entorno'] === ['Taller de soldadura'] && produccion_m3::parse_plan_json('no es json') === null,
    'P1: se lee el plan del asistente (sin duplicados ni categorías inválidas; el origen sale del código si falta)');
$verif = static fn(string $url): array => ['ok' => strpos($url, 'caido') === false, 'url' => $url];
$filas = produccion_m3::plan_rows($plan['materiales'], $m2, $verif, '26-11-2026');
local_chatbot_verificar($filas[0]['estado'] === 'disponible' && $filas[0]['modo'] === 'A' && $filas[0]['formato'] === 'HTML'
        && $filas[0]['origen']['origen_version'] === 'v1' && $filas[0]['origen']['fuente'] === 'repertorio'
        && $filas[0]['entregada']['contenido'] === '<h2>Uno</h2><p>a</p>',
    'P1: un reutilizado entregado en el Módulo 2 queda «Disponible en el repertorio» con su modo, formato y versión de origen');
local_chatbot_verificar($filas[1]['estado'] === 'pendiente' && $filas[1]['categoria'] === 'ajustado'
        && $filas[1]['origen']['enlaces_caidos'] === ['https://caido.example/b']
        && $filas[1]['origen']['fecha_verificacion'] === '26-11-2026' && $filas[1]['entregada'] === null,
    'P1: un reutilizado de Modo C con enlaces caídos queda «Pendiente» como ajustado, solo para reemplazar esos enlaces');
local_chatbot_verificar($filas[2]['estado'] === 'pendiente' && $filas[2]['origen']['fuente'] === 'especificacion'
        && $filas[2]['origen']['origen_version'] === '' && $filas[2]['origen']['regla8'] === null
        && $filas[3]['estado'] === 'pendiente' && $filas[3]['origen']['fuente'] === '' && $filas[3]['origen']['regla8'] === null,
    'P1: ajustados y nuevos quedan «Pendiente»; un origen no producido se toma de la especificación del diseño');
local_chatbot_verificar($tiene($filas[4]['origen']['nota'], '[REVISAR: material no encontrado en el diseño]')
        && $filas[4]['origen']['en_diseno'] === false,
    'P1: un material citado que no está en la lista de producción del diseño queda con [REVISAR]');
[$e1, $c1] = produccion_m3::initial_state('reutilizado', null);
local_chatbot_verificar($e1 === 'pendiente' && $c1 === 'reutilizado',
    'P1: un reutilizado que no se produjo en el Módulo 2 queda «Pendiente» para producirlo según el diseño, sin cambios');

// --- Etapa P2.3: material ajustado por secciones ---
$origenHtml = '<h2>1. Propósito</h2><p>Texto original del propósito.</p><h2>2. Pasos</h2><p>Pasos originales.</p>'
    . '<h3>2.1 Detalle</h3><p>Detalle original.</p><h2>3. Glosario</h2><p>Términos.</p>'
    . '<h3>Referencias consultadas</h3><ul><li>Fuente M2 — https://bcn.cl/x</li></ul>'
    . '<p><em>M3 · ' . centro_recursos::PIE_MATERIAL . '</em></p>';
$parcialHtml = '<h2>2. Pasos</h2><p>Pasos guiados para nivelación.</p><h2>4. Profundización</h2><p>Caso de transferencia.</p>';
$fusion = produccion_m3::merge_adjusted($origenHtml, $parcialHtml, '<blockquote><p>Cambió la sección 2.</p></blockquote>',
    ['glosario'], 'M3-C · Ajustado · Origen: M3 v1 · ' . centro_recursos::PIE_MATERIAL);
local_chatbot_verificar($fusion !== null && strpos($fusion, '<blockquote><p>Cambió la sección 2.</p></blockquote>') === 0
        && $tiene($fusion, '<h2>1. Propósito</h2><p>Texto original del propósito.</p>')
        && $tiene($fusion, 'Pasos guiados para nivelación.') && !$tiene($fusion, 'Pasos originales.') && !$tiene($fusion, 'Detalle original.')
        && !$tiene($fusion, 'Términos.') && $tiene($fusion, 'Caso de transferencia.')
        && strpos($fusion, 'Caso de transferencia.') < strpos($fusion, 'Referencias consultadas')
        && substr_count($fusion, centro_recursos::PIE_MATERIAL) === 1 && $tiene($fusion, 'M3-C · Ajustado · Origen: M3 v1'),
    'P2.3: el ajustado conserva textuales las secciones de origen, cambia las aprobadas, agrega y elimina, con nota inicial y pie');
local_chatbot_verificar(produccion_m3::merge_adjusted($origenHtml, '<p>sin títulos</p>', '', [], 'pie') === null,
    'P2.3: sin secciones con título no se adivina dónde van los cambios');
$fusionRef = produccion_m3::merge_adjusted($origenHtml, '<h3>Referencias consultadas</h3><ul><li>Nueva — https://minsal.cl</li></ul>',
    '', [], 'pie');
local_chatbot_verificar($tiene($fusionRef, 'Fuente M2 — https://bcn.cl/x') && $tiene($fusionRef, 'Nueva — https://minsal.cl')
        && substr_count($fusionRef, 'Referencias consultadas') === 1,
    'P2.3: las referencias web nuevas se suman a las de la versión de origen');
$tablaCambios = '<table><tr><th>Sección</th><th>Se conserva / Se modifica / Se agrega / Se elimina</th><th>Cambio</th></tr>'
    . '<tr><td>1. Propósito</td><td>Se conserva</td><td>—</td></tr><tr><td>2. Pasos</td><td>Se modifica</td><td>x</td></tr>'
    . '<tr><td>3. Glosario</td><td>Se elimina</td><td>Se remite a M1</td></tr></table>';
local_chatbot_verificar(produccion_m3::change_table($tablaCambios) === ['conserva' => ['proposito'], 'elimina' => ['glosario']],
    'P2.2: la plataforma lee del cuadro de cambios aprobado qué secciones se conservan y cuáles se eliminan');
[$nota, $resto] = produccion_m3::split_note("> **Nota para la persona facilitadora:** cambió 2.\n> Y se agregó 4.\n\n## 2. Pasos\nTexto");
local_chatbot_verificar($tiene($nota, 'Nota para la persona facilitadora') && $tiene($nota, 'se agregó 4') && strpos($resto, '## 2. Pasos') === 0,
    'P2.3: la nota inicial para la persona facilitadora se separa de las secciones');
local_chatbot_verificar(produccion_m3::partial_mode('ajustado', ['contenido' => '<p>x</p>', 'estructurado' => ''], false)
        && !produccion_m3::partial_mode('ajustado', ['contenido' => '<p>x</p>', 'estructurado' => '{"a":1}'], true)
        && !produccion_m3::partial_mode('ajustado', null, false) && !produccion_m3::partial_mode('nuevo', ['contenido' => 'x'], false),
    'P2.3: el ajuste por secciones aplica a ajustados con origen en texto; el resto se produce completo');

// --- Prompts de P1 a P4 ---
$mat = ['codigo' => 'M3-C', 'nombre' => 'Caso ajustado', 'fase' => 'Desarrollo · Nivelación', 'modo' => 'B', 'formato' => 'Word',
    'categoria' => 'ajustado', 'origen' => ['origen_codigo' => 'M3', 'origen_version' => 'v1', 'fuente' => 'repertorio',
        'criterios' => 'CE2', 'especificacion' => 'Sección 2 se modifica con pasos guiados.']];
$ctxP = ['datos' => ['organismo' => 'OTEC Sur', 'fechas' => '02-12-2026'], 'etapa6' => 'ETAPA SEIS', 'origen' => 'TEXTO DE ORIGEN',
    'diseno4' => 'ETAPA CUATRO M2', 'ficha' => 'FICHA', 'refs' => ['manual.pdf'], 'producidos' => ['M1: Guía']];
$pEst = produccion_m3::build_structure_prompt($mat, $ctxP);
local_chatbot_verificar($tiene($pEst, '«Sección», «Se conserva / Se modifica / Se agrega / Se elimina», «Cambio» y «Evidencia o '
            . 'trayectoria que lo justifica»') && $tiene($pEst, '«' . produccion_m3::PREGUNTA_PROPUESTA . '»')
        && $tiene($pEst, 'TEXTO DE ORIGEN') && $tiene($pEst, 'ETAPA SEIS') && $tiene($pEst, 'Sección 2 se modifica')
        && $tiene($pEst, 'Material de origen: M3 v1 (fuente: repertorio del diseño)') && $tiene($pEst, 'OTEC Sur')
        && $tiene($pEst, produccion_m3::PRINCIPIO_EXACTITUD),
    'P2.2: la propuesta de un ajustado es el cuadro de cambios con su origen, versión y fuente');
$pEstNuevo = produccion_m3::build_structure_prompt(['categoria' => 'nuevo', 'origen' => []] + $mat, $ctxP);
local_chatbot_verificar($tiene($pEstNuevo, 'Partes del material con su contenido en una línea cada una')
        && !$tiene($pEstNuevo, 'Se conserva / Se modifica'),
    'P2.2: la propuesta de un material nuevo es su estructura');
$pCont = produccion_m3::build_content_prompt($mat, $ctxP + ['estructura' => 'CUADRO APROBADO', 'web' => true, 'parcial' => true]);
local_chatbot_verificar($tiene($pCont, '> **Nota para la persona facilitadora:**') && $tiene($pCont, 'SOLO las secciones')
        && $tiene($pCont, produccion_m3::REGLA_NORMATIVA_CHILE) && $tiene($pCont, 'CUADRO APROBADO')
        && $tiene($pCont, '«Nivelación» y «Profundización»'),
    'P2.3: el ajustado pide solo las secciones que cambian, con nota inicial, partes por trayectoria y búsqueda web conforme a Chile');
$pContB = produccion_m3::build_content_prompt(['categoria' => 'nuevo', 'origen' => [], 'codigo' => 'MC1'] + $mat,
    $ctxP + ['estructura' => 'E', 'web' => true, 'parcial' => false]);
local_chatbot_verificar($tiene($pContB, 'INSTRUCCIONES DE PRODUCCIÓN') && $tiene($pContB, 'MC1 · Nuevo · ' . centro_recursos::PIE_MATERIAL)
        && $tiene($pContB, produccion_m3::REGLA_NORMATIVA_CHILE),
    'P2.3: un material nuevo aplica las reglas de cada modo del Módulo 2 y cierra con código, categoría y pie');
$pAj = produccion_m3::build_adjust_prompt($mat, 'ACTUAL', 'cambia el tiempo de la actividad', false);
local_chatbot_verificar($tiene($pAj, produccion_m3::RESPUESTA_NO_MODIFICA) && $tiene($pAj, 'ACTUAL')
        && centro_recursos::is_refusal('Ese cambio modifica la experiencia de continuidad consolidada. Puedo…',
            ['Ese cambio modifica la experiencia de continuidad consolidada'])
        && !centro_recursos::is_refusal('Material ajustado', ['Ese cambio modifica la experiencia de continuidad consolidada']),
    'P2.4: un ajuste que altera la experiencia se rechaza con la respuesta textual y la plataforma lo reconoce');
$pPlan = produccion_m3::build_plan_prompt('ETAPA SEIS', $inv);
local_chatbot_verificar($tiene($pPlan, 'Etapa P1: plan de producción') && $tiene($pPlan, '"categoria": "reutilizado|ajustado|nuevo"')
        && $tiene($pPlan, 'M1 | Guía de inicio') && $tiene($pPlan, 'REGLA 8. CÓDIGOS'),
    'P1: el plan recupera la lista de la Etapa 6 con el inventario del repertorio');

// --- Pie, repertorio P4 y estado para el documento operativo ---
$filaM3 = (object) ['codigo' => 'M3-C', 'categoria' => 'ajustado', 'origen' => json_encode(['origen_codigo' => 'M3',
    'origen_version' => 'v1'])];
local_chatbot_verificar(centro_recursos::footer_prefix($filaM3) === 'M3-C · Ajustado · Origen: M3 v1'
        && centro_recursos::footer_prefix((object) ['codigo' => 'MC1', 'categoria' => 'nuevo', 'origen' => '{}']) === 'MC1 · Nuevo',
    'P2.3: la línea final lleva el código, la categoría y el origen');
$recM3 = [
    (object) ['codigo' => 'M1', 'nombre' => 'Guía', 'categoria' => 'reutilizado', 'modo' => 'A', 'formato' => 'HTML',
        'estado' => 'disponible', 'version' => 1, 'revisar_count' => 1, 'estructurado' => null,
        'origen' => json_encode(['origen_codigo' => 'M1', 'origen_version' => 'v1'])],
    (object) ['codigo' => 'M3-C', 'nombre' => 'Caso', 'categoria' => 'ajustado', 'modo' => 'B', 'formato' => 'Word',
        'estado' => 'entregado', 'version' => 2, 'revisar_count' => 0, 'estructurado' => null,
        'origen' => json_encode(['origen_codigo' => 'M3', 'origen_version' => 'v1'])],
    (object) ['codigo' => 'MC1', 'nombre' => 'Pauta', 'categoria' => 'nuevo', 'modo' => 'A', 'formato' => 'HTML',
        'estado' => 'estructura', 'version' => 0, 'revisar_count' => 0, 'estructurado' => null, 'origen' => '{}'],
];
$rep = produccion_m3::repertory_html(['experiencia' => 'Continuidad X'], $recM3);
local_chatbot_verificar($tiene($rep, '<td>M3-C</td><td>Caso</td><td>Ajustado</td><td>M3 v1</td>')
        && $tiene($rep, 'Disponible en el repertorio') && $tiene($rep, '<td>MC1</td><td>Pauta</td><td>Nuevo</td><td>—</td>')
        && $tiene($rep, produccion_m3::RECORDATORIO_P4) && $tiene($rep, produccion_m3::USO_REPERTORIO),
    'P4: repertorio con categoría, origen y versión, estado y recordatorio de las marcas [REVISAR]');
local_chatbot_verificar(produccion_m3::status_map($recM3) === ['M1' => 'Disponible en el repertorio', 'M3-C' => 'Entregado',
        'MC1' => 'Pendiente'],
    'P4: el estado del repertorio de la continuidad alimenta la columna «Estado» del documento operativo');

// --- Etapa 6 del Prompt Maestro con las reglas de materiales ---
$ctxCal = ['ficha' => 'F', 'datos' => 'D', 'informe' => ['items' => []], 'condicion' => '', 'diseno' => 'DISEÑO', 'previas' => [],
    'adjuntos' => [], 'ajuste' => calidad::AJUSTE_REMEDIALES, 'actual' => '', 'ajustes' => '', 'conlibreria' => false,
    'conbusqueda' => false];
$p6 = calidad::build_prompt(6, $ctxCal + ['materiales' => true, 'inventario' => $inv]);
$p6sin = calidad::build_prompt(6, $ctxCal);
local_chatbot_verificar($tiene($p6, '- Materiales de apoyo, según las reglas para los materiales de la experiencia de continuidad')
        && $tiene($p6, 'REGLA 9. RELACIÓN CON LAS ETAPAS 4 Y 5') && $tiene($p6, 'CATÁLOGO DE CAPACIDADES DE PRODUCCIÓN')
        && $tiene($p6, 'INVENTARIO DE MATERIALES DEL DISEÑO (OBLIGATORIO)') && $tiene($p6, 'M1 | Guía de inicio')
        && $tiene($p6, produccion_m3::VERIFICACIONES_ETAPA6[0]),
    'Etapa 6: el prompt trae catálogo, recuperación, reglas 1 a 9, verificaciones e inventario del repertorio del diseño');
local_chatbot_verificar(!$tiene($p6sin, 'REGLA 9.') && !$tiene($p6sin, 'según las reglas para los materiales')
        && $tiene($p6sin, '- Materiales de apoyo'),
    'Etapa 6 con el interruptor apagado: el prompt queda como antes');
$p5 = calidad::build_prompt(5, $ctxCal + ['materiales' => true]);
local_chatbot_verificar($tiene($p5, 'REGLA 9. RELACIÓN CON LAS ETAPAS 4 Y 5') && $tiene($p5, 'REGLA 8. CÓDIGOS')
        && !$tiene($p5, 'REGLA 1. CLASIFICACIÓN'),
    'Etapas 4 y 5: reciben solo las reglas 8 y 9 (códigos y recomendaciones para el Módulo 2)');

printf("Casos verificados: %d | Fallidos: %d\n", $total, $fallidos);
if ($fallidos === 0) {
    echo "Materiales y producción de la experiencia de continuidad (Módulo 3) conformes.\n";
    exit(0);
}
echo "Materiales y producción de la continuidad NO conformes: revisar classes/produccion_m3.php.\n";
exit(1);
