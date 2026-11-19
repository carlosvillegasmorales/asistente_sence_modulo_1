<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Motor de generacion, validacion y extraccion de archivos del Centro de Produccion de Recursos (Modulo 2, Etapas 9-12).
 *
 * Resuelve el Modo B en PHP puro dentro del mismo servidor Moodle (sin servicios externos de Azure):
 *  - Word (.docx): reutiliza documento_final::build() y diseno::html_to_docx().
 *  - Excel (.xlsx): usa PhpSpreadsheet (incluido en Moodle 5.2) con respaldo OOXML puro via ZipArchive.
 *  - Presentaciones (.pptx): generador OOXML puro con plantilla Interfases y notas del facilitador.
 *  - PDF (.pdf): TCPDF (incluido en Moodle 5.2) con soporte A4, Carta, A4 horizontal, A3, A2 y tarjetas 9x6 cm,
 *    mas pictogramas vectoriales SVG para afiches, tarjetas y tableros.
 *  - Cuestionarios desde una sola fuente JSON: Moodle GIFT (.txt UTF-8 validado), Google Apps Script (.gs) y Word impreso.
 *  - HTML compatible con el editor de Moodle (estilos en linea, sin scripts ni recursos externos).
 *  - Empaquetado del repertorio final en .zip y lectura de referencias tecnicas (.docx, .xlsx).
 *
 * Todas las funciones de esta clase son puras (no consultan BD) y las ejercita cli/verificar_centro_recursos.php.
 */
class recurso_export {

    /** @var string Paleta institucional Interfases (hex sin #). */
    const NAVY = '0B2447';
    const AZUL = '2E5A88';
    const FONDO = 'F4F7FB';
    const VERDE = '267A43';
    const AMBAR = 'B45309';
    const TEXTO = '1E2533';

    /**
     * Detecta la configuracion de hoja PDF a partir del texto del formato de salida (ej. «PDF A2», «PDF tarjeta 9x6 cm»,
     * «PDF A4 horizontal», «PDF A3 para plastificar»).
     *
     * @param string $formato Texto del formato del material.
     * @param string $impresion Formato preferido por la persona facilitadora ('carta' o 'a4').
     * @return array ['tamano' => string|float[], 'orientacion' => 'P'|'L', 'tipo' => 'doc'|'afiche'|'tarjeta'|'tablero'].
     */
    public static function parse_pdf_spec(string $formato, string $impresion = 'carta'): array {
        $f = mb_strtolower($formato, 'UTF-8');
        $horizontal = preg_match('/horizontal|apaisad|landscape/u', $f) === 1;
        if (preg_match('/tarjeta|bolsillo|9\s*[x×]\s*6/u', $f)) {
            return ['tamano' => [90, 60], 'orientacion' => 'L', 'tipo' => 'tarjeta'];
        }
        if (preg_match('/\ba2\b/u', $f)) {
            return ['tamano' => 'A2', 'orientacion' => $horizontal ? 'L' : 'P', 'tipo' => 'afiche'];
        }
        if (preg_match('/\ba3\b|tablero|mapa del circuito/u', $f)) {
            return ['tamano' => 'A3', 'orientacion' => 'L', 'tipo' => 'tablero'];
        }
        if (preg_match('/\ba4\b/u', $f)) {
            return ['tamano' => 'A4', 'orientacion' => $horizontal ? 'L' : 'P', 'tipo' => 'doc'];
        }
        if (preg_match('/carta|letter/u', $f)) {
            return ['tamano' => 'LETTER', 'orientacion' => $horizontal ? 'L' : 'P', 'tipo' => 'doc'];
        }
        $base = mb_strtolower($impresion, 'UTF-8') === 'a4' ? 'A4' : 'LETTER';
        return ['tamano' => $base, 'orientacion' => $horizontal ? 'L' : 'P', 'tipo' => 'doc'];
    }

