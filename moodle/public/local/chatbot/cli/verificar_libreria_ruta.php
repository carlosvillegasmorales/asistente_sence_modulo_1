<?php
/**
 * Verifica el enrutador de la libreria de conocimientos del Modulo 2 (classes/kb_router.php) y los ocho documentos
 * versionados en deploy/libreria_m2/.
 *
 * Uso:  php cli/verificar_libreria_ruta.php
 * Sale con codigo 1 ante cualquier caso fallido, para encadenarlo a CI.
 */

require_once(__DIR__ . '/_puro.php');
require_once(__DIR__ . '/../classes/kb_router.php');
require_once(__DIR__ . '/../classes/kb_store.php');
require_once(__DIR__ . '/../classes/ux.php');
require_once(__DIR__ . '/../classes/guardrails.php');
require_once(__DIR__ . '/../classes/exante.php');
require_once(__DIR__ . '/../classes/diseno.php');

use local_chatbot\diseno;
use local_chatbot\exante;
use local_chatbot\kb_router;
use local_chatbot\kb_store;
use local_chatbot\ux;

$fallidos = 0;
$total = 0;

/**
 * @param bool $condicion
 * @param string $descripcion
 * @return void
 */
function local_chatbot_verificar(bool $condicion, string $descripcion): void {
    global $fallidos, $total;
    $total++;
    if (!$condicion) {
        echo "  FALLA  {$descripcion}\n";
        $fallidos++;
    }
}

// --- Pureza de kb_router.php: sin dependencias de Moodle ($DB, get_config, core_text) ---
$fuenterouter = (string) file_get_contents(__DIR__ . '/../classes/kb_router.php');
$fuentesincomentarios = '';
foreach (token_get_all($fuenterouter) as $tok) {
    if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
        continue;
    }
    $fuentesincomentarios .= is_array($tok) ? $tok[1] : $tok;
}
local_chatbot_verificar(
    strpos($fuentesincomentarios, '$DB') === false
    && stripos($fuentesincomentarios, 'get_config') === false
    && stripos($fuentesincomentarios, 'core_text') === false,
    'kb_router es clase pura (no usa $DB, get_config ni core_text)'
);

// --- Lectura del nivel de cualificacion (level_of) ---
local_chatbot_verificar(kb_router::level_of('Nivel 2') === 2, 'level_of("Nivel 2") -> 2');
local_chatbot_verificar(kb_router::level_of('2') === 2, 'level_of("2") -> 2');
local_chatbot_verificar(kb_router::level_of('nivel cuatro') === 4, 'level_of("nivel cuatro") -> 4');
local_chatbot_verificar(kb_router::level_of('Nivel 3 MCTP') === 3, 'level_of("Nivel 3 MCTP") -> 3');
local_chatbot_verificar(kb_router::level_of('nivel uno') === 1 && kb_router::level_of('Quinto nivel') === 5,
    'level_of reconoce 1 y 5 en palabra');
local_chatbot_verificar(kb_router::level_of('') === null && kb_router::level_of('   ') === null,
    'level_of("") -> null');
local_chatbot_verificar(kb_router::level_of('Nivel 2 o 3') === null && kb_router::level_of('dos o tres') === null,
    'level_of("Nivel 2 o 3") -> null cuando hay más de un nivel');
local_chatbot_verificar(kb_router::level_of('Nivel 6') === null && kb_router::level_of('0') === null
    && kb_router::level_of('Sin información') === null,
    'level_of devuelve null fuera del rango 1..5 o sin nivel');

// --- Limpieza de marcas (strip_marks) y nombre de documento en kb_store::library_file ---
$conmarcas = "# Guía X {ruta: fase1 e1}\n\n## Sección A {ruta: e2 e3}\nTexto normal.\n### Nivel 3 {ruta: e1} {nivel: 3}\nDetalle.";
$limpio = kb_router::strip_marks($conmarcas);
local_chatbot_verificar(
    $limpio === "# Guía X\n\n## Sección A\nTexto normal.\n### Nivel 3\nDetalle."
    && strpos($limpio, '{ruta') === false && strpos($limpio, '{nivel') === false,
    'strip_marks quita {ruta: ...} y {nivel: ...} sin dejar espacios al final del título'
);

$doclib = kb_store::library_file("# Guía X {ruta: fase1}\n\nContenido.");
local_chatbot_verificar(
    $doclib === ['Guía X', "# Guía X {ruta: fase1}\n\nContenido."],
    'kb_store::library_file quita la marca del nombre H1 pero la conserva en el contenido'
);

