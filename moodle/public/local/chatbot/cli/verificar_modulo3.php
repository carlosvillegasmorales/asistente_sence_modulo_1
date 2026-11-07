<?php
/**
 * Verifica los botones de las cuatro hojas del curso del Modulo 3 (pedido de Interfases del 06-11-2026).
 *
 * El cliente pidio un boton al final de cada hoja, con estos textos: «Ir a la Fase 1: Registro y Validación» (bienvenida),
 * «Completar y validar la ficha de implementación» (Fase 1), «Acceder al asistente IA» (Fase 2) e «Ir al ajuste de la
 * experiencia de aprendizaje - Fase 3» (Fase 3). Los textos de las hojas son suyos: el guion solo agrega los botones, enlaza
 * el que el cliente ya haya puesto con ese mismo texto y no hace nada si ya estan.
 *
 * Uso:  php cli/verificar_modulo3.php
 */

require_once(__DIR__ . '/_puro.php');
require_once(__DIR__ . '/../classes/exante.php');
require_once(__DIR__ . '/../classes/modulo2_setup.php');
require_once(__DIR__ . '/../classes/modulo3_setup.php');

use local_chatbot\modulo3_setup;

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

/**
 * Aplica las acciones de un plan a los elementos, como lo hace el guion, para probar que una segunda pasada no cambia nada.
 *
 * @param array $items
 * @param array $acciones
 * @return array
 */
function local_chatbot_aplicar(array $items, array $acciones): array {
    foreach ($acciones as $a) {
        if ($a['accion'] === 'crear') {
            $items[] = ['seccion' => $a['seccion'], 'tipo' => 'label', 'id' => 900 + count($items), 'html' => $a['html']];
        } else if (in_array($a['accion'], ['actualizar', 'enlazar'], true)) {
            foreach ($items as $k => $i) {
                if ($i['tipo'] === $a['tipo'] && $i['id'] === $a['id']) {
                    $items[$k]['html'] = $a['html'];
                }
            }
        }
    }
    return $items;
}

// --- Los textos son los que pidio el cliente ---
$botones = modulo3_setup::buttons();
local_chatbot_verificar(array_keys($botones) === ['fase1', 'ficha', 'asistente', 'ajuste'], 'hay un boton por hoja: cuatro');
local_chatbot_verificar(array_column($botones, 'texto') === ['Ir a la Fase 1: Registro y Validación',
    'Completar y validar la ficha de implementación', 'Acceder al asistente IA',
    'Ir al ajuste de la experiencia de aprendizaje - Fase 3'], 'los textos de los botones son los del cliente');
local_chatbot_verificar(array_column($botones, 'hoja') === [0, 1, 2, 3], 'el primero va en la bienvenida y los demas en las pestañas Fase 1, 2 y 3');
local_chatbot_verificar(count(array_unique(array_column($botones, 'marca'))) === 4, 'cada boton tiene su propia marca');

// --- Donde va cada uno: el curso del Modulo 3 (cuatro pestañas, sin subsecciones) ---
$curso19 = [0 => ['name' => 'Bienvenida e Instrucciones Generales', 'level' => 0], 1 => ['name' => 'Fase 1: Registro y Validación', 'level' => 0],
    2 => ['name' => 'Fase 2: Evaluación de la Implementación', 'level' => 0],
    3 => ['name' => 'Fase 3: Ajuste de la Experiencia de Aprendizaje', 'level' => 0]];
local_chatbot_verificar(modulo3_setup::target_sections($curso19) === ['fase1' => 0, 'ficha' => 1, 'asistente' => 2, 'ajuste' => 3],
    'en el curso 19 cada boton va en la seccion de su pestaña');
$conhijas = [0 => ['name' => 'Bienvenida', 'level' => 0], 1 => ['name' => 'Descripción', 'level' => 1], 2 => ['name' => 'Trabajo con el asistente', 'level' => 1],
    3 => ['name' => 'Fase 1: Registro y Validación', 'level' => 0], 4 => ['name' => 'Instrucciones', 'level' => 1],
    5 => ['name' => 'Completa y valida el formulario', 'level' => 1], 6 => ['name' => 'Fase 2: Evaluación de la Implementación', 'level' => 0],
    7 => ['name' => 'Fase 3: Ajuste de la Experiencia de Aprendizaje', 'level' => 0], 8 => ['name' => 'Cierre', 'level' => 1]];
