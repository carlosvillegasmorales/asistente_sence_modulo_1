<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/modulo2_setup.php');

/**
 * Botones al final de las cinco hojas del curso del Modulo 3 (pedido de Interfases del 06-11-2026, «al igual que en el
 * Modulo 2»; el de la Fase 4, del 09-11-2026):
 *
 *   Bienvenida e Instrucciones Generales  →  «Ir a la Fase 1: Registro y Validación»  (pestaña Fase 1 del curso)
 *   Fase 1: Registro y Validación         →  «Completar y validar la ficha de implementación»  (ficha_implementacion.php)
 *   Fase 2: Evaluación de la Implementación →  «Acceder al asistente IA»  (calidad.php: etapas 1 a 3)
 *   Fase 3: Ajuste de la Experiencia de Aprendizaje → «Ir al ajuste de la experiencia de aprendizaje - Fase 3»
 *                                                      (calidad.php, etapa 4)
 *   Fase 4: Evaluación Ex-post            →  «Ir a evaluación ex-post»  (ficha_expost.php: ficha ex post y etapas 9 a 15)
 *
 * Los textos de cada hoja son del cliente y los carga él: aqui solo se agregan los botones, con los textos que pidio, y
 * se enlaza el boton del propio texto del cliente si ya trae uno con ese mismo texto (con «#» provisional). Como en el
 * curso del Modulo 3 el contenido esta en actividades «Texto y medios» (etiquetas) dentro de cada seccion y no en su
 * resumen, el boton va en una etiqueta propia al final de la seccion, marcada con un comentario (<!-- BOTON M3 ... -->) para
 * reconocerla y actualizarla sin duplicarla. Todo es idempotente: una segunda pasada no cambia nada.
 *
 * Funciones puras; cli/configurar_modulo3.php las aplica y cli/verificar_modulo3.php las prueba.
 */
class modulo3_setup {

    /** @var string Textos de los botones, los que pidio el cliente. */
    const BOTON_FASE1 = 'Ir a la Fase 1: Registro y Validación';
    const BOTON_FICHA = 'Completar y validar la ficha de implementación';
    const BOTON_ASISTENTE = 'Acceder al asistente IA';
    const BOTON_AJUSTE = 'Ir al ajuste de la experiencia de aprendizaje - Fase 3';
    const BOTON_EXPOST = 'Ir a evaluación ex-post';

    /**
     * Los cinco botones: texto, marca con que se reconoce su etiqueta y hoja del curso donde van (0 es la bienvenida; 1 a 4
     * son las pestañas «Fase N»).
     *
     * @return array clave => ['texto', 'marca', 'hoja'].
     */
    public static function buttons(): array {
        return [
            'fase1' => ['texto' => self::BOTON_FASE1, 'marca' => 'BOTON M3 FASE1', 'hoja' => 0],
            'ficha' => ['texto' => self::BOTON_FICHA, 'marca' => 'BOTON M3 FICHA', 'hoja' => 1],
            'asistente' => ['texto' => self::BOTON_ASISTENTE, 'marca' => 'BOTON M3 ASISTENTE', 'hoja' => 2],
            'ajuste' => ['texto' => self::BOTON_AJUSTE, 'marca' => 'BOTON M3 AJUSTE', 'hoja' => 3],
            'expost' => ['texto' => self::BOTON_EXPOST, 'marca' => 'BOTON M3 EXPOST', 'hoja' => 4],
        ];
    }

    /**
     * Seccion del curso donde va cada boton: la ultima de la bienvenida (la que va antes de la pestaña «Fase 1») y la ultima
     * de cada pestaña «Fase N» (con pestañas, su ultima subseccion; sin subsecciones, la propia seccion de la pestaña).
     *
     * @param array $secciones numero => ['name' => string, 'level' => int].
     * @return array clave del boton => numero de seccion o null si el curso no tiene esa hoja.
     */
    public static function target_sections(array $secciones): array {
        $salida = [];
        foreach (self::buttons() as $clave => $b) {
            $salida[$clave] = $b['hoja'] === 0 ? modulo2_setup::welcome_last_section($secciones)
                : modulo2_setup::tab_last_section($secciones, $b['hoja']);
        }
        return $salida;
    }