// --- Herencia de marcas, documento sin marcas, {ruta: todas} y {ruta: ninguna} ---
$docsprueba = [
    [
        'nombre' => 'Doc Marcado',
        'version' => 2,
        'contenido' => implode("\n", [
            '# Doc Marcado {ruta: fase1 e1}',
            'Intro heredada de H1.',
            '## Hija sin marca',
            'Hereda fase1 y e1.',
            '## Hija con ruta propia {ruta: e2 e3}',
            'Va en e2 y e3.',
            '### Nieta heredada',
            'También va en e2 y e3.',
            '### Nieta sobreescrita {ruta: e4}',
            'Va solo en e4.',
            '## Descartada {ruta: ninguna}',
            'Este bloque nunca entra en tareas enrutadas.',
            '## Comodín {ruta: todas}',
            'Entra en cualquier tarea válida.',
        ]),
    ],
    [
        'nombre' => 'Doc Panel Sin Marcas',
        'version' => 1,
        'contenido' => "# Doc Panel Sin Marcas\n\nSubido por el panel: sin marcas va en todas las tareas.",
    ],
    [
        'nombre' => 'Doc Solo E8',
        'version' => 3,
        'contenido' => "# Doc Solo E8 {ruta: e8}\n\nExclusivo de evaluación final.",
    ],
];

$self1 = kb_router::select($docsprueba, 'fase1', null);
local_chatbot_verificar(
    strpos($self1['texto'], '=== DOCUMENTO: Doc Marcado (v2) ===') !== false
    && strpos($self1['texto'], 'Hereda fase1 y e1.') !== false
    && strpos($self1['texto'], 'Entra en cualquier tarea válida.') !== false
    && strpos($self1['texto'], 'Va en e2 y e3.') === false
    && strpos($self1['texto'], 'Va solo en e4.') === false
    && strpos($self1['texto'], 'nunca entra') === false
    && strpos($self1['texto'], 'Doc Solo E8') === false
    && strpos($self1['texto'], '{ruta') === false,
    'herencia H1 -> H2, sobreescritura, exclusión de documento sin secciones elegidas y limpieza de marcas'
);

$sele2 = kb_router::select($docsprueba, 'e2', null);
local_chatbot_verificar(
    strpos($sele2['texto'], 'Va en e2 y e3.') !== false
    && strpos($sele2['texto'], 'También va en e2 y e3.') !== false
    && strpos($sele2['texto'], 'Va solo en e4.') === false
    && strpos($sele2['texto'], 'Hereda fase1 y e1.') === false,
    'herencia H2 -> H3 y sobreescritura en H3 hija ({ruta: e4})'
);

$sele4 = kb_router::select($docsprueba, 'e4', null);
local_chatbot_verificar(
    strpos($sele4['texto'], 'Va solo en e4.') !== false
    && strpos($sele4['texto'], 'Va en e2 y e3.') === false,
    'la subsección H3 sobreescrita entra en su tarea sin arrastrar a su H2 padre'
);

$sinmarcasentodas = true;
$ningunanunca = true;
foreach (kb_router::TAREAS as $t) {
    $r = kb_router::select($docsprueba, $t, null)['texto'];
    if (strpos($r, 'Subido por el panel: sin marcas va en todas las tareas.') === false) {
        $sinmarcasentodas = false;
    }
    if (strpos($r, 'nunca entra en tareas enrutadas') !== false) {
        $ningunanunca = false;
    }
}
local_chatbot_verificar($sinmarcasentodas, 'un documento sin marcas entra en todas las tareas');
local_chatbot_verificar($ningunanunca, '{ruta: ninguna} nunca entra en tareas enrutadas');

// --- Filtro por {nivel: N} y ubicacion al final para preservar el prefijo de cache ---
$docsnivel = [
    [
        'nombre' => 'Marco Niveles',
        'version' => 1,
        'contenido' => implode("\n", [
            '# Marco Niveles',
            '## Resumen general {ruta: e1 e2}',
            'Resumen común de niveles.',
            '## Descriptores {ruta: ninguna}',
            '### Nivel 2 {ruta: e1 e2} {nivel: 2}',
            'Descriptor exclusivo del nivel dos.',
            '### Nivel 3 {ruta: e1 e2} {nivel: 3}',
            'Descriptor exclusivo del nivel tres.',
        ]),
    ],
    [
        'nombre' => 'Manual Posterior',
        'version' => 1,
        'contenido' => "# Manual Posterior {ruta: e1 e2}\n\nContenido general del manual posterior.",
    ],
];