local_chatbot_verificar(modulo3_setup::target_sections($conhijas) === ['fase1' => 2, 'ficha' => 5, 'asistente' => 6, 'ajuste' => 8],
    'con subsecciones el boton va en la ultima de cada pestaña, como en el Modulo 2');
local_chatbot_verificar(modulo3_setup::target_sections([0 => ['name' => 'Inicio', 'level' => 0], 1 => ['name' => 'Otra', 'level' => 0]])
    === ['fase1' => null, 'ficha' => null, 'asistente' => null, 'ajuste' => null], 'si el curso no tiene las hojas, no hay donde ponerlos');

// --- El boton ---
$urls = ['fase1' => 'https://x.cl/course/view.php?id=19&section=1', 'ficha' => 'https://x.cl/local/chatbot/ficha_implementacion.php?courseid=19',
    'asistente' => 'https://x.cl/local/chatbot/calidad.php?courseid=19', 'ajuste' => 'https://x.cl/local/chatbot/calidad.php?courseid=19&etapa=4'];
$h = modulo3_setup::button_html($urls['ficha'], $botones['ficha']['texto'], $botones['ficha']['marca']);
local_chatbot_verificar(strpos($h, '<!-- BOTON M3 FICHA -->') === 0 && strpos($h, 'href="https://x.cl/local/chatbot/ficha_implementacion.php?courseid=19"') !== false
    && strpos($h, '>Completar y validar la ficha de implementación &rarr;</a>') !== false && strpos($h, 'text-align: center') !== false
    && strpos($h, '#267a43') !== false, 'el botón es verde y centrado, con su marca, su texto y una flecha, como los del Módulo 2');
local_chatbot_verificar(strpos(modulo3_setup::button_html($urls['fase1'], 'x', 'M'), 'id=19&amp;section=1') !== false,
    'la URL se escribe con el & escapado');

// --- El plan: curso sin botones ---
$items = [['seccion' => 0, 'tipo' => 'resumen', 'id' => 10, 'html' => ''], ['seccion' => 0, 'tipo' => 'label', 'id' => 619, 'html' => '<p>Bienvenida</p>'],
    ['seccion' => 1, 'tipo' => 'label', 'id' => 622, 'html' => '<p>Instrucciones</p><a href="URL_GUIA">Abrir guía</a>'],
    ['seccion' => 2, 'tipo' => 'resumen', 'id' => 12, 'html' => ''], ['seccion' => 3, 'tipo' => 'resumen', 'id' => 13, 'html' => '']];
$plan = modulo3_setup::plan($curso19, $items, $urls);
local_chatbot_verificar(count($plan) === 4 && array_column($plan, 'accion') === ['crear', 'crear', 'crear', 'crear']
    && array_column($plan, 'seccion') === [0, 1, 2, 3] && array_column($plan, 'tipo') === ['label', 'label', 'label', 'label'],
    'en un curso sin botones se agrega una etiqueta al final de cada una de las cuatro hojas');
local_chatbot_verificar(strpos($plan[2]['html'], 'Acceder al asistente IA &rarr;') !== false && strpos($plan[3]['html'], 'etapa=4') !== false,
    'el botón de la Fase 3 lleva a la etapa 4 y el de la Fase 2 al asistente');
// El cliente no pierde nada: el plan no toca sus textos ni sus enlaces provisionales.
local_chatbot_verificar(!isset($plan[1]['id']) && strpos(json_encode($plan), 'URL_GUIA') === false,
    'el plan no toca los textos del cliente (ni su «URL_GUIA» provisional)');

// --- Segunda pasada: idempotente ---
$despues = local_chatbot_aplicar($items, $plan);
$plan2 = modulo3_setup::plan($curso19, $despues, $urls);
local_chatbot_verificar(array_unique(array_column($plan2, 'accion')) === ['ok'] && count(array_filter(array_column($plan2, 'html'))) === 0,
    'una segunda pasada no cambia nada');
$antes = count($despues);
$despues = local_chatbot_aplicar($despues, $plan2);
local_chatbot_verificar(count($despues) === $antes, 'y no duplica los botones');

// --- Si cambia el servidor o el curso, la etiqueta propia se actualiza ---
$otras = array_map(static function(string $u): string {
    return str_replace('https://x.cl', 'https://otro.cl', $u);
}, $urls);
$plan3 = modulo3_setup::plan($curso19, $despues, $otras);
local_chatbot_verificar(array_unique(array_column($plan3, 'accion')) === ['actualizar'] && count(array_filter(array_column($plan3, 'id'))) === 4
    && strpos($plan3[1]['html'], 'https://otro.cl/local/chatbot/ficha_implementacion.php?courseid=19') !== false,
    'con otra URL la etiqueta propia se actualiza, no se agrega otra');
