<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Ajustes del curso del Modulo 2, pestana «Fase 1: Diagnostico y Validacion» (pedido de Interfases del 21-10-2026).
 *
 * 1) Cada material de apoyo de la seccion de diagnostico enlaza a su recurso, y los recursos quedan
 *    disponibles pero ocultos en la pagina del curso (como en el Modulo 1).
 * 2) La condicion enmarcada (APROBADO, APROBADO CON OBSERVACIONES, NO APROBADO) va centrada en sus recuadros.
 * 3) «Consultar y descargar el checklist» enlaza al recurso del checklist, tambien oculto.
 * 4) Al final de «Completa y valida el formulario» va el boton a la ficha (ficha_exante.php).
 *
 * Los textos del curso son del cliente: aqui solo se agregan enlaces y estilos, y se quitan las notas
 * provisionales que el propio texto pedia reemplazar. Todo es idempotente: una segunda pasada no cambia nada.
 * Funciones puras; cli/configurar_modulo2.php las aplica y cli/verificar_exante.php las prueba.
 */
class modulo2_setup {

    /** @var string Texto del boton al formulario. */
    const BOTON_FICHA = 'Completar y validar la ficha de diagnóstico';

    /** @var string Texto del boton a las hojas de trabajo de la Fase 2. */
    const BOTON_DISENO = 'Ir al Asistente de Diseño · Fase 2';

    /** @var string Texto del enlace a la rubrica de la Fase 3. */
    const BOTON_RUBRICA = 'Revisar y descargar la rúbrica de validación';

    /** @var string Texto del boton a las etapas 7 y 8 del asistente. */
    const BOTON_VALIDACION = 'Ir a la consolidación y evaluación del diseño · Fase 3';

    /**
     * Recursos de la Fase 1 por su nombre en el curso.
     *
     * @param string[] $nombres cmid => nombre del recurso.
     * @return array material1..3, checklist y rubrica => cmid (solo los que se encontraron).
     */
    public static function match_resources(array $nombres): array {
        $salida = [];
        foreach ($nombres as $cmid => $nombre) {
            $n = \core_text::strtolower((string) $nombre);
            if (strpos($n, 'checklist') !== false) {
                $clave = 'checklist';
            } else if (preg_match('/r[uú]brica/u', $n)) {
                $clave = 'rubrica';
            } else if (strpos($n, 'ejemplo') !== false) {
                $clave = 'material3';
            } else if (preg_match('/gu[ií]a/u', $n)) {
                $clave = 'material1';
            } else if (strpos($n, 'trabajo de campo') !== false || strpos($n, 'ficha de campo') !== false) {
                $clave = 'material2';
            } else {
                continue;
            }
            if (!isset($salida[$clave])) {
                $salida[$clave] = (int) $cmid;
            }
        }
        return $salida;
    }

    /**
     * Modo de visualizacion que debe tener un recurso enlazado desde el texto del curso.
     *
     * La ventana emergente solo funciona al pulsar el recurso en la pagina del curso (Moodle la abre con
     * JavaScript); oculto y abierto por un enlace directo, Moodle muestra una pagina intermedia «Haga clic en ...
     * para ver el archivo». Se pasa a incrustado: el PDF se ve dentro de Moodle y se descarga desde el visor.
     *
     * @param int $display Valor actual (RESOURCELIB_DISPLAY_*: 1 incrustar, 4 descargar, 6 ventana emergente).
     * @return int|null Nuevo valor, o null si se deja como esta.
     */
    public static function linked_display(int $display): ?int {
        return $display === 6 ? 1 : null;
    }