    /**
     * Pictograma vectorial SVG limpio segun una palabra clave (para afiches, tarjetas y tableros sin depender de imagenes IA).
     *
     * @param string $clave Descripcion del pictograma (ej. «lavado de manos», «guantes», «mascarilla», «seguridad»).
     * @param int $size Tamano en px.
     * @return string Codigo SVG inline.
     */
    public static function svg_pictogram(string $clave, int $size = 28): string {
        $k = mb_strtolower($clave, 'UTF-8');
        $c = '#' . self::AZUL;
        $inner = '<circle cx="12" cy="12" r="9" fill="none" stroke="' . $c . '" stroke-width="2"/>'
            . '<path d="M8 12.5l2.5 2.5L16.5 9" fill="none" stroke="' . $c . '" stroke-width="2" stroke-linecap="round"/>';
        if (preg_match('/mano|jab[oó]n|lav|higiene|agua/u', $k)) {
            $inner = '<path d="M12 3C9 7 6 10 6 13.5a6 6 0 0 0 12 0C18 10 15 7 12 3z" fill="#E8F0F7" stroke="' . $c
                . '" stroke-width="2"/><path d="M10 14.5a2.5 2.5 0 0 0 2 2" fill="none" stroke="' . $c . '" stroke-width="1.8"/>';
        } else if (preg_match('/alerta|riesgo|peligro|segur|revisar|precauci|epp|protecci/u', $k)) {
            $c = '#' . self::AMBAR;
            $inner = '<path d="M12 3L2 20h20L12 3z" fill="#FEF3C7" stroke="' . $c . '" stroke-width="2" stroke-linejoin="round"/>'
                . '<line x1="12" y1="9" x2="12" y2="14" stroke="' . $c . '" stroke-width="2" stroke-linecap="round"/>'
                . '<circle cx="12" cy="17" r="1.2" fill="' . $c . '"/>';
        } else if (preg_match('/saludo|acogida|consent|di[aá]logo|comunic|escucha|persona/u', $k)) {
            $inner = '<circle cx="9" cy="8" r="3" fill="#E8F0F7" stroke="' . $c . '" stroke-width="2"/>'
                . '<path d="M4 19c0-3 2.5-5 5-5s5 2 5 5" fill="none" stroke="' . $c . '" stroke-width="2" stroke-linecap="round"/>'
                . '<path d="M15 6h5v5h-3l-2 2V6z" fill="#E8F0F7" stroke="' . $c . '" stroke-width="1.8" stroke-linejoin="round"/>';
        } else if (preg_match('/tiempo|reloj|minuto|segundo|duraci/u', $k)) {
            $inner = '<circle cx="12" cy="12" r="9" fill="#E8F0F7" stroke="' . $c . '" stroke-width="2"/>'
                . '<path d="M12 7v5l3.5 2" fill="none" stroke="' . $c . '" stroke-width="2" stroke-linecap="round"/>';
        } else if (preg_match('/mascar|guante|pechera|vestim|calzado|cama/u', $k)) {
            $inner = '<path d="M12 3l7 3v5c0 5-3 8.5-7 10-4-1.5-7-5-7-10V6l7-3z" fill="#E8F0F7" stroke="' . $c . '" stroke-width="2"/>'
                . '<path d="M9 12l2 2 4-4" fill="none" stroke="#' . self::VERDE . '" stroke-width="2" stroke-linecap="round"/>';
        } else if (preg_match('/lista|registro|gu[ií]a|manual|ficha|document|bit[aá]cora/u', $k)) {
            $inner = '<rect x="5" y="3" width="14" height="18" rx="2" fill="#E8F0F7" stroke="' . $c . '" stroke-width="2"/>'
                . '<line x1="8" y1="8" x2="16" y2="8" stroke="' . $c . '" stroke-width="1.8"/>'
                . '<line x1="8" y1="12" x2="16" y2="12" stroke="' . $c . '" stroke-width="1.8"/>'
                . '<line x1="8" y1="16" x2="13" y2="16" stroke="' . $c . '" stroke-width="1.8"/>';
        } else if (preg_match('/video|c[aá]psula|reproduc/u', $k)) {
            $inner = '<rect x="3" y="5" width="18" height="14" rx="2" fill="#E8F0F7" stroke="' . $c . '" stroke-width="2"/>'
                . '<polygon points="10,9 16,12 10,15" fill="' . $c . '"/>';
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size
            . '" viewBox="0 0 24 24" style="vertical-align:middle;margin-right:6px;">' . $inner . '</svg>';
    }

    /**
     * Pictogramas guardados antes del 19-11-2026 (solo el icono, con la descripcion en «title»): vuelven a ser marcadores
     * [Pictograma: ...] para que se muestren con su nota. Funcion pura.
     *
     * @param string $html
     * @return string
     */
    public static function legacy_pictograms(string $html): string {
        if (strpos($html, 'class="cr-picto" title="') === false) {
            return $html;
        }
        return (string) preg_replace_callback('#<span class="cr-picto" title="([^"]*)">\s*<svg\b.*?</svg>\s*</span>#isu',
            static fn(array $m): string => '[Pictograma: ' . html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') . ']', $html);
    }

    /**
     * Marcadores [Pictograma: ...] como nota «Ilustración sugerida: ...» (Word y PDF, que no dibujan el SVG). Funcion pura.
     *
     * @param string $html
     * @return string
     */
    public static function pictogram_captions(string $html): string {
        $html = self::legacy_pictograms($html);
        return (string) preg_replace_callback('/\[(?:pictograma|[ií]cono)(?:\s*:\s*|\s+)([^\]]+)\]/iu',
            static fn(array $m): string => '<em style="color: #4A6A8A;">Ilustración sugerida: '
                . htmlspecialchars(trim($m[1]), ENT_QUOTES, 'UTF-8') . '</em>', $html);
    }

    /**
     * Marcadores de pictograma como pictograma SVG con su nota visible (pantalla y HTML para Moodle).
     *
     * @param string $html
     * @param int $size
     * @return string
     */
    public static function render_pictograms(string $html, int $size = 24): string {
        $html = self::legacy_pictograms($html);
        return (string) preg_replace_callback('/\[(?:pictograma|[ií]cono)(?:\s*:\s*|\s+)([^\]]+)\]/iu',
            static function(array $m) use ($size): string {
                $etiqueta = trim($m[1]);
                // Icono y nota visible: el icono solo no decia nada (pedido del 19-11-2026: material formal y entendible).
                return '<span class="cr-picto" style="display: inline-block; background: #F4F8FC; border: 1px solid #D5E4EF; '
                    . 'border-radius: 6px; padding: 2px 8px; margin: 2px 0; color: #36506B; font-size: 13px;">'
                    . self::svg_pictogram($etiqueta, $size) . '<em>Ilustración sugerida: '
                    . htmlspecialchars($etiqueta, ENT_QUOTES, 'UTF-8') . '</em></span>';
            }, $html);
    }

    /**
     * Limpia y empaqueta HTML compatible con el editor de Moodle: estilos en linea seguros, sin scripts,
     * sin iframes ni recursos externos, y con marcas [REVISAR: ...] destacadas.
     *
     * @param string $html Contenido HTML del material.
     * @param string $titulo Titulo del material.
     * @param string $codigo Codigo (ej. M1).
     * @return string HTML listo para pegar en vista de codigo fuente de Moodle.
     */
    public static function to_moodle_html(string $html, string $titulo = '', string $codigo = ''): string {
        // Quitar scripts, iframes, objetos y manejadores de eventos.
        $limpio = (string) preg_replace('#<(script|style|iframe|object|embed|form)\b[^>]*>.*?</\1>#isu', '', $html);
        $limpio = (string) preg_replace('/\s+on[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $limpio);
        $limpio = (string) preg_replace('/href\s*=\s*(["\'])\s*javascript:[^"\']*\1/iu', 'href="#"', $limpio);
        $limpio = self::render_pictograms($limpio, 22);
        // Destacar marcas [REVISAR] con estilo en linea seguro.
        $limpio = (string) preg_replace_callback('/\[REVISAR(?:\s*:\s*[^\]]+)?\]/u', static function(array $m): string {
            return '<span style="background:#fff3cd;color:#856404;border:1px solid #ffeeba;padding:1px 6px;'
                . 'border-radius:4px;font-weight:bold;">' . htmlspecialchars($m[0], ENT_QUOTES, 'UTF-8') . '</span>';
        }, $limpio);
        // Dar estilo en linea minimo a tablas para que se vean ordenadas en cualquier tema Moodle.
        // El diagrama de tarjetas conserva su estilo (sin cuadricula); las demas tablas, el estilo minimo.
        $limpio = (string) preg_replace_callback('/<table\b[^>]*>/i', static fn(array $m): string =>
            preg_match('/m2-(diagrama|tarjetas)/', $m[0], $clase)
                ? '<table class="m2-' . $clase[1] . '" style="width:100%;border-collapse:separate;border-spacing:6px;margin:12px 0;font-size:14px;">'
                : '<table style="width:100%;border-collapse:collapse;margin:12px 0;font-size:14px;">', $limpio);
        $limpio = (string) preg_replace('/<th\b([^>]*)>/i',
            '<th$1 style="background:#0b2447;color:#ffffff;padding:8px 10px;border:1px solid #c3cedd;text-align:left;">', $limpio);
        $limpio = (string) preg_replace('/<td\b([^>]*)>/i',
            '<td$1 style="padding:8px 10px;border:1px solid #c3cedd;vertical-align:top;">', $limpio);

        $cab = '';
        if ($titulo !== '') {
            $pref = $codigo !== '' ? htmlspecialchars($codigo, ENT_QUOTES, 'UTF-8') . ' · ' : '';
            $cab = '<div style="background:#0b2447;color:#ffffff;padding:14px 18px;border-radius:8px 8px 0 0;">'
                . '<h2 style="margin:0;color:#ffffff;font-size:20px;">' . $pref
                . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') . '</h2></div>';
        }
        return '<div style="font-family:Arial,Helvetica,sans-serif;color:#1e2533;line-height:1.6;'
            . 'border:1px solid #d5e4ef;border-radius:8px;overflow:hidden;">'
            . $cab . '<div style="padding:16px 20px;">' . trim($limpio) . '</div></div>';
    }

    // ------------------------------------------------------------------------------------------------------------
    // Cuestionarios desde una sola fuente JSON: Moodle GIFT, Google Apps Script y Word imprimible con pauta.
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Escapa caracteres especiales de la sintaxis GIFT de Moodle (~ = # { } :).
     *
     * @param string $texto
     * @return string
     */
    public static function escape_gift(string $texto): string {
        $limpio = trim(preg_replace('/\s+/u', ' ', $texto));
        return strtr($limpio, [
            '\\' => '\\\\',
            '~' => '\\~',
            '=' => '\\=',
            '#' => '\\#',
            '{' => '\\{',
            '}' => '\\}',
            ':' => '\\:',
        ]);
    }

    /**
     * Genera un cuestionario en formato GIFT valido (UTF-8) listo para importar al banco de preguntas de Moodle.
     *
     * @param array $quiz ['titulo' => string, 'preguntas' => [ ['enunciado', 'tipo', 'opciones', 'correcta', 'retroalimentacion'] ]].
     * @return string Texto GIFT.
     */
    public static function to_gift(array $quiz): string {
        $lineas = [];
        $titulo = trim((string) ($quiz['titulo'] ?? 'Cuestionario'));
        $lineas[] = '// ' . preg_replace('/\s+/u', ' ', $titulo);
        $lineas[] = '// Importar en Moodle: Banco de preguntas -> Importar -> Formato GIFT (UTF-8).';
        $lineas[] = '';

        $idx = 0;
        foreach ((array) ($quiz['preguntas'] ?? []) as $p) {
            if (!is_array($p) || trim((string) ($p['enunciado'] ?? '')) === '') {
                continue;
            }
            $idx++;
            $tipo = mb_strtolower(trim((string) ($p['tipo'] ?? 'multiple')), 'UTF-8');
            $enunciado = self::escape_gift((string) $p['enunciado']);
            $retro = trim((string) ($p['retroalimentacion'] ?? ''));
            $nom = 'P' . $idx;

            if ($tipo === 'vf' || $tipo === 'verdadero_falso' || $tipo === 'truefalse') {
                $corr = mb_strtolower(trim((string) ($p['correcta'] ?? 'verdadero')), 'UTF-8');
                $esv = in_array($corr, ['v', 't', 'true', 'verdadero', 'si', 'sí', '1'], true);
                $marca = $esv ? 'T' : 'F';
                $fb = $retro !== '' ? '#' . self::escape_gift($retro) : '';
                $lineas[] = "::{$nom}::{$enunciado} {{$marca}{$fb}}";
                $lineas[] = '';
                continue;
            }

            if ($tipo === 'corta' || $tipo === 'shortanswer') {
                $respuestas = is_array($p['correcta'] ?? null) ? $p['correcta'] : [trim((string) ($p['correcta'] ?? ''))];
                $bloques = [];
                foreach ($respuestas as $r) {
                    $r = trim((string) $r);
                    if ($r !== '') {
                        $bloques[] = '    =' . self::escape_gift($r) . ($retro !== '' ? ' #' . self::escape_gift($retro) : '');
                    }
                }
                if (!$bloques) {
                    $bloques[] = '    =Respuesta';
                }
                $lineas[] = "::{$nom}::{$enunciado} {\n" . implode("\n", $bloques) . "\n}";
                $lineas[] = '';
                continue;
            }

            // Opcion multiple (por defecto).
            $opciones = array_values(array_filter(array_map('strval', (array) ($p['opciones'] ?? [])),
                static fn($o) => trim($o) !== ''));
            $correcta = $p['correcta'] ?? 0;
            $corrIdx = 0;
            if (is_int($correcta) || ctype_digit((string) $correcta)) {
                $corrIdx = (int) $correcta;
                if ($corrIdx >= count($opciones)) {
                    $corrIdx = 0;
                }
            } else {
                $buscada = mb_strtolower(trim((string) $correcta), 'UTF-8');
                // Acepta letra A/B/C/D o coincidencia con el texto de la opcion.
                if (preg_match('/^[a-f]$/', $buscada)) {
                    $corrIdx = ord($buscada) - ord('a');
                } else {
                    foreach ($opciones as $i => $op) {
                        if (mb_strtolower(trim($op), 'UTF-8') === $buscada) {
                            $corrIdx = $i;
                            break;
                        }
                    }
                }
            }
            if (!$opciones) {
                $opciones = ['Opción A', 'Opción B'];
            }
            if (!isset($opciones[$corrIdx])) {
                $corrIdx = 0;
            }
            $bloques = [];
            foreach ($opciones as $i => $op) {
                $pref = ($i === $corrIdx) ? '=' : '~';
                $fb = ($i === $corrIdx && $retro !== '') ? ' #' . self::escape_gift($retro) : '';
                $bloques[] = '    ' . $pref . self::escape_gift($op) . $fb;
            }
            $lineas[] = "::{$nom}::{$enunciado} {\n" . implode("\n", $bloques) . "\n}";
            $lineas[] = '';
        }
        return trim(implode("\n", $lineas)) . "\n";
    }

    /**
     * Valida la sintaxis basica de un texto GIFT antes de entregarlo (llaves balanceadas y al menos una pregunta valida).
     *
     * @param string $gift
     * @return string[] Lista de errores detectados; vacio si es valido.
     */
    public static function validate_gift(string $gift): array {
        $errores = [];
        $sinComentarios = preg_replace('/^\/\/.*$/m', '', $gift);
        // Quitar llaves escapadas \{ y \} para contar las reales.
        $limpio = str_replace(['\\{', '\\}'], '', (string) $sinComentarios);
        $abiertas = substr_count($limpio, '{');
        $cerradas = substr_count($limpio, '}');
        if ($abiertas === 0) {
            $errores[] = 'No se encontró ninguna pregunta con bloque de respuestas { ... }.';
        }
        if ($abiertas !== $cerradas) {
            $errores[] = "Llaves desbalanceadas: {$abiertas} de apertura y {$cerradas} de cierre.";
        }
        preg_match_all('/\{([^{}]+)\}/s', $limpio, $bloques);
        foreach ($bloques[1] as $i => $b) {
            $t = trim($b);
            if (!preg_match('/^(?:T|F|TRUE|FALSE)(?:#|$)/i', $t) && strpos($t, '=') === false) {
                $errores[] = 'La pregunta ' . ($i + 1) . ' no marca ninguna respuesta correcta (=).';
            }
        }
        return $errores;
    }

    /**
     * Genera un script de Google Apps Script (.gs) listo para pegar y ejecutar en script.google.com, que crea el
     * Google Form en modo cuestionario con puntajes, respuestas correctas y retroalimentacion.
     *
     * @param array $quiz Misma estructura JSON de to_gift().
     * @param string $titulo
     * @return string Codigo JavaScript de Google Apps Script.
     */
    public static function to_google_apps_script(array $quiz, string $titulo = ''): string {
        $nom = trim($titulo !== '' ? $titulo : (string) ($quiz['titulo'] ?? 'Cuestionario de aprendizaje'));
        $desc = trim((string) ($quiz['descripcion'] ?? 'Cuestionario generado desde el Centro de producción de recursos.'));
        $jsStr = static function(string $s): string {
            return json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        };

        $l = [];
        $l[] = '/**';
        $l[] = ' * Cuestionario para Google Forms — ' . str_replace('*/', '', $nom);
        $l[] = ' *';
        $l[] = ' * Instrucciones en 3 pasos:';
        $l[] = ' *   1. Abre https://script.google.com y pulsa «Nuevo proyecto».';
        $l[] = ' *   2. Reemplaza todo el código por este archivo y guarda.';
        $l[] = ' *   3. Pulsa «Ejecutar» (función crearCuestionario), autoriza permisos y abre el enlace en el registro.';
        $l[] = ' */';
        $l[] = 'function crearCuestionario() {';
        $l[] = '  var form = FormApp.create(' . $jsStr($nom) . ');';
        $l[] = '  form.setDescription(' . $jsStr($desc) . ');';
        $l[] = '  form.setIsQuiz(true);';
        $l[] = '  form.setCollectEmail(true);';
        $l[] = '';

        $idx = 0;
        foreach ((array) ($quiz['preguntas'] ?? []) as $p) {
            if (!is_array($p) || trim((string) ($p['enunciado'] ?? '')) === '') {
                continue;
            }
            $idx++;
            $tipo = mb_strtolower(trim((string) ($p['tipo'] ?? 'multiple')), 'UTF-8');
            $enunciado = trim((string) $p['enunciado']);
            $puntaje = max(1, (int) ($p['puntaje'] ?? 1));
            $retro = trim((string) ($p['retroalimentacion'] ?? ''));

            if ($tipo === 'corta' || $tipo === 'shortanswer') {
                $l[] = "  var p{$idx} = form.addTextItem();";
                $l[] = "  p{$idx}.setTitle(" . $jsStr($enunciado) . ").setRequired(true);";
                $l[] = "  p{$idx}.setPoints({$puntaje});";
                if ($retro !== '') {
                    $l[] = "  p{$idx}.setGeneralFeedback(FormApp.createFeedback().setText(" . $jsStr($retro) . ').build());';
                }
                $l[] = '';
                continue;
            }

            $opciones = [];
            $corrIdx = 0;
            if ($tipo === 'vf' || $tipo === 'verdadero_falso' || $tipo === 'truefalse') {
                $opciones = ['Verdadero', 'Falso'];
                $corr = mb_strtolower(trim((string) ($p['correcta'] ?? 'verdadero')), 'UTF-8');
                $corrIdx = in_array($corr, ['v', 't', 'true', 'verdadero', 'si', 'sí', '1'], true) ? 0 : 1;
            } else {
                $opciones = array_values(array_filter(array_map('strval', (array) ($p['opciones'] ?? [])),
                    static fn($o) => trim($o) !== ''));
                if (!$opciones) {
                    $opciones = ['Opción A', 'Opción B'];
                }
                $correcta = $p['correcta'] ?? 0;
                if (is_int($correcta) || ctype_digit((string) $correcta)) {
                    $corrIdx = isset($opciones[(int) $correcta]) ? (int) $correcta : 0;
                } else {
                    $buscada = mb_strtolower(trim((string) $correcta), 'UTF-8');
                    if (preg_match('/^[a-f]$/', $buscada) && isset($opciones[ord($buscada) - ord('a')])) {
                        $corrIdx = ord($buscada) - ord('a');
                    } else {
                        foreach ($opciones as $i => $op) {
                            if (mb_strtolower(trim($op), 'UTF-8') === $buscada) {
                                $corrIdx = $i;
                                break;
                            }
                        }
                    }
                }
            }

            $l[] = "  var p{$idx} = form.addMultipleChoiceItem();";
            $l[] = "  p{$idx}.setTitle(" . $jsStr($enunciado) . ").setRequired(true);";
            $l[] = "  p{$idx}.setPoints({$puntaje});";
            $choices = [];
            foreach ($opciones as $i => $op) {
                $choices[] = "p{$idx}.createChoice(" . $jsStr(trim($op)) . ', ' . ($i === $corrIdx ? 'true' : 'false') . ')';
            }
            $l[] = "  p{$idx}.setChoices([" . implode(', ', $choices) . ']);';
            if ($retro !== '') {
                $l[] = "  var fb{$idx} = FormApp.createFeedback().setText(" . $jsStr($retro) . ').build();';
                $l[] = "  p{$idx}.setFeedbackForCorrect(fb{$idx});";
                $l[] = "  p{$idx}.setFeedbackForIncorrect(fb{$idx});";
            }
            $l[] = '';
        }

        $l[] = '  Logger.log("Formulario creado (edición): " + form.getEditUrl());';
        $l[] = '  Logger.log("Enlace para participantes: " + form.getPublishedUrl());';
        $l[] = '}';
        return implode("\n", $l) . "\n";
    }

    /**
     * Arma el HTML de un cuestionario imprimible con la pauta de respuestas por separado (una sola fuente JSON).
     *
     * @param array $quiz
     * @param string $titulo
     * @param string $pie Linea de pie institucional.
     * @return string HTML.
     */
    public static function to_printable_quiz_html(array $quiz, string $titulo = '', string $pie = ''): string {
        $e = static fn(string $t) => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        $nom = trim($titulo !== '' ? $titulo : (string) ($quiz['titulo'] ?? 'Cuestionario'));
        $html = '<h1>' . $e($nom) . '</h1>';
        if (!empty($quiz['descripcion'])) {
            $html .= '<p>' . $e((string) $quiz['descripcion']) . '</p>';
        }
        $html .= '<p><strong>Nombre participante:</strong> ____________________________________ &nbsp; <strong>Fecha:</strong> ______________</p>';
        $pauta = [];
        $idx = 0;
        foreach ((array) ($quiz['preguntas'] ?? []) as $p) {
            if (!is_array($p) || trim((string) ($p['enunciado'] ?? '')) === '') {
                continue;
            }
            $idx++;
            $tipo = mb_strtolower(trim((string) ($p['tipo'] ?? 'multiple')), 'UTF-8');
            $html .= '<p><strong>' . $idx . '. ' . $e((string) $p['enunciado']) . '</strong></p>';
            $correctaTxt = '';
            if ($tipo === 'vf' || $tipo === 'verdadero_falso' || $tipo === 'truefalse') {
                $html .= '<ul><li>☐ Verdadero</li><li>☐ Falso</li></ul>';
                $corr = mb_strtolower(trim((string) ($p['correcta'] ?? 'verdadero')), 'UTF-8');
                $correctaTxt = in_array($corr, ['v', 't', 'true', 'verdadero', 'si', 'sí', '1'], true) ? 'Verdadero' : 'Falso';
            } else if ($tipo === 'corta' || $tipo === 'shortanswer') {
                $html .= '<p>Respuesta: ____________________________________________________________</p>';
                $correctaTxt = is_array($p['correcta'] ?? null)
                    ? implode(' / ', array_map('strval', $p['correcta'])) : trim((string) ($p['correcta'] ?? ''));
            } else {
                $opciones = array_values(array_filter(array_map('strval', (array) ($p['opciones'] ?? [])),
                    static fn($o) => trim($o) !== ''));
                $letras = ['a', 'b', 'c', 'd', 'e', 'f'];
                $html .= '<ul>';
                foreach ($opciones as $i => $op) {
                    $letra = $letras[$i] ?? (string) ($i + 1);
                    $html .= '<li>☐ ' . $letra . ') ' . $e($op) . '</li>';
                }
                $html .= '</ul>';
                $correcta = $p['correcta'] ?? 0;
                $ci = (is_int($correcta) || ctype_digit((string) $correcta)) ? (int) $correcta : 0;
                if (!is_int($correcta) && !ctype_digit((string) $correcta)) {
                    $b = mb_strtolower(trim((string) $correcta), 'UTF-8');
                    if (preg_match('/^[a-f]$/', $b)) {
                        $ci = ord($b) - ord('a');
                    } else {
                        foreach ($opciones as $i => $op) {
                            if (mb_strtolower(trim($op), 'UTF-8') === $b) {
                                $ci = $i;
                                break;
                            }
                        }
                    }
                }
                $correctaTxt = ($letras[$ci] ?? '') . ') ' . ($opciones[$ci] ?? '');
            }
            $pauta[] = [$idx, $correctaTxt, trim((string) ($p['retroalimentacion'] ?? ''))];
        }
        $html .= '<hr><h2>Pauta de respuestas (uso exclusivo de la persona facilitadora)</h2>'
            . '<table><thead><tr><th>N.º</th><th>Respuesta correcta</th><th>Retroalimentación</th></tr></thead><tbody>';
        foreach ($pauta as [$n, $resp, $retro]) {
            $html .= '<tr><td>' . $n . '</td><td>' . $e($resp) . '</td><td>' . $e($retro) . '</td></tr>';
        }
        $html .= '</tbody></table>';
        if ($pie !== '') {
            $html .= '<p><em>' . $e($pie) . '</em></p>';
        }
        return $html;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Word (.docx)
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Genera un archivo Word (.docx) editable con el formato institucional Interfases.
     *
     * @param string $ruta Ruta de salida .docx.
     * @param string $titulo
     * @param string $subtitulo
     * @param string $html
     * @param string $logo Ruta a pix/logo_interfases.png (opcional).
     * @return bool
     */
    public static function build_docx(string $ruta, string $titulo, string $subtitulo, string $html, string $logo = ''): bool {
        $html = diseno::normalize_product(self::pictogram_captions($html));
        if (class_exists('\local_chatbot\documento_final')) {
            $ok = documento_final::build($ruta, [
                'portada' => false,
                'encabezado' => trim($titulo . ($subtitulo !== '' ? ' · ' . $subtitulo : '')),
            ], '<h1>' . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') . '</h1>' . $html, $logo);
            if ($ok) {
                return true;
            }
        }
        return diseno::html_to_docx($ruta, $titulo, $subtitulo, $html);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Excel (.xlsx): PhpSpreadsheet (incluido en Moodle 5.2) con respaldo OOXML puro via ZipArchive.
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Extrae tablas de un HTML para exportarlas como hojas de Excel cuando no hay JSON estructurado.
     *
     * @param string $html
     * @param string $titulo
     * @return array Lista de ['nombre' => string, 'encabezados' => string[], 'filas' => string[][]].
     */
    public static function html_to_sheets(string $html, string $titulo = 'Planilla'): array {
        $hojas = [];
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>');
        libxml_clear_errors();
        $tablas = $dom->getElementsByTagName('table');
        $idx = 0;
        foreach ($tablas as $tabla) {
            $idx++;
            $enc = [];
            $filas = [];
            foreach ($tabla->getElementsByTagName('tr') as $tr) {
                $celdas = [];
                $esCab = false;
                foreach ($tr->childNodes as $c) {
                    if ($c instanceof \DOMElement && ($c->nodeName === 'td' || $c->nodeName === 'th')) {
                        if ($c->nodeName === 'th') {
                            $esCab = true;
                        }
                        $celdas[] = trim(preg_replace('/\s+/u', ' ', $c->textContent));
                    }
                }
                if (!$celdas) {
                    continue;
                }
                if (!$enc && $esCab) {
                    $enc = $celdas;
                } else {
                    $filas[] = $celdas;
                }
            }
            if ($enc || $filas) {
                $hojas[] = ['nombre' => 'Tabla ' . $idx, 'encabezados' => $enc, 'filas' => $filas];
            }
        }
        if (!$hojas) {
            $lineas = array_values(array_filter(array_map('trim',
                explode("\n", html_entity_decode(strip_tags(preg_replace('#</(p|li|h[1-6]|tr|div)>#i', "\n", $html)),
                    ENT_QUOTES | ENT_HTML5, 'UTF-8'))), static fn($x) => $x !== ''));
            $filas = array_map(static fn($x) => [$x], $lineas);
            $hojas[] = ['nombre' => mb_substr($titulo, 0, 28, 'UTF-8'), 'encabezados' => [$titulo], 'filas' => $filas];
        }
        return $hojas;
    }

    /**
     * Genera un archivo Excel (.xlsx) editable real. Usa PhpSpreadsheet si esta disponible en Moodle;
     * si no (p. ej. verificador CLI puro), escribe OOXML .xlsx valido directamente con ZipArchive.
     *
     * @param string $ruta Ruta de salida .xlsx.
     * @param array $hojas Lista de ['nombre' => string, 'encabezados' => string[], 'filas' => array[]].
     * @param string $titulo Titulo general.
     * @return bool
     */
    public static function build_xlsx(string $ruta, array $hojas, string $titulo = 'Planilla'): bool {
        if (!$hojas) {
            $hojas = [['nombre' => 'Datos', 'encabezados' => [$titulo], 'filas' => []]];
        }
        // Intentar PhpSpreadsheet incluido en Moodle 5.2.
        if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            $autoload = __DIR__ . '/../../../../lib/phpspreadsheet/vendor/autoload.php';
            if (is_file($autoload)) {
                require_once($autoload);
            }
        }
        if (class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            try {
                $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
                $book->getProperties()->setTitle($titulo)->setCreator('Centro de producción de recursos');
                foreach (array_values($hojas) as $idx => $h) {
                    $sheet = $idx === 0 ? $book->getActiveSheet() : $book->createSheet($idx);
                    $sheetName = self::safe_sheet_name((string) ($h['nombre'] ?? ('Hoja ' . ($idx + 1))), $idx + 1);
                    $sheet->setTitle($sheetName);
                    $row = 1;
                    $enc = (array) ($h['encabezados'] ?? []);
                    if ($enc) {
                        foreach (array_values($enc) as $c => $val) {
                            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c + 1);
                            $sheet->setCellValue($col . $row, (string) $val);
                            $sheet->getStyle($col . $row)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
                            $sheet->getStyle($col . $row)->getFill()
                                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                                ->getStartColor()->setARGB('FF' . self::NAVY);
                        }
                        $row++;
                    }
                    foreach ((array) ($h['filas'] ?? []) as $fila) {
                        foreach (array_values((array) $fila) as $c => $val) {
                            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c + 1);
                            $sheet->setCellValue($col . $row, is_scalar($val) ? $val : '');
                        }
                        $row++;
                    }
                    $maxCol = max(count($enc), ...array_map(static fn($f) => count((array) $f), (array) ($h['filas'] ?? [[]])));
                    for ($c = 1; $c <= max(1, $maxCol); $c++) {
                        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
                        $sheet->getColumnDimension($col)->setAutoSize(true);
                    }
                }
                $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book);
                $writer->save($ruta);
                return is_file($ruta) && filesize($ruta) > 0;
            } catch (\Throwable $e) {
                // Caer al respaldo OOXML puro.
            }
        }
        return self::build_xlsx_ooxml($ruta, $hojas);
    }

    /**
     * Respaldo OOXML puro (.xlsx) con ZipArchive sin dependencias externas.
     *
     * @param string $ruta
     * @param array $hojas
     * @return bool
     */
    private static function build_xlsx_ooxml(string $ruta, array $hojas): bool {
        if (!class_exists('\ZipArchive')) {
            return false;
        }
        $zip = new \ZipArchive();
        if ($zip->open($ruta, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return false;
        }
        $esc = static fn(string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $colName = static function(int $idx): string {
            $name = '';
            $n = $idx;
            while ($n > 0) {
                $mod = ($n - 1) % 26;
                $name = chr(65 + $mod) . $name;
                $n = intdiv($n - 1, 26);
            }
            return $name;
        };

        $sheetsXml = [];
        $sheetOverrides = '';
        $wbSheets = '';
        $wbRels = '';
        foreach (array_values($hojas) as $i => $h) {
            $sid = $i + 1;
            $sname = self::safe_sheet_name((string) ($h['nombre'] ?? ('Hoja ' . $sid)), $sid);
            $sheetOverrides .= '<Override PartName="/xl/worksheets/sheet' . $sid . '.xml" '
                . 'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $wbSheets .= '<sheet name="' . $esc($sname) . '" sheetId="' . $sid . '" r:id="rId' . $sid . '"/>';
            $wbRels .= '<Relationship Id="rId' . $sid . '" '
                . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" '
                . 'Target="worksheets/sheet' . $sid . '.xml"/>';

            $rows = [];
            $rnum = 1;
            $todas = [];
            if (!empty($h['encabezados'])) {
                $todas[] = ['vals' => (array) $h['encabezados'], 'style' => 1];
            }
            foreach ((array) ($h['filas'] ?? []) as $fila) {
                $todas[] = ['vals' => (array) $fila, 'style' => 0];
            }
            foreach ($todas as $item) {
                $cells = '';
                foreach (array_values($item['vals']) as $c => $val) {
                    $ref = $colName($c + 1) . $rnum;
                    $sattr = $item['style'] ? ' s="1"' : '';
                    $str = is_scalar($val) ? (string) $val : '';
                    if ($str !== '' && $str[0] === '=') {
                        $cells .= '<c r="' . $ref . '"' . $sattr . '><f>' . $esc(substr($str, 1)) . '</f></c>';
                    } else if (is_numeric($str) && !preg_match('/^0\d/', $str)) {
                        $cells .= '<c r="' . $ref . '"' . $sattr . '><v>' . $str . '</v></c>';
                    } else {
                        $cells .= '<c r="' . $ref . '" t="inlineStr"' . $sattr . '><is><t>' . $esc($str) . '</t></is></c>';
                    }
                }
                $rows[] = '<row r="' . $rnum . '">' . $cells . '</row>';
                $rnum++;
            }
            $sheetsXml[$sid] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
                . implode('', $rows) . '</sheetData></worksheet>';
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $sheetOverrides . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
            . $wbSheets . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $wbRels
            . '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF' . self::NAVY . '"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
            . '</styleSheet>');
        foreach ($sheetsXml as $sid => $xml) {
            $zip->addFromString('xl/worksheets/sheet' . $sid . '.xml', $xml);
        }
        $zip->close();
        return is_file($ruta) && filesize($ruta) > 0;
    }

    /**
     * Limpia el nombre de hoja Excel (max 31 caracteres, sin : \ / ? * [ ]).
     *
     * @param string $nombre
     * @param int $idx
     * @return string
     */
    private static function safe_sheet_name(string $nombre, int $idx): string {
        $limpio = trim((string) preg_replace('/[:\\\\\/?*\[\]]/u', ' ', $nombre));
        $limpio = mb_substr($limpio, 0, 31, 'UTF-8');
        return $limpio !== '' ? $limpio : ('Hoja ' . $idx);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Presentaciones (.pptx): generador OOXML puro en PHP (plantilla institucional Interfases + notas del facilitador).
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Convierte un HTML en diapositivas estructuradas (cuando no hay JSON de diapositivas).
     *
     * @param string $html
     * @param string $titulo
     * @return array Lista de ['titulo' => string, 'puntos' => string[], 'notas' => string].
     */
    public static function html_to_slides(string $html, string $titulo): array {
        $slides = [];
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>');
        libxml_clear_errors();
        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            return [['titulo' => $titulo, 'puntos' => [], 'notas' => '']];
        }
        $actual = null;
        foreach ($body->childNodes as $nodo) {
            if (!$nodo instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($nodo->nodeName);
            $txt = trim(preg_replace('/\s+/u', ' ', $nodo->textContent));
            if ($txt === '') {
                continue;
            }
            if (in_array($tag, ['h1', 'h2', 'h3'], true)) {
                if ($actual !== null) {
                    $slides[] = $actual;
                }
                $actual = ['titulo' => $txt, 'puntos' => [], 'notas' => ''];
            } else {
                if ($actual === null) {
                    $actual = ['titulo' => $titulo, 'puntos' => [], 'notas' => ''];
                }
                if ($tag === 'ul' || $tag === 'ol') {
                    foreach ($nodo->getElementsByTagName('li') as $li) {
                        $t = trim(preg_replace('/\s+/u', ' ', $li->textContent));
                        if ($t !== '') {
                            $actual['puntos'][] = $t;
                        }
                    }
                } else if ($tag === 'table') {
                    foreach ($nodo->getElementsByTagName('tr') as $tr) {
                        $celdas = [];
                        foreach ($tr->childNodes as $td) {
                            if ($td instanceof \DOMElement && ($td->nodeName === 'td' || $td->nodeName === 'th')) {
                                $celdas[] = trim(preg_replace('/\s+/u', ' ', $td->textContent));
                            }
                        }
                        if ($celdas) {
                            $actual['puntos'][] = implode(' · ', $celdas);
                        }
                    }
                } else {
                    $actual['puntos'][] = $txt;
                }
            }
        }
        if ($actual !== null) {
            $slides[] = $actual;
        }
        return $slides ?: [['titulo' => $titulo, 'puntos' => [], 'notas' => '']];
    }

    /**
     * Genera una presentacion PowerPoint (.pptx) editable en formato 16:9 con portada Interfases,
     * diapositivas estructuradas y notas para la persona facilitadora.
     *
     * @param string $ruta Ruta de salida .pptx.
     * @param string $titulo
     * @param string $subtitulo
     * @param array $diapositivas Lista de ['titulo' => string, 'puntos' => string[], 'notas' => string].
     * @param string $pie Pie de diapositiva (ej. codigo + aviso IA).
     * @return bool
     */
    public static function build_pptx(string $ruta, string $titulo, string $subtitulo, array $diapositivas,
            string $pie = ''): bool {
        if (!class_exists('\ZipArchive')) {
            return false;
        }
        $zip = new \ZipArchive();
        if ($zip->open($ruta, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return false;
        }
        $esc = static fn(string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        // Diapositiva 1 = portada; siguientes = contenido.
        $todas = array_merge([['portada' => true, 'titulo' => $titulo, 'subtitulo' => $subtitulo, 'notas' => '']],
            array_values($diapositivas));

        $overrides = '';
        $presRels = '<Relationship Id="rIdMaster" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>';
        $sldIdLst = '';
        foreach ($todas as $idx => $s) {
            $n = $idx + 1;
            $rid = 'rId' . ($n + 1);
            $overrides .= '<Override PartName="/ppt/slides/slide' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>';
            $presRels .= '<Relationship Id="' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide' . $n . '.xml"/>';
            $sldIdLst .= '<p:sldId id="' . (255 + $n) . '" r:id="' . $rid . '"/>';

            $notas = trim((string) ($s['notas'] ?? ''));
            $relNotas = '';
            if ($notas !== '') {
                $overrides .= '<Override PartName="/ppt/notesSlides/notesSlide' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.notesSlide+xml"/>';
                $relNotas = '<Relationship Id="rIdNotes" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/notesSlide" Target="../notesSlides/notesSlide' . $n . '.xml"/>';
                $zip->addFromString('ppt/notesSlides/notesSlide' . $n . '.xml', self::pptx_notes_xml($esc($notas)));
                $zip->addFromString('ppt/notesSlides/_rels/notesSlide' . $n . '.xml.rels',
                    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="../slides/slide' . $n . '.xml"/>'
                    . '</Relationships>');
            }

            $zip->addFromString('ppt/slides/slide' . $n . '.xml', self::pptx_slide_xml($s, $n, count($todas), $pie, $esc));
            $zip->addFromString('ppt/slides/_rels/slide' . $n . '.xml.rels',
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>'
                . $relNotas . '</Relationships>');
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>'
            . '<Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>'
            . '<Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>'
            . '<Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>'
            . $overrides . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>'
            . '</Relationships>');
        $zip->addFromString('ppt/presentation.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:presentation xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            . 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
            . '<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rIdMaster"/></p:sldMasterIdLst>'
            . '<p:sldIdLst>' . $sldIdLst . '</p:sldIdLst>'
            . '<p:sldSz cx="12192000" cy="6858000"/>'
            . '<p:notesSz cx="6858000" cy="9144000"/>'
            . '</p:presentation>');
        $zip->addFromString('ppt/_rels/presentation.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $presRels . '</Relationships>');
        $zip->addFromString('ppt/slideMasters/slideMaster1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:sldMaster xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            . 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
            . '<p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            . '<p:grpSpPr/></p:spTree></p:cSld>'
            . '<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" '
            . 'accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>'
            . '<p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1"/></p:sldLayoutIdLst></p:sldMaster>');
        $zip->addFromString('ppt/slideMasters/_rels/slideMaster1.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme1.xml"/>'
            . '</Relationships>');
        $zip->addFromString('ppt/slideLayouts/slideLayout1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:sldLayout xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            . 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" type="blank">'
            . '<p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            . '<p:grpSpPr/></p:spTree></p:cSld></p:sldLayout>');
        $zip->addFromString('ppt/slideLayouts/_rels/slideLayout1.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/>'
            . '</Relationships>');
        $zip->addFromString('ppt/theme/theme1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="Interfases">'
            . '<a:themeElements><a:clrScheme name="Interfases">'
            . '<a:dk1><a:srgbClr val="1E2533"/></a:dk1><a:lt1><a:srgbClr val="FFFFFF"/></a:lt1>'
            . '<a:dk2><a:srgbClr val="0B2447"/></a:dk2><a:lt2><a:srgbClr val="F4F7FB"/></a:lt2>'
            . '<a:accent1><a:srgbClr val="2E5A88"/></a:accent1><a:accent2><a:srgbClr val="267A43"/></a:accent2>'
            . '<a:accent3><a:srgbClr val="B45309"/></a:accent3><a:accent4><a:srgbClr val="5B6577"/></a:accent4>'
            . '<a:accent5><a:srgbClr val="17365D"/></a:accent5><a:accent6><a:srgbClr val="C3CEDD"/></a:accent6>'
            . '<a:hlink><a:srgbClr val="2E5A88"/></a:hlink><a:folHlink><a:srgbClr val="17365D"/></a:folHlink>'
            . '</a:clrScheme><a:fontScheme name="Interfases">'
            . '<a:majorFont><a:latin typeface="Calibri"/><a:ea typeface=""/><a:cs typeface=""/></a:majorFont>'
            . '<a:minorFont><a:latin typeface="Calibri"/><a:ea typeface=""/><a:cs typeface=""/></a:minorFont>'
            . '</a:fontScheme><a:fmtScheme name="Interfases"><a:fillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:fillStyleLst>'
            . '<a:lnStyleLst><a:ln w="9525"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln></a:lnStyleLst>'
            . '<a:effectStyleLst><a:effectStyle><a:effectLst/></a:effectStyle></a:effectStyleLst>'
            . '<a:bgFillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:bgFillStyleLst>'
            . '</a:fmtScheme></a:themeElements></a:theme>');
        $zip->close();
        return is_file($ruta) && filesize($ruta) > 0;
    }

    /**
     * XML de una diapositiva PPTX (portada o diapositiva de contenido).
     */
    private static function pptx_slide_xml(array $s, int $num, int $total, string $pie, callable $esc): string {
        $box = static function(int $id, string $name, int $x, int $y, int $cx, int $cy, string $fillHex, string $parrafos): string {
            $fill = $fillHex !== '' ? '<a:solidFill><a:srgbClr val="' . $fillHex . '"/></a:solidFill>' : '<a:noFill/>';
            return '<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="' . $name . '"/><p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>'
                . '<p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
                . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom>' . $fill . '<a:ln><a:noFill/></a:ln></p:spPr>'
                . '<p:txBody><a:bodyPr wrap="square" lIns="137160" tIns="91440" rIns="137160" bIns="91440"/><a:lstStyle/>'
                . $parrafos . '</p:txBody></p:sp>';
        };
        $p = static function(string $txt, int $sz, string $color, bool $bold = false, bool $bullet = false) use ($esc): string {
            $bu = $bullet ? '<a:buChar char="•"/>' : '<a:buNone/>';
            $mar = $bullet ? ' marL="285750" indent="-228600"' : '';
            return '<a:p><a:pPr' . $mar . '><a:spcAft><a:spcPts val="600"/></a:spcAft>' . $bu . '</a:pPr>'
                . '<a:r><a:rPr lang="es-CL" sz="' . $sz . '"' . ($bold ? ' b="1"' : '') . '>'
                . '<a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill></a:rPr><a:t>' . $esc($txt) . '</a:t></a:r></a:p>';
        };

        $shapes = '';
        if (!empty($s['portada'])) {
            $shapes .= $box(2, 'Fondo', 0, 0, 12192000, 6858000, self::NAVY, '<a:p/>');
            $shapes .= $box(3, 'Barra', 0, 5600000, 12192000, 140000, self::AZUL, '<a:p/>');
            $shapes .= $box(4, 'Titulo', 762000, 1800000, 10668000, 2200000, '',
                $p((string) ($s['titulo'] ?? ''), 3400, 'FFFFFF', true)
                . (!empty($s['subtitulo']) ? $p((string) $s['subtitulo'], 1800, 'D5E4EF', false) : ''));
            if ($pie !== '') {
                $shapes .= $box(5, 'Pie', 762000, 5950000, 10668000, 600000, '', $p($pie, 1100, 'C3CEDD'));
            }
        } else {
            $shapes .= $box(2, 'Cabecera', 0, 0, 12192000, 1150000, self::NAVY,
                $p((string) ($s['titulo'] ?? ''), 2400, 'FFFFFF', true));
            $cuerpo = '';
            foreach (array_slice((array) ($s['puntos'] ?? []), 0, 12) as $pt) {
                $t = trim((string) $pt);
                if ($t !== '') {
                    $cuerpo .= $p($t, 1600, self::TEXTO, false, true);
                }
            }
            if ($cuerpo === '') {
                $cuerpo = '<a:p/>';
            }
            $shapes .= $box(3, 'Cuerpo', 609600, 1400000, 10972800, 4700000, self::FONDO, $cuerpo);
            $pieTxt = trim(($pie !== '' ? $pie . '   |   ' : '') . $num . ' / ' . $total);
            $shapes .= $box(4, 'Pie', 609600, 6280000, 10972800, 420000, '', $p($pieTxt, 1000, '5B6577'));
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            . 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
            . '<p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            . '<p:grpSpPr/>' . $shapes . '</p:spTree></p:cSld></p:sld>';
    }

    /**
     * XML de notas del facilitador para una diapositiva PPTX.
     */
    private static function pptx_notes_xml(string $escapedNotas): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:notes xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            . 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
            . '<p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            . '<p:grpSpPr/><p:sp><p:nvSpPr><p:cNvPr id="2" name="Notas"/><p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>'
            . '<p:spPr><a:xfrm><a:off x="685800" y="4343400"/><a:ext cx="5486400" cy="4114800"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr>'
            . '<p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:r><a:rPr lang="es-CL" sz="1200"/><a:t>'
            . $escapedNotas . '</a:t></a:r></a:p></p:txBody></p:sp></p:spTree></p:cSld></p:notes>';
    }

    // ------------------------------------------------------------------------------------------------------------
    // PDF (.pdf): TCPDF incluido en Moodle 5.2, soportando A4, Carta, A3, A2 y tarjetas 9x6 cm con pictogramas SVG.
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Genera un archivo PDF en el tamano de hoja correspondiente al formato del material (A4, Carta, A3, A2 o tarjeta 9x6 cm).
     *
     * @param string $ruta Ruta de salida .pdf.
     * @param string $titulo
     * @param string $subtitulo
     * @param string $html
     * @param string $formato Texto del formato (ej. «PDF A2», «PDF tarjeta 9x6 cm»).
     * @param string $impresion Formato preferido ('carta' o 'a4').
     * @return bool
     */
    public static function build_pdf(string $ruta, string $titulo, string $subtitulo, string $html,
            string $formato = 'PDF A4', string $impresion = 'carta'): bool {
        $spec = self::parse_pdf_spec($formato, $impresion);
        if (!class_exists('\TCPDF') && extension_loaded('curl')) {
            if (!defined('K_PATH_CACHE')) {
                define('K_PATH_CACHE', rtrim(sys_get_temp_dir(), '/\\') . '/');
            }
            $tcpdf = __DIR__ . '/../../../lib/tcpdf/tcpdf.php';
            if (is_file($tcpdf)) {
                require_once($tcpdf);
            }
        }
        if (!class_exists('\TCPDF')) {
            return self::build_pdf_minimal($ruta, $titulo, $subtitulo, $html, $spec);
        }
        $pdf = new \TCPDF($spec['orientacion'], 'mm', $spec['tamano'], true, 'UTF-8', false);
        $pdf->SetCreator('Centro de producción de recursos');
        $pdf->SetTitle($titulo);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter($spec['tipo'] !== 'tarjeta');

        if ($spec['tipo'] === 'tarjeta') {
            $pdf->SetMargins(5, 5, 5);
            $pdf->SetAutoPageBreak(true, 5);
            $fontSize = 14;
        } else if ($spec['tipo'] === 'afiche') {
            $pdf->SetMargins(18, 18, 18);
            $pdf->SetAutoPageBreak(true, 18);
            $fontSize = 18;
        } else if ($spec['tipo'] === 'tablero') {
            $pdf->SetMargins(14, 14, 14);
            $pdf->SetAutoPageBreak(true, 14);
            $fontSize = 13;
        } else {
            $pdf->SetMargins(16, 16, 16);
            $pdf->SetAutoPageBreak(true, 16);
            $fontSize = 11;
        }
        $pdf->SetFont('freesans', '', $fontSize);
        $pdf->AddPage();

        // TCPDF no renderiza SVG inline dentro de writeHTML: reemplazamos marcadores por etiqueta limpia destacada.
        $limpio = diseno::normalize_product(self::pictogram_captions($html));
        // La casilla ☐ no esta en freesans (salia «?»): ZapfDingbats trae una casilla («o»).
        $limpio = str_replace('☐', '<span style="font-family:zapfdingbats;">o</span>', $limpio);
        $limpio = diagrama::pdf_flows($limpio);
        $limpio = (string) preg_replace_callback('/\[REVISAR(?:\s*:\s*[^\]]+)?\]/u', static function(array $m): string {
            return '<span style="background-color:#fff3cd;color:#856404;font-weight:bold;">'
                . htmlspecialchars($m[0], ENT_QUOTES, 'UTF-8') . '</span>';
        }, $limpio);
        $limpio = (string) preg_replace_callback('/<table\b[^>]*>/i', static fn(array $m): string =>
            stripos($m[0], 'm2-diagrama') !== false
                ? '<table class="m2-diagrama" border="0" cellpadding="8" cellspacing="6">'
                : (stripos($m[0], 'm2-tarjetas') !== false ? '<table class="m2-tarjetas" border="0" cellpadding="8" cellspacing="6">'
                : '<table border="1" cellpadding="5" cellspacing="0" style="border-color:#c3cedd;">'), $limpio);
        $limpio = (string) preg_replace('/<th\b([^>]*)>/i',
            '<th$1 style="background-color:#0b2447;color:#ffffff;font-weight:bold;">', $limpio);

        $cab = '<div style="background-color:#0b2447;color:#ffffff;padding:8px;"><strong>'
            . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') . '</strong>'
            . ($subtitulo !== '' && $spec['tipo'] !== 'tarjeta'
                ? '<br><span style="font-size:80%;color:#d5e4ef;">' . htmlspecialchars($subtitulo, ENT_QUOTES, 'UTF-8') . '</span>'
                : '')
            . '</div><br>';
        $pdf->writeHTML($cab . $limpio, true, false, true, false, '');
        $pdf->Output($ruta, 'F');
        return is_file($ruta) && filesize($ruta) > 0;
    }

    /**
     * Respaldo PDF 1.4 puro en PHP para entornos CLI sin TCPDF/curl.
     *
     * @param string $ruta
     * @param string $titulo
     * @param string $subtitulo
     * @param string $html
     * @param array $spec Resultado de parse_pdf_spec().
     * @return bool
     */
    private static function build_pdf_minimal(string $ruta, string $titulo, string $subtitulo, string $html, array $spec): bool {
        $mmToPt = 2.83464567;
        $sizes = ['A4' => [210, 297], 'LETTER' => [215.9, 279.4], 'A3' => [297, 420], 'A2' => [420, 594]];
        $dim = is_array($spec['tamano']) ? $spec['tamano'] : ($sizes[$spec['tamano']] ?? [210, 297]);
        [$wMm, $hMm] = $spec['orientacion'] === 'L' ? [max($dim[0], $dim[1]), min($dim[0], $dim[1])] : $dim;
        $w = round($wMm * $mmToPt, 2);
        $h = round($hMm * $mmToPt, 2);

        $plano = trim($titulo . ($subtitulo !== '' ? "\n" . $subtitulo : '') . "\n\n"
            . html_entity_decode(strip_tags(preg_replace('#</(p|li|h[1-6]|tr|div|br)>#i', "\n", $html)),
                ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $ascii = (string) @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $plano);
        $lineas = array_slice(explode("\n", str_replace("\r", '', $ascii)), 0, 50);

        $stream = "BT\n/F1 12 Tf\n36 " . ($h - 40) . " Td\n15 TL\n";
        foreach ($lineas as $idx => $ln) {
            $esc = strtr(trim($ln), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
            $stream .= ($idx === 0 ? '' : "T*\n") . '(' . $esc . ") Tj\n";
        }
        $stream .= "ET\n";

        $objs = [
            1 => "<< /Type /Catalog /Pages 2 0 R >>",
            2 => "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
            3 => "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$w} {$h}] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>",
            4 => "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>",
            5 => "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objs as $n => $body) {
            $offsets[$n] = strlen($pdf);
            $pdf .= "{$n} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= count($objs); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
        return file_put_contents($ruta, $pdf) !== false && filesize($ruta) > 0;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Empaquetado del repertorio (.zip) y lectura de archivos de referencia (.xlsx).
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Empaqueta varios archivos en un .zip descargable (Etapa 12: repertorio completo).
     *
     * @param string $zipPath Ruta del .zip de salida.
     * @param array $archivos Mapa nombre_en_zip => ['ruta' => string] o ['texto' => string].
     * @return bool
     */
    public static function build_repertory_zip(string $zipPath, array $archivos): bool {
        if (!class_exists('\ZipArchive') || !$archivos) {
            return false;
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return false;
        }
        foreach ($archivos as $nombre => $origen) {
            $limpio = ltrim(str_replace(['..', '\\'], ['', '/'], (string) $nombre), '/');
            if ($limpio === '') {
                continue;
            }
            if (isset($origen['ruta']) && is_file((string) $origen['ruta'])) {
                $zip->addFile((string) $origen['ruta'], $limpio);
            } else if (isset($origen['texto'])) {
                $zip->addFromString($limpio, (string) $origen['texto']);
            }
        }
        $zip->close();
        return is_file($zipPath) && filesize($zipPath) > 0;
    }

    /**
     * Extrae texto legible de una planilla Excel (.xlsx) subida como material tecnico de referencia.
     * Funciona con PhpSpreadsheet y tambien en PHP puro (ZipArchive + XML) sin dependencias.
     *
     * @param string $ruta Ruta al archivo .xlsx.
     * @param int $maxChars Limite de caracteres.
     * @return string|null
     */
    public static function xlsx_text(string $ruta, int $maxChars = 40000): ?string {
        if (!is_file($ruta)) {
            return null;
        }
        if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
            $autoload = __DIR__ . '/../../../../lib/phpspreadsheet/vendor/autoload.php';
            if (is_file($autoload)) {
                require_once($autoload);
            }
        }
        if (class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
            try {
                $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($ruta);
                $partes = [];
                foreach ($book->getWorksheetIterator() as $sheet) {
                    $partes[] = '=== HOJA: ' . $sheet->getTitle() . ' ===';
                    foreach ($sheet->toArray(null, true, true, false) as $fila) {
                        $vals = array_map(static fn($v) => trim((string) $v), $fila);
                        if (implode('', $vals) !== '') {
                            $partes[] = implode(' | ', $vals);
                        }
                    }
                }
                return mb_substr(implode("\n", $partes), 0, $maxChars, 'UTF-8');
            } catch (\Throwable $e) {
                // Caer al lector XML puro.
            }
        }
        if (!class_exists('\ZipArchive')) {
            return null;
        }
        $zip = new \ZipArchive();
        if ($zip->open($ruta) !== true) {
            return null;
        }
        $shared = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml !== false) {
            $dom = new \DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadXML($ssXml);
            libxml_clear_errors();
            foreach ($dom->getElementsByTagName('si') as $si) {
                $shared[] = trim($si->textContent);
            }
        }
        $partes = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!preg_match('#^xl/worksheets/sheet\d+\.xml$#', (string) $name)) {
                continue;
            }
            $xml = $zip->getFromIndex($i);
            if ($xml === false) {
                continue;
            }
            $dom = new \DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadXML($xml);
            libxml_clear_errors();
            $partes[] = '=== ' . basename((string) $name, '.xml') . ' ===';
            foreach ($dom->getElementsByTagName('row') as $row) {
                $vals = [];
                foreach ($row->getElementsByTagName('c') as $c) {
                    $t = $c->getAttribute('t');
                    $vNode = $c->getElementsByTagName('v')->item(0);
                    $isNode = $c->getElementsByTagName('is')->item(0);
                    if ($t === 's' && $vNode) {
                        $vals[] = $shared[(int) $vNode->textContent] ?? '';
                    } else if ($t === 'inlineStr' && $isNode) {
                        $vals[] = trim($isNode->textContent);
                    } else if ($vNode) {
                        $vals[] = trim($vNode->textContent);
                    }
                }
                if (implode('', $vals) !== '') {
                    $partes[] = implode(' | ', $vals);
                }
            }
        }
        $zip->close();
        return $partes ? mb_substr(implode("\n", $partes), 0, $maxChars, 'UTF-8') : null;
    }
}