$despues3 = local_chatbot_aplicar($despues, $plan3);
local_chatbot_verificar(array_unique(array_column(modulo3_setup::plan($curso19, $despues3, $otras), 'accion')) === ['ok'],
    'y queda al dia');

// --- El cliente ya puso su propio boton con ese texto (con «#» provisional) ---
$conboton = [['seccion' => 2, 'tipo' => 'label', 'id' => 700, 'html' => '<div><!-- REEMPLAZA # POR EL ENLACE REAL AL ASISTENTE IA --> '
    . '<a style="display: inline-block;" href="#"> Acceder al asistente IA </a></div>'],
    ['seccion' => 1, 'tipo' => 'resumen', 'id' => 11, 'html' => '<p>Texto</p><a href="#" class="x">Completar y validar la ficha de implementación</a>']];
$plan4 = modulo3_setup::plan($curso19, $conboton, $urls);
$por = array_column($plan4, null, 'boton');
local_chatbot_verificar($por['asistente']['accion'] === 'enlazar' && $por['asistente']['tipo'] === 'label' && $por['asistente']['id'] === 700
    && strpos($por['asistente']['html'], 'href="https://x.cl/local/chatbot/calidad.php?courseid=19"') !== false
    && strpos($por['asistente']['html'], 'REEMPLAZA') === false,
    'el botón «Acceder al asistente IA» que ya puso el cliente se enlaza y se quita el aviso de reemplazo');
local_chatbot_verificar($por['ficha']['accion'] === 'enlazar' && $por['ficha']['tipo'] === 'resumen' && $por['ficha']['id'] === 11
    && strpos($por['ficha']['html'], 'class="x"') !== false && strpos($por['ficha']['html'], 'ficha_implementacion.php') !== false,
    'también se enlaza un botón del cliente que esté en el resumen de la sección, conservando su estilo');
local_chatbot_verificar($por['fase1']['accion'] === 'crear' && $por['ajuste']['accion'] === 'crear',
    'los botones que el cliente no puso se agregan');
$aplicado = local_chatbot_aplicar($conboton, $plan4);
local_chatbot_verificar(count($aplicado) === 4 && array_unique(array_column(modulo3_setup::plan($curso19, $aplicado, $urls), 'accion')) === ['ok'],
    'con el botón del cliente enlazado, una segunda pasada tampoco cambia nada');
$yaenlazado = [['seccion' => 2, 'tipo' => 'label', 'id' => 700, 'html' => '<a href="' . $urls['asistente'] . '"> Acceder al asistente IA </a>']];
local_chatbot_verificar(array_column(modulo3_setup::plan($curso19, $yaenlazado, $urls), 'accion', 'boton')['asistente'] === 'ok',
    'un botón del cliente que ya apunta al destino queda como está');
// Un texto parecido en otra hoja no cuenta: el boton va en su hoja.
$otrahoja = [['seccion' => 1, 'tipo' => 'label', 'id' => 5, 'html' => '<a href="#">Acceder al asistente IA</a>']];
local_chatbot_verificar(array_column(modulo3_setup::plan($curso19, $otrahoja, $urls), 'accion', 'boton')['asistente'] === 'crear',
    'un botón con el texto de otro en una hoja que no le corresponde no se toca');

// --- Cursos sin las hojas ---
$sinfase2 = [0 => $curso19[0], 1 => $curso19[1], 2 => ['name' => 'Evaluación', 'level' => 0], 3 => $curso19[3]];
$plan5 = array_column(modulo3_setup::plan($sinfase2, [], $urls), null, 'boton');
local_chatbot_verificar($plan5['asistente']['accion'] === 'sin_hoja' && $plan5['ficha']['accion'] === 'crear',
    'si falta una hoja, se avisa y se siguen poniendo los demás botones');
local_chatbot_verificar(array_column(modulo3_setup::plan($curso19, [], ['fase1' => ''] + $urls), 'accion', 'boton')['fase1'] === 'sin_hoja',
    'sin destino calculable (no hay pestaña Fase 1) el botón de la bienvenida no se pone');

printf("Casos verificados: %d | Fallidos: %d\n", $total, $fallidos);
if ($fallidos === 0) {
    echo "Botones del Módulo 3 conformes: textos del cliente, una etiqueta por hoja, enlace del botón del cliente e idempotencia.\n";
}
exit($fallidos === 0 ? 0 : 1);