    /**
     * Enlaza cada tarjeta «MATERIAL N» a su recurso: el titulo y un boton «Abrir o descargar».
     *
     * @param string $html Resumen de la seccion de diagnostico.
     * @param string[] $urls 1..3 => URL del recurso.
     * @return string
     */
    public static function link_materials(string $html, array $urls): string {
        foreach ($urls as $n => $url) {
            $marca = '<!-- MATERIAL ' . (int) $n . ' -->';
            $ini = strpos($html, $marca);
            if ($ini === false) {
                continue;
            }
            $sig = strpos($html, '<!--', $ini + strlen($marca));
            $fin = $sig === false ? strlen($html) : $sig;
            $bloque = substr($html, $ini, $fin - $ini);
            $href = 'href="' . htmlspecialchars($url, ENT_QUOTES) . '"';
            if (strpos($bloque, $href) !== false) {
                continue;
            }
            // El color del numero de la tarjeta (el primer fondo que no es el blanco de la tarjeta).
            $color = '#173f5f';
            preg_match_all('/background:\s*(#[0-9a-fA-F]{6})/', $bloque, $m);
            foreach ($m[1] as $c) {
                if (strtolower($c) !== '#ffffff') {
                    $color = $c;
                    break;
                }
            }
            $nuevo = preg_replace('/(<h4\b[^>]*>)(.*?)(<\/h4>)/s',
                '$1<a ' . $href . ' style="color: inherit; text-decoration: underline;">$2</a>$3', $bloque, 1);
            $boton = '<p style="margin: 10px 0 0 0;"><a ' . $href . ' style="display: inline-block; background: '
                . $color . '; color: #ffffff; text-decoration: none; font-weight: bold; font-size: 14px; '
                . 'padding: 7px 14px; border-radius: 7px;">Abrir o descargar</a></p>';
            $pos = strpos($nuevo, '</p>');
            if ($pos !== false) {
                $nuevo = substr($nuevo, 0, $pos + 4) . $boton . substr($nuevo, $pos + 4);
            }
            $html = substr($html, 0, $ini) . $nuevo . substr($html, $fin);
        }
        return $html;
    }

    /**
     * Quita la nota provisional «Los documentos deben estar enlazados o adjuntos en Moodle...».
     *
     * @param string $html
     * @return string
     */
    public static function remove_material_note(string $html): string {
        return preg_replace('/<p\b[^>]*>\s*Los documentos deben estar enlazados o adjuntos en Moodle[^<]*<\/p>\s*/u', '', $html);
    }

    /**
     * Centra la condicion enmarcada de los recuadros de resultado: la etiqueta es inline-block y solo
     * se centra si su contenedor centra el texto.
     *
     * @param string $html
     * @return string
     */
    public static function center_conditions(string $html): string {
        return preg_replace_callback('/<div style="([^"]*)">(\s*<div style="display: inline-block;[^"]*">\s*'
                . '(?:APROBADO|APROBADO CON OBSERVACIONES|NO APROBADO)\s*<\/div>)/u',
            static function($m) {
                if (preg_match('/text-align\s*:\s*center/', $m[1])) {
                    return $m[0];
                }
                return '<div style="' . rtrim(trim($m[1]), ';') . '; text-align: center;">' . $m[2];
            }, $html);
    }

    /**
     * Enlaza «Consultar y descargar el checklist» y quita el comentario y la nota que pedian reemplazar el «#».
     *
     * @param string $html
     * @param string $url
     * @return string
     */
    public static function link_checklist(string $html, string $url): string {
        $html = preg_replace('/<!--\s*REEMPLAZA # POR LA URL REAL[^>]*-->\s*/u', '', $html);
        $html = preg_replace('/<p\b[^>]*>\s*Nota: reemplaza el enlace provisional[^<]*<\/p>\s*/u', '', $html);
        // Reemplaza el enlace que tenga (el «#» provisional o uno anterior): ahora lleva al checklist en Moodle.
        return preg_replace('/(<a\b[^>]*?)href="[^"]*"([^>]*>\s*Consultar y descargar el checklist)/u',
            '$1href="' . htmlspecialchars($url, ENT_QUOTES) . '"$2', $html);
    }

    /**
     * Agrega al final de la seccion el boton al formulario, si no esta.
     *
     * @param string $html
     * @param string $url
     * @return string
     */
    public static function add_form_button(string $html, string $url): string {
        return self::add_button($html, $url, self::BOTON_FICHA, 'FORMULARIO');
    }