$rnulo = kb_router::select($docsnivel, 'e1', null);
local_chatbot_verificar(
    strpos($rnulo['texto'], 'Resumen común de niveles.') !== false
    && strpos($rnulo['texto'], 'Descriptor exclusivo') === false,
    'con nivel null va el resumen general sin {nivel} y no entra ninguna sección con {nivel}'
);

$rn3 = kb_router::select($docsnivel, 'e1', 3);
$rn2 = kb_router::select($docsnivel, 'e1', 2);
local_chatbot_verificar(
    strpos($rn3['texto'], 'Descriptor exclusivo del nivel tres.') !== false
    && strpos($rn3['texto'], 'Descriptor exclusivo del nivel dos.') === false
    && strpos($rn3['texto'], '{nivel') === false,
    'con nivel 3 entra únicamente la sección {nivel: 3} y sin marcas'
);
local_chatbot_verificar(
    strpos($rn3['texto'], 'Contenido general del manual posterior.') < strpos($rn3['texto'], 'Descriptor exclusivo del nivel tres.')
    && strpos($rn3['texto'], $rnulo['texto']) === 0
    && strpos($rn2['texto'], $rnulo['texto']) === 0,
    'las secciones con {nivel} van al final de toda la librería y mantienen idéntico el prefijo común'
);

// --- Presupuesto: secciones omitidas y aviso final ---
$docspresupuesto = [
    [
        'nombre' => 'Doc Largo',
        'version' => 1,
        'contenido' => "## Uno {ruta: e1}\n" . str_repeat('A', 60)
            . "\n## Dos {ruta: e1}\n" . str_repeat('B', 200)
            . "\n## Tres {ruta: e1}\n" . str_repeat('C', 20),
    ],
];
$rpres = kb_router::select($docspresupuesto, 'e1', null, 150);
local_chatbot_verificar(
    $rpres['secciones'] === 2
    && $rpres['omitidas'] === 1
    && strpos($rpres['texto'], str_repeat('A', 60)) !== false
    && strpos($rpres['texto'], str_repeat('B', 200)) === false
    && strpos($rpres['texto'], str_repeat('C', 20)) !== false
    && str_ends_with($rpres['texto'], '(Por límite de tamaño se omitieron 1 secciones de la librería.)')
    && $rpres['caracteres'] === mb_strlen($rpres['texto'], 'UTF-8'),
    'el presupuesto salta la sección que no cabe, cuenta omitidas y agrega la línea de aviso final'
);

// --- Tarea vacia o desconocida (y etapa 7 fuera del enrutador) ---
$rvacia = kb_router::select($docsprueba, '', null);
$rdesconocida = kb_router::select($docsprueba, 'e7', null);
local_chatbot_verificar(
    $rvacia['omitidas'] === 0
    && $rvacia['texto'] === $rdesconocida['texto']
    && strpos($rvacia['texto'], 'Va solo en e4.') !== false
    && strpos($rvacia['texto'], 'Exclusivo de evaluación final.') !== false
    && strpos($rvacia['texto'], '{ruta') === false,
    'tarea vacía o desconocida devuelve la librería completa sin marcas'
);
local_chatbot_verificar(!in_array('e7', kb_router::TAREAS, true), 'la etapa 7 (consolidación) no es una tarea con librería');
$fuentediseno = (string) file_get_contents(__DIR__ . '/../classes/diseno.php');
if (preg_match('/function consolidate_ai\(.*?\n    \}/s', $fuentediseno, $mc7)) {
    local_chatbot_verificar(
        strpos($mc7[0], 'role_library') === false && strpos($mc7[0], 'request_parts') === false,
        'diseno::consolidate_ai (etapa 7) sigue sin usar la librería'
    );
} else {
    local_chatbot_verificar(false, 'se encontró diseno::consolidate_ai para verificar que no usa librería');
}