    /**
     * El boton en HTML: verde, centrado, con el mismo estilo que los del Modulo 2. Funcion pura.
     *
     * @param string $url
     * @param string $texto
     * @param string $marca Comentario que identifica el bloque.
     * @return string
     */
    public static function button_html(string $url, string $texto, string $marca): string {
        return '<!-- ' . $marca . ' -->' . "\n"
            . '<div style="text-align: center; margin: 26px 0 6px 0;"><a href="' . htmlspecialchars($url, ENT_QUOTES)
            . '" style="display: inline-block; background: #267a43; color: #ffffff; text-decoration: none; font-weight: bold; '
            . 'font-size: 17px; padding: 14px 26px; border-radius: 10px; border: 2px solid #267a43;">'
            . htmlspecialchars($texto, ENT_QUOTES, 'UTF-8') . ' &rarr;</a></div>' . "\n";
    }

    /**
     * Que hay que hacer para que cada boton quede en su hoja. Funcion pura.
     *
     * @param array $secciones numero => ['name' => string, 'level' => int].
     * @param array $items Lo que hay en el curso: lista de ['seccion' => int, 'tipo' => 'label'|'resumen', 'id' => int (cmid
     *        de la etiqueta o id de la seccion), 'html' => string].
     * @param array $urls clave del boton => URL de destino ('' si aun no se puede calcular).
     * @return array[] Acciones: boton, seccion (int|null), accion (crear, actualizar, enlazar, ok o sin_hoja), tipo, id (int|null)
     *         y html (el nuevo HTML del elemento, o el del boton nuevo; null si no cambia).
     */
    public static function plan(array $secciones, array $items, array $urls): array {
        $destinos = self::target_sections($secciones);
        $acciones = [];
        foreach (self::buttons() as $clave => $b) {
            $n = $destinos[$clave];
            $url = (string) ($urls[$clave] ?? '');
            if ($n === null || $url === '') {
                $acciones[] = ['boton' => $clave, 'seccion' => $n, 'accion' => 'sin_hoja', 'tipo' => null, 'id' => null, 'html' => null];
                continue;
            }
            $hubo = false;
            foreach ($items as $item) {
                if ((int) $item['seccion'] !== (int) $n) {
                    continue;
                }
                $html = (string) $item['html'];
                if ($item['tipo'] === 'label' && strpos($html, '<!-- ' . $b['marca'] . ' -->') !== false) {
                    // La etiqueta propia de una pasada anterior: se rehace si la URL cambio.
                    $nuevo = self::button_html($url, $b['texto'], $b['marca']);
                    $hubo = true;
                    $acciones[] = ['boton' => $clave, 'seccion' => $n, 'accion' => $nuevo === $html ? 'ok' : 'actualizar',
                        'tipo' => 'label', 'id' => (int) $item['id'], 'html' => $nuevo === $html ? null : $nuevo];
                } else if (modulo2_setup::has_anchor($html, $b['texto'])) {
                    // El texto del cliente ya trae su boton con ese texto (con «#» provisional): se enlaza ese.
                    $nuevo = modulo2_setup::link_anchor($html, $b['texto'], $url);
                    $hubo = true;
                    $acciones[] = ['boton' => $clave, 'seccion' => $n, 'accion' => $nuevo === $html ? 'ok' : 'enlazar',
                        'tipo' => $item['tipo'], 'id' => (int) $item['id'], 'html' => $nuevo === $html ? null : $nuevo];
                }
            }
            if (!$hubo) {
                $acciones[] = ['boton' => $clave, 'seccion' => $n, 'accion' => 'crear', 'tipo' => 'label', 'id' => null,
                    'html' => self::button_html($url, $b['texto'], $b['marca'])];
            }
        }
        return $acciones;
    }
}
