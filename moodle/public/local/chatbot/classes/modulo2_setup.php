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

    /** @var string Boton que el cliente puso en la pestana Fase 2 (con «#»), si existe. */
    const BOTON_CLIENTE_FASE2 = 'Acceder al asistente IA';

    /** @var string Boton que el cliente puso en la pestana Fase 3 para la rubrica (con «#»), si existe. */
    const BOTON_CLIENTE_RUBRICA = 'Revisar y descargar la rúbrica';

    /** @var string Texto del boton que lleva al comienzo de la Fase 1 desde el final de la bienvenida. */
    const BOTON_FASE1 = 'Ir a la Fase 1: Diagnóstico y Validación';

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
     * Opciones de visualizacion sin «mostrar tipo»: el recuadro que solo dice «PDF» sobre el visor.
     *
     * @param string $serializado displayoptions del recurso (serialize de PHP).
     * @return string|null Nuevo valor, o null si no hay que cambiar nada.
     */
    public static function display_options(string $serializado): ?string {
        $opciones = @unserialize($serializado, ['allowed_classes' => false]);
        if (!is_array($opciones) || empty($opciones['showtype'])) {
            return null;
        }
        $opciones['showtype'] = 0;
        return serialize($opciones);
    }

    /**
     * Nombre de archivo sin tildes ni eñes. Moodle manda el nombre con tildes en bruto en la cabecera
     * Content-Disposition y el visor de PDF del navegador lo muestra como «DiagnÃ³stico».
     *
     * @param string $nombre
     * @return string Igual si ya era ASCII.
     */
    public static function ascii_filename(string $nombre): string {
        if (preg_match('/^[\x20-\x7E]+$/', $nombre)) {
            return $nombre;
        }
        $limpio = \core_text::specialtoascii($nombre);
        $limpio = preg_replace('/[^\x20-\x7E]/', '', $limpio);
        return trim($limpio) !== '' ? $limpio : $nombre;
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
     * Enlaces URL (catalogos externos) de la Fase 1 por su nombre en el curso.
     *
     * @param string[] $nombres cmid => nombre de la actividad URL.
     * @return array 4 (Plan Formativo) y 5 (Perfil Ocupacional) => cmid (solo los que se encontraron).
     */
    public static function match_urls(array $nombres): array {
        $salida = [];
        foreach ($nombres as $cmid => $nombre) {
            $n = \core_text::strtolower((string) $nombre);
            if (strpos($n, 'plan formativo') !== false) {
                $clave = 4;
            } else if (strpos($n, 'perfil ocupacional') !== false) {
                $clave = 5;
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
     * Agrega las tarjetas 4 y 5 (Plan Formativo y Perfil Ocupacional) a continuacion de la tarjeta «MATERIAL 3», con
     * el mismo formato que las anteriores y un boton «Buscar y descargar» al catalogo externo. Idempotente: si ya
     * estan, las rehace con las URL recibidas.
     *
     * @param string $html Resumen de la seccion de diagnostico.
     * @param string[] $urls 4 y 5 => URL externa del catalogo (las que falten no se agregan).
     * @return string
     */
    public static function add_catalog_cards(string $html, array $urls): string {
        $tarjetas = [
            4 => ['Plan Formativo', 'Catálogo Nacional de Planes Formativos (SENCE)', '#20639b', '#173f5f'],
            5 => ['Perfil Ocupacional', 'Catálogo de Perfiles Laborales (ChileValora)', '#397a46', '#285b32'],
        ];
        $ini = strpos($html, '<!-- MATERIAL 3 -->');
        if ($ini === false || !array_intersect_key($urls, $tarjetas)) {
            return $html;
        }
        // La seccion de tarjetas llega hasta el siguiente comentario que no sea una tarjeta nuestra; su ultimo
        // «</div>» cierra el contenedor de las tarjetas.
        $desde = $ini + strlen('<!-- MATERIAL 3 -->');
        $sig = preg_match('/<!--(?! MATERIAL [45] -->)/', $html, $m, PREG_OFFSET_CAPTURE, $desde) ? $m[0][1] : strlen($html);
        $tramo = substr($html, $ini, $sig - $ini);
        $cierre = strrpos($tramo, '</div>');
        if ($cierre === false) {
            return $html;
        }
        $tarjetas3 = substr($tramo, 0, $cierre);
        $viejas = strpos($tarjetas3, '<!-- MATERIAL 4 -->');
        if ($viejas !== false) {
            // Si ya enlazan a las URL recibidas no se rehacen: el editor de Moodle las reformatea al guardar y el
            // cliente puede haber ajustado su texto.
            $actuales = substr($tarjetas3, $viejas);
            $aldia = true;
            foreach ($urls as $url) {
                $aldia = $aldia && ($url === '' || strpos($actuales, 'href="' . htmlspecialchars($url, ENT_QUOTES) . '"') !== false);
            }
            if ($aldia) {
                return $html;
            }
            $tarjetas3 = substr($tarjetas3, 0, $viejas);
        }
        // La tarjeta 3 era la ultima y no traia separacion inferior.
        $tarjetas3 = str_replace('padding: 19px 20px;">', 'padding: 19px 20px; margin-bottom: 12px;">', $tarjetas3);
        $nuevas = '';
        foreach ($tarjetas as $n => [$titulo, $descripcion, $color, $colortitulo]) {
            if (empty($urls[$n])) {
                continue;
            }
            $href = 'href="' . htmlspecialchars($urls[$n], ENT_QUOTES) . '" target="_blank" rel="noopener"';
            $ultima = ($n === 5 || empty($urls[5]));
            $nuevas .= '<!-- MATERIAL ' . $n . ' -->' . "\n"
                . '<div style="display: flex; align-items: flex-start; gap: 15px; background: #ffffff; border: 1px solid '
                . '#dce3e8; border-radius: 10px; padding: 19px 20px;' . ($ultima ? '' : ' margin-bottom: 12px;') . '">' . "\n"
                . '<div style="flex: 0 0 42px; width: 42px; height: 42px; line-height: 42px; background: ' . $color
                . '; color: #ffffff; border-radius: 9px; text-align: center; font-size: 19px; font-weight: bold;">' . $n
                . '</div>' . "\n"
                . '<div style="flex: 1; min-width: 0;">' . "\n"
                . '<h4 style="margin: 0 0 5px 0; color: ' . $colortitulo . '; font-size: 17px;"><a ' . $href
                . ' style="color: inherit; text-decoration: underline;">' . $titulo . '</a></h4>' . "\n"
                . '<p style="margin: 0; font-size: 14px; text-align: justify;">' . $descripcion . '</p>'
                . '<p style="margin: 10px 0 0 0;"><a ' . $href . ' style="display: inline-block; background: ' . $color
                . '; color: #ffffff; text-decoration: none; font-weight: bold; font-size: 14px; padding: 7px 14px; '
                . 'border-radius: 7px;">Buscar y descargar</a></p>' . "\n"
                . '</div>' . "\n"
                . '</div>' . "\n";
        }
        return substr($html, 0, $ini) . $tarjetas3 . $nuevas . substr($tramo, $cierre) . substr($html, $sig);
    }

    /**
     * Da a las tarjetas de «Revisa los criterios antes de enviar tu ficha» (bloque «CHECKLIST») el formato de las
     * tarjetas de «¿Que informacion debes considerar?»: fondo y borde de color, marca en un circulo y titulo y texto
     * del mismo tamano (pedido del 29-10-2026). Solo cambia el estilo: titulos y textos quedan como estan.
     * Idempotente: una tarjeta ya convertida no vuelve a coincidir.
     *
     * @param string $html Resumen de la seccion «Completa y valida el formulario».
     * @return string
     */
    public static function style_review_cards(string $html): string {
        $ini = strpos($html, '<!-- CHECKLIST -->');
        if ($ini === false) {
            return $html;
        }
        $sig = strpos($html, '<!--', $ini + strlen('<!-- CHECKLIST -->'));
        $fin = $sig === false ? strlen($html) : $sig;
        // Fondo, borde, color del circulo y del titulo, en el orden de las tarjetas de la seccion de diagnostico.
        $paleta = [
            ['#e8f4fc', '#d2e8f7', '#20639b', '#173f5f'],
            ['#edf6ed', '#d7e9d6', '#397a46', '#285b32'],
            ['#fff5df', '#f4e4b9', '#c28a00', '#795600'],
            ['#f1edfc', '#e0d9f7', '#6650a4', '#49377f'],
            ['#eaf5ef', '#d3e9dc', '#16734a', '#145638'],
        ];
        $n = 0;
        $bloque = preg_replace_callback('/<div style="flex: 1 1 260px;[^"]*">\s*<div style="[^"]*">\s*✓\s*<\/div>\s*'
                . '<h4\b[^>]*>(.*?)<\/h4>\s*<p\b[^>]*>(.*?)<\/p>\s*<\/div>/su',
            static function($m) use ($paleta, &$n) {
                [$fondo, $borde, $circulo, $titulo] = $paleta[$n++ % count($paleta)];
                return '<div style="box-sizing: border-box; flex: 1 1 245px; background: ' . $fondo . '; border: 1px solid '
                    . $borde . '; border-radius: 11px; padding: 20px;">' . "\n"
                    . '<div style="width: 42px; height: 42px; line-height: 42px; background: ' . $circulo . '; color: #ffffff; '
                    . 'border-radius: 50%; text-align: center; font-weight: bold; font-size: 20px; margin-bottom: 12px;">✓</div>' . "\n"
                    . '<h4 style="margin: 0 0 8px 0; color: ' . $titulo . '; font-size: 17px; line-height: 1.4; text-align: left;">'
                    . $m[1] . '</h4>' . "\n"
                    . '<p style="margin: 0; font-size: 14px; text-align: justify;">' . $m[2] . '</p>' . "\n"
                    . '</div>';
            }, substr($html, $ini, $fin - $ini));
        return substr($html, 0, $ini) . $bloque . substr($html, $fin);
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
        // Los comentarios del cliente varian («REEMPLAZA # ...», «IMPORTANTE: REEMPLAZA # ...»).
        $html = preg_replace('/<!--[^>]*REEMPLAZA #[^>]*-->\s*/u', '', $html);
        $html = preg_replace('/<p\b[^>]*>\s*Nota: reemplaza el enlace provisional[^<]*<\/p>\s*/u', '', $html);
        // Reemplaza el enlace que tenga (el «#» provisional o uno anterior) por el recibido.
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
        // Si el texto del cliente ya trae su boton («Acceder al asistente IA», con «#»), se enlaza ese.
        if (self::has_anchor($html, self::BOTON_CLIENTE_FASE2)) {
            return self::link_anchor($html, self::BOTON_CLIENTE_FASE2, $url);
        }
        return self::add_button($html, $url, self::BOTON_DISENO, 'ASISTENTE FASE 2');
    }

    /**
     * Agrega al final de la bienvenida el boton que lleva al comienzo de la pestana «Fase 1», si no esta.
     *
     * @param string $html Resumen de la seccion (puede venir vacio).
     * @param string $url
     * @return string
     */
    public static function add_phase1_button(string $html, string $url): string {
        return self::add_button($html, $url, self::BOTON_FASE1, 'IR A FASE 1');
    }

    /**
     * Ultima seccion de la bienvenida: la que va justo antes de la pestana «Fase 1» (con pestanas, la ultima
     * subseccion de la pestana de bienvenida; ahi termina «Trabajo con el Asistente IA»).
     *
     * @param array $secciones numero => ['name' => string, 'level' => int].
     * @return int|null null si no hay pestana «Fase 1» o nada antes de ella.
     */
    public static function welcome_last_section(array $secciones): ?int {
        $fase1 = exante::phase_section(array_map(static function($s) {
            return $s['name'];
        }, $secciones), 1);
        if ($fase1 === null) {
            return null;
        }
        $antes = array_filter(array_keys($secciones), static function($num) use ($fase1) {
            return $num < $fase1;
        });
        return $antes ? (int) max($antes) : null;
    }

    /**
     * Si el texto tiene un enlace con ese texto visible.
     *
     * @param string $html
     * @param string $texto
     * @return bool
     */
    public static function has_anchor(string $html, string $texto): bool {
        return (bool) preg_match('/<a\b[^>]*>\s*' . preg_quote($texto, '/') . '\s*<\/a>/u', $html);
    }

    /**
     * Apunta a $url el enlace con ese texto visible y quita los comentarios «REEMPLAZA # ...» del cliente.
     *
     * @param string $html
     * @param string $texto
     * @param string $url
     * @return string
     */
    public static function link_anchor(string $html, string $texto, string $url): string {
        $html = preg_replace('/<!--[^>]*REEMPLAZA #[^>]*-->\s*/u', '', $html);
        return preg_replace_callback('/<a\b([^>]*)>(\s*' . preg_quote($texto, '/') . '\s*)<\/a>/u',
            static function($m) use ($url) {
                $attrs = preg_replace('/\shref="[^"]*"/', '', $m[1]);
                return '<a' . $attrs . ' href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . $m[2] . '</a>';
            }, $html);
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
        if ($rubrica !== '' && self::has_anchor($html, self::BOTON_CLIENTE_RUBRICA)) {
            // El texto del cliente ya trae «Revisar y descargar la rúbrica» (con «#»): se enlaza ese.
            $html = self::link_anchor($html, self::BOTON_CLIENTE_RUBRICA, $rubrica);
        } else if ($rubrica !== '') {
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