// --- Posicion de la libreria en el pedido (exante::request_parts), interruptor y versiones de prompt ---
$partesPrimero = exante::request_parts('PROMPT', [['text' => 'ADJ']], 'LIB', true);
$partesFinal = exante::request_parts('PROMPT', [['text' => 'ADJ']], 'LIB', false);
$partesSinLib = exante::request_parts('PROMPT', [['text' => 'ADJ']], '', true);
local_chatbot_verificar(
    count($partesPrimero) === 3
    && strpos($partesPrimero[0]['text'], 'LIBRERÍA DE CONOCIMIENTOS') === 0
    && $partesPrimero[1] === ['text' => 'PROMPT']
    && $partesPrimero[2] === ['text' => 'ADJ'],
    'request_parts con $primero = true coloca la librería antes del prompt y de los adjuntos'
);
local_chatbot_verificar(
    count($partesFinal) === 3
    && $partesFinal[0] === ['text' => 'PROMPT']
    && $partesFinal[1] === ['text' => 'ADJ']
    && strpos($partesFinal[2]['text'], 'LIBRERÍA DE CONOCIMIENTOS') === 0
    && $partesSinLib === [['text' => 'PROMPT'], ['text' => 'ADJ']],
    'request_parts con $primero = false deja la librería al final, y sin librería no agrega parte vacía'
);
$reglalib = implode("\n", exante::knowledge_rules(true, false));
local_chatbot_verificar(
    strpos($reglalib, 'que viene en este pedido') !== false
    && stripos($reglalib, 'abajo') === false
    && stripos($reglalib, 'más adelante') === false,
    'la regla de fuentes refiere a la librería «que viene en este pedido» (válido al inicio o al final)'
);
local_chatbot_verificar(
    (ux::DEFAULTS['libreriaruta'] ?? null) === 1
    && exante::PROMPT_VERSION === 'M2-F1-checklist-v6'
    && diseno::PROMPT_VERSION === 'M2-F2-etapas-v8',
    'interruptor libreriaruta encendido por defecto y versiones de prompt actualizadas (v6 / v8)'
);

// --- Siete archivos reales de deploy/libreria_m2 (si la carpeta esta presente en este arbol) ---
$dirlib = __DIR__ . '/../../../../../deploy/libreria_m2';
$archivoslib = is_dir($dirlib) ? (glob($dirlib . '/*.md') ?: []) : [];
sort($archivoslib);
if ($archivoslib) {
    local_chatbot_verificar(count($archivoslib) === 8, 'deploy/libreria_m2 contiene los 8 documentos Markdown');
    $docsreales = [];
    foreach ($archivoslib as $ruta) {
        $par = kb_store::library_file((string) file_get_contents($ruta));
        local_chatbot_verificar($par !== null && kb_store::answer_key_markers($par[1]) === [],
            basename($ruta) . ': título H1 válido y sin claves de evaluación');
        if ($par !== null) {
            $docsreales[] = ['nombre' => $par[0], 'contenido' => $par[1], 'version' => 1];
        }
    }

    $completa = kb_router::select($docsreales, '', null, PHP_INT_MAX);
    echo "Librería Módulo 2 completa (sin enrutar): {$completa['caracteres']} caracteres ({$completa['secciones']} secciones)\n";
    echo sprintf("  %-8s | %12s | %12s | %9s\n", 'Tarea', 'Sin nivel', 'Máx. niv 1-5', 'Secciones');
    echo '  ' . str_repeat('-', 50) . "\n";

    foreach (kb_router::TAREAS as $tarea) {
        $sinnivel = kb_router::select($docsreales, $tarea, null);
        local_chatbot_verificar(
            $sinnivel['caracteres'] > 0
            && $sinnivel['caracteres'] <= kb_router::PRESUPUESTO
            && $sinnivel['omitidas'] === 0
            && strpos($sinnivel['texto'], '{ruta') === false
            && strpos($sinnivel['texto'], '{nivel') === false,
            "librería real en '{$tarea}' (nivel null): dentro de PRESUPUESTO, 0 omitidas y sin marcas"
        );

        $maxchars = $sinnivel['caracteres'];
        $maxsecc = $sinnivel['secciones'];
        for ($niv = 1; $niv <= 5; $niv++) {
            $conniv = kb_router::select($docsreales, $tarea, $niv);
            if ($conniv['caracteres'] > $maxchars) {
                $maxchars = $conniv['caracteres'];
            }
            if ($conniv['secciones'] > $maxsecc) {
                $maxsecc = $conniv['secciones'];
            }
            local_chatbot_verificar(
                $conniv['caracteres'] > 0
                && $conniv['caracteres'] <= kb_router::PRESUPUESTO
                && $conniv['omitidas'] === 0
                && strpos($conniv['texto'], '{ruta') === false
                && strpos($conniv['texto'], '{nivel') === false
                && strpos($conniv['texto'], $sinnivel['texto']) === 0,
                "librería real en '{$tarea}' (nivel {$niv}): <= PRESUPUESTO, 0 omitidas y prefijo común intacto"
            );
        }
        echo sprintf("  %-8s | %12d | %12d | %9d\n", $tarea, $sinnivel['caracteres'], $maxchars, $maxsecc);
    }
}

printf("Casos verificados: %d | Fallidos: %d\n", $total, $fallidos);
if ($fallidos === 0) {
    echo "Enrutador de la librería del Módulo 2 conforme.\n";
    exit(0);
}
echo "Enrutador de la librería NO conforme: revisar classes/kb_router.php y deploy/libreria_m2/.\n";
exit(1);