    /**
     * Agrega al final de la pestana «Fase 2» el boton a las hojas de trabajo del asistente, si no esta.
     *
     * @param string $html Resumen de la seccion (puede venir vacio).
     * @param string $url
     * @return string
     */
    public static function add_design_button(string $html, string $url): string {
        return self::add_button($html, $url, self::BOTON_DISENO, 'ASISTENTE FASE 2');
    }

    /**
     * Al final de la pestana «Fase 3»: el enlace a la rubrica (recurso oculto) y el boton a las etapas 7 y 8.
     *
     * @param string $html
     * @param string $rubrica URL del recurso de la rubrica ('' si no hay).
     * @param string $url URL de la etapa 7.
     * @return string
     */
    public static function add_validation_buttons(string $html, string $rubrica, string $url): string {
        if ($rubrica !== '') {
            $html = self::add_button($html, $rubrica, self::BOTON_RUBRICA, 'RUBRICA', false);
        }
        return self::add_button($html, $url, self::BOTON_VALIDACION, 'ASISTENTE FASE 3');
    }

    /**
     * Quita el boton a las hojas de trabajo de la Fase 2 (para moverlo a otra seccion).
     *
     * @param string $html
     * @return string
     */
    public static function remove_design_button(string $html): string {
        return preg_replace('/<!-- ASISTENTE FASE 2 -->\s*<div style="text-align: center;[^"]*"><a [^>]*>[^<]*<\/a><\/div>\s*/u',
            '', $html);
    }

    /**
     * Ultima seccion de la pestana de una fase: con pestanas (formato multitopic) es la ultima subseccion que sigue
     * a la seccion «Fase N»; lo que va al final de la pestana va ahi, no en la seccion de la pestana, que se muestra
     * arriba.
     *
     * @param array $secciones numero => ['name' => string, 'level' => int].
     * @param int $fase
     * @return int|null
     */
    public static function tab_last_section(array $secciones, int $fase): ?int {
        $inicio = exante::phase_section(array_map(static function($s) {
            return $s['name'];
        }, $secciones), $fase);
        if ($inicio === null) {
            return null;
        }
        ksort($secciones);
        $ultima = $inicio;
        $nivel = (int) $secciones[$inicio]['level'];
        foreach ($secciones as $num => $s) {
            if ($num <= $inicio) {
                continue;
            }
            if ((int) $s['level'] <= $nivel) {
                break;
            }
            $ultima = (int) $num;
        }
        return $ultima;
    }

    /**
     * Boton verde centrado al final del texto (dentro de su contenedor, si lo tiene). Idempotente por la URL.
     *
     * @param string $html
     * @param string $url
     * @param string $texto
     * @param string $marca Comentario que identifica el bloque.
     * @param bool $principal Verde y destacado; si no, blanco con borde azul (enlace secundario).
     * @return string
     */
    private static function add_button(string $html, string $url, string $texto, string $marca,
            bool $principal = true): string {
        $href = 'href="' . htmlspecialchars($url, ENT_QUOTES) . '"';
        if (strpos($html, $href) !== false) {
            return $html;
        }
        $bloque = '<!-- ' . $marca . ' -->' . "\n"
            . '<div style="text-align: center; margin: 26px 0 6px 0;"><a ' . $href . ' style="display: '
            . 'inline-block; background: ' . ($principal ? '#267a43' : '#ffffff') . '; color: '
            . ($principal ? '#ffffff' : '#173f5f') . '; text-decoration: none; font-weight: bold; '
            . 'font-size: ' . ($principal ? '17' : '15') . 'px; padding: ' . ($principal ? '14px 26px' : '10px 20px')
            . '; border-radius: 10px; border: 2px solid ' . ($principal ? '#267a43' : '#20639b') . ';">'
            . $texto . ' &rarr;</a></div>' . "\n";
        $pos = strrpos($html, '</div>');
        return $pos === false ? $html . $bloque : substr($html, 0, $pos) . $bloque . substr($html, $pos);
    }
}
