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

    /** @var string Paleta editorial didactica moderna (armonizada con el logo Interfases). */
    const INDIGO = '1B2B5E';
    const CYAN = '0E8FC4';
    const CYAN_BRILLO = '1EA7E1';
    const CYAN_OSCURO = '0C6B8A';
    const CYAN_CLARO = 'EDF7FC';
    const BORDE_SUAVE = 'D5E4F0';
    const TEXTO_SUAVE = '475569';
    const FUENTE_BG = 'F4F7FA';

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
     * Pictograma vectorial SVG limpio segun una palabra clave (para afiches, tarjetas, callouts y tableros sin depender
     * de imagenes IA). Usa solo primitivas SVG 1.1 compatibles con navegadores y con TCPDF ImageSVG().
     *
     * @param string $clave Descripcion del pictograma (ej. «lavado de manos», «ergonomía», «convivencia», «seguridad»).
     * @param int $size Tamano en px.
     * @param bool $inlineStyle Si agrega atributo style en la etiqueta raiz (false para incrustar en TCPDF).
     * @return string Codigo SVG.
     */
    public static function svg_pictogram(string $clave, int $size = 28, bool $inlineStyle = true): string {
        $k = mb_strtolower($clave, 'UTF-8');
        $c = '#' . self::CYAN;
        $nav = '#' . self::INDIGO;
        $inner = '<circle cx="12" cy="12" r="9" fill="#E9F6FC" stroke="' . $c . '" stroke-width="2"/>'
            . '<polyline points="8,12.5 10.8,15.3 16.5,9.2" fill="none" stroke="' . $nav . '" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"/>';
        if (preg_match('/\bmanos?\b|jab[oó]n|\blav[ao]|higiene|\bagua\b|\baseo\b|desinfec/u', $k)) {
            $inner = '<path d="M12 3 C8.5 8 6 11 6 14.2 C6 17.5 8.7 20 12 20 C15.3 20 18 17.5 18 14.2 C18 11 15.5 8 12 3 Z" fill="#DFF2FC" stroke="' . $c . '" stroke-width="2"/>'
                . '<circle cx="10" cy="14.8" r="1.4" fill="#FFFFFF"/>'
                . '<circle cx="17.5" cy="6.5" r="1.5" fill="' . $nav . '"/>';
        } else if (preg_match('/ergonom|espalda|biomec|transfer|carga|rodilla|moviliz|columna|postura/u', $k)) {
            $inner = '<circle cx="12" cy="5.2" r="2.4" fill="#DFF2FC" stroke="' . $nav . '" stroke-width="1.8"/>'
                . '<line x1="12" y1="8" x2="12" y2="14.5" stroke="' . $c . '" stroke-width="2.4" stroke-linecap="round"/>'
                . '<line x1="7.5" y1="10.5" x2="16.5" y2="10.5" stroke="' . $nav . '" stroke-width="2" stroke-linecap="round"/>'
                . '<polyline points="8.2,20.5 10.2,16.5 12,14.5 13.8,16.5 15.8,20.5" fill="none" stroke="' . $nav . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>';
        } else if (preg_match('/alerta|riesgo|peligro|revisar|precauci|atenci[oó]n|advertenc|importante|recordatorio/u', $k)) {
            $c = '#' . self::AMBAR;
            $inner = '<polygon points="12,3 21.5,20 2.5,20" fill="#FEF3C7" stroke="' . $c . '" stroke-width="2" stroke-linejoin="round"/>'
                . '<line x1="12" y1="9.2" x2="12" y2="14.2" stroke="' . $c . '" stroke-width="2.2" stroke-linecap="round"/>'
                . '<circle cx="12" cy="17.2" r="1.2" fill="' . $c . '"/>';
        } else if (preg_match('/conviv|equipo|colabor|respet|empat|trato|digno|saludo|acogida|consent|di[aá]logo|comunic|escucha|persona|grupo|derecho|apoyo/u', $k)) {
            $inner = '<circle cx="8.5" cy="8.2" r="2.6" fill="#DFF2FC" stroke="' . $nav . '" stroke-width="1.9"/>'
                . '<path d="M4 19 C4 15.5 6 13.8 8.5 13.8 C11 13.8 13 15.5 13 19" fill="none" stroke="' . $nav . '" stroke-width="2" stroke-linecap="round"/>'
                . '<circle cx="16.2" cy="9.2" r="2.2" fill="#E9F6FC" stroke="' . $c . '" stroke-width="1.9"/>'
                . '<path d="M13.2 19 C13.5 16.2 14.8 14.8 16.5 14.8 C18.5 14.8 20 16.2 20 19" fill="none" stroke="' . $c . '" stroke-width="2" stroke-linecap="round"/>';
        } else if (preg_match('/mascar|guante|pechera|vestim|calzado|cama|epp|protecci|barrera|escudo|segur/u', $k)) {
            $inner = '<path d="M12 3 L19 6 L19 11.5 C19 16 15.8 19.3 12 21 C8.2 19.3 5 16 5 11.5 L5 6 Z" fill="#E9F6FC" stroke="' . $nav . '" stroke-width="2" stroke-linejoin="round"/>'
                . '<polyline points="8.8,12.2 11.1,14.5 15.6,9.8" fill="none" stroke="#' . self::VERDE . '" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>';
        } else if (preg_match('/nota|profundiz|consejo|idea|clave|recuerda|objetivo|prop[oó]sito|tip|saber|reflex/u', $k)) {
            $inner = '<circle cx="12" cy="9.8" r="5.2" fill="#DFF2FC" stroke="' . $c . '" stroke-width="2"/>'
                . '<rect x="9.4" y="15.2" width="5.2" height="2.4" fill="#FFFFFF" stroke="' . $nav . '" stroke-width="1.7"/>'
                . '<line x1="10.2" y1="20" x2="13.8" y2="20" stroke="' . $nav . '" stroke-width="2" stroke-linecap="round"/>'
                . '<line x1="12" y1="7.4" x2="12" y2="11.2" stroke="' . $nav . '" stroke-width="1.9" stroke-linecap="round"/>';
        } else if (preg_match('/ejemplo|caso|actividad|pr[aá]ctica|taller|ejercicio|aplicaci/u', $k)) {
            $v = '#' . self::VERDE;
            $inner = '<rect x="5" y="4" width="14" height="17" fill="#ECFDF3" stroke="' . $v . '" stroke-width="2"/>'
                . '<rect x="9" y="2.4" width="6" height="3" fill="' . $v . '"/>'
                . '<polyline points="8.5,12.8 11,15.3 15.8,10.2" fill="none" stroke="' . $v . '" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>';
        } else if (preg_match('/tiempo|reloj|minuto|segundo|duraci|pausa|cronogram/u', $k)) {
            $inner = '<circle cx="12" cy="12" r="8.8" fill="#E9F6FC" stroke="' . $nav . '" stroke-width="2"/>'
                . '<polyline points="12,7 12,12 15.8,14" fill="none" stroke="' . $c . '" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"/>';
        } else if (preg_match('/video|v[ií]deo|c[aá]psula|reproduc|audiovisual/u', $k)) {
            $inner = '<rect x="3" y="5" width="18" height="14" fill="#E9F6FC" stroke="' . $nav . '" stroke-width="2"/>'
                . '<polygon points="10,8.8 16.5,12 10,15.2" fill="' . $c . '"/>';
        } else if (preg_match('/fuente|referenc|bibliograf|lista|registro|gu[ií]a|manual|ficha|document|bit[aá]cora|norma|plan/u', $k)) {
            $inner = '<rect x="5" y="3" width="14" height="18" fill="#F1F5F9" stroke="' . $nav . '" stroke-width="2"/>'
                . '<line x1="8" y1="8" x2="16" y2="8" stroke="' . $c . '" stroke-width="1.9" stroke-linecap="round"/>'
                . '<line x1="8" y1="12" x2="16" y2="12" stroke="' . $nav . '" stroke-width="1.7" stroke-linecap="round"/>'
                . '<line x1="8" y1="16" x2="13.5" y2="16" stroke="' . $nav . '" stroke-width="1.7" stroke-linecap="round"/>';
        }
        $st = $inlineStyle ? ' style="vertical-align:middle;margin-right:6px;flex-shrink:0;"' : '';
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size
            . '" viewBox="0 0 24 24"' . $st . '>' . $inner . '</svg>';
    }

    /**
     * Devuelve el src en formato data stream de TCPDF («@base64») para incrustar un pictograma SVG vectorial en PDF.
     *
     * @param string $clave
     * @param int $size
     * @return string
     */
    public static function svg_tcpdf_src(string $clave, int $size = 24): string {
        return '@' . base64_encode(self::svg_pictogram($clave, $size, false));
    }

    /**
     * Clasifica una etiqueta de recuadro didactico (Nota de profundizacion, Importante, Ejemplo, Recuerda...).
     * Funcion pura.
     *
     * @param string $rotulo Texto de la etiqueta (sin dos puntos).
     * @return array|null ['tipo', 'icono', 'bg', 'bdr', 'bdr_soft', 'titulo_color'] o null si no es callout.
     */
    public static function classify_callout(string $rotulo): ?array {
        $k = mb_strtolower(trim($rotulo), 'UTF-8');
        if ($k === '' || mb_strlen($k, 'UTF-8') > 65) {
            return null;
        }
        if (preg_match('/^(nota\b|para saber|profundiz|consejo|sugerencia|sab[ií]as|tip\b|dato\s+de\s+apoyo)/u', $k)) {
            return [
                'tipo' => 'nota', 'icono' => 'nota', 'bg' => 'EAF7FB', 'bdr' => self::CYAN,
                'bdr_soft' => 'BFE3F2', 'titulo_color' => self::CYAN_OSCURO,
            ];
        }
        if (preg_match('/^(ejemplo|caso\b|actividad\b|pr[aá]ctica\b|ejercicio\b|aplicaci[oó]n\b|din[aá]mica\b)/u', $k)) {
            return [
                'tipo' => 'ejemplo', 'icono' => 'ejemplo', 'bg' => 'ECFDF3', 'bdr' => self::VERDE,
                'bdr_soft' => 'B7E4C7', 'titulo_color' => '166534',
            ];
        }
        if (preg_match('/^(importante|atenci[oó]n|precauci[oó]n|advertencia|alerta|cuidado\b|recordatorio|peligro|seguridad)/u', $k)) {
            return [
                'tipo' => 'alerta', 'icono' => 'alerta', 'bg' => 'FEF7E6', 'bdr' => 'D97706',
                'bdr_soft' => 'F6D78F', 'titulo_color' => '92400E',
            ];
        }
        if (preg_match('/^(recuerda|dato clave|idea clave|clave\b|resumen|objetivo|prop[oó]sito|reflexi[oó]n|en s[ií]ntesis|para recordar)/u', $k)) {
            return [
                'tipo' => 'clave', 'icono' => 'nota', 'bg' => 'EEF2FF', 'bdr' => '3B49DF',
                'bdr_soft' => 'C7D2FE', 'titulo_color' => self::INDIGO,
            ];
        }
        return null;
    }

    /**
     * Analiza y enriquece semanticamente el HTML de un material instruccional (nuevo o ya guardado en BD):
     *  - Separa codigo («M1») y titulo limpio, y evita duplicar el <h1> inicial.
     *  - Extrae la linea de metadatos («Organismo: ... | Curso: ... | Persona facilitadora: ...») a chips limpios.
     *  - Convierte el bloque introductorio «Encabezado e identificación» en un resumen de proposito amigable.
     *  - Repara listas en linea de fuentes («Fuentes utilizadas: * A * B») y extrae el pie institucional obligatorio.
     *  - Anota visualmente recuadros didacticos (blockquotes y parrafos «Nota de profundización:», «Ejemplo:», etc.)
     *    y sugerencias visuales («[Pictograma: ...]» e «Ilustración sugerida: ...»).
     * Funcion pura.
     *
     * @param string $titulo
     * @param string $subtitulo
     * @param string $html
     * @return array ['codigo', 'titulo', 'titulo_completo', 'subtitulo', 'meta_items', 'lead', 'fuentes', 'pie', 'body_html'].
     */
    public static function prepare_material_parts(string $titulo, string $subtitulo, string $html): array {
        $codigo = '';
        $tituloLimpio = trim($titulo);
        if (preg_match('/^([A-Za-z]{1,4}\d+(?:-[A-Za-z0-9]+)?)\s*[·:\-–—]\s*(.+)$/u', $tituloLimpio, $m)) {
            $codigo = strtoupper(trim($m[1]));
            $tituloLimpio = trim($m[2]);
        }

        // Normalizar contenido heredado y pictogramas antiguos antes de analizar el DOM.
        $limpio = diseno::normalize_product(self::legacy_pictograms($html));
        $limpio = (string) preg_replace('#<(script|style|iframe|object|embed|form)\b[^>]*>.*?</\1>#isu', '', $limpio);
        $limpio = (string) preg_replace('/\s+on[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $limpio);
        $limpio = (string) preg_replace('/href\s*=\s*(["\'])\s*javascript:[^"\']*\1/iu', 'href="#"', $limpio);

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $limpio . '</body></html>');
        libxml_clear_errors();
        $body = $dom->getElementsByTagName('body')->item(0);

        $metaItems = [];
        $lead = '';
        $fuentes = [];
        $pie = '';
        $nodes = [];
        if ($body) {
            foreach ($body->childNodes as $child) {
                if ($child instanceof \DOMElement) {
                    $nodes[] = $child;
                } else if ($child instanceof \DOMText && trim($child->nodeValue) !== '') {
                    $p = $dom->createElement('p', trim($child->nodeValue));
                    $nodes[] = $p;
                }
            }
        }

        $plainNorm = static function(string $t): string {
            $t = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $t)), 'UTF-8');
            return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t));
        };
        $innerHtml = static function(\DOMNode $node) use ($dom): string {
            $out = '';
            foreach ($node->childNodes as $c) {
                $out .= $dom->saveHTML($c);
            }
            return trim($out);
        };

        $skip = [];
        $n = count($nodes);

        // 1) Cabecera inicial: metadatos pipe-separated, H1 duplicado y bloque robotico «Encabezado e identificacion».
        $cursor = 0;
        while ($cursor < $n && $cursor < 5) {
            $el = $nodes[$cursor];
            $tag = strtolower($el->nodeName);
            $txt = trim(preg_replace('/\s+/u', ' ', $el->textContent));
            if ($tag === 'hr') {
                $skip[$cursor] = true;
                $cursor++;
                continue;
            }
            if ($tag === 'p' && !$metaItems
                    && preg_match('/\b(organismo|curso|persona\s+facilitadora|facilitador|c[oó]digo|fase\s+y\s+actividad)\s*:/iu', $txt)) {
                // Conservar saltos <br> y saltos de linea como separadores, y separar tambien claves pegadas en linea.
                $rawMeta = preg_replace('#<br\s*/?>#i', ' | ', $innerHtml($el));
                $rawMeta = html_entity_decode(strip_tags((string) $rawMeta), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $rawMeta = preg_replace('/\R+/u', ' | ', $rawMeta);
                $rawMeta = preg_replace('/\s+(?=(?:Organismo|Curso|Persona\s+facilitadora|Facilitador(?:\/a)?|C[oó]digo|Modo|Formato(?:\s+de\s+salida)?|Impresi[oó]n|Fase\s+y\s+actividad|Actividad|Duraci[oó]n)\s*:)/iu', ' | ', (string) $rawMeta);
                $vistos = [];
                foreach (explode('|', (string) $rawMeta) as $seg) {
                    $seg = trim($seg);
                    if (preg_match('/^([^:]{2,40}):\s*(.+)$/u', $seg, $mm)) {
                        $k = trim($mm[1]);
                        $v = trim($mm[2]);
                        $kl = mb_strtolower($k, 'UTF-8');
                        if (preg_match('/^c[oó]digo$/u', $kl)) {
                            if ($codigo === '') {
                                $codigo = $v;
                            }
                            continue;
                        }
                        // Datos tecnicos de produccion que no aportan al participante en la cabecera del material.
                        if (preg_match('/^(modo|formato(?:\s+de\s+salida)?|impresi[oó]n)$/u', $kl)) {
                            continue;
                        }
                        if ($v !== '' && empty($vistos[$kl])) {
                            $vistos[$kl] = true;
                            $metaItems[] = ['etiqueta' => $k, 'valor' => $v];
                        }
                    }
                }
                if ($metaItems) {
                    $skip[$cursor] = true;
                    $cursor++;
                    continue;
                }
            }
            if ($tag === 'h1' && $tituloLimpio !== '') {
                $hNorm = $plainNorm($txt);
                $tNorm = $plainNorm($tituloLimpio);
                $fNorm = $plainNorm($titulo);
                if ($hNorm === $tNorm || $hNorm === $fNorm) {
                    $skip[$cursor] = true;
                    $cursor++;
                    continue;
                }
            }
            if (($tag === 'h2' || $tag === 'h3')
                    && preg_match('/^(encabezado\s+e\s+identificaci[oó]n|identificaci[oó]n(\s+del\s+(material|recurso))?|encabezado)$/iu', $txt)) {
                $skip[$cursor] = true;
                if ($cursor + 1 < $n && strtolower($nodes[$cursor + 1]->nodeName) === 'p') {
                    $lead = $innerHtml($nodes[$cursor + 1]);
                    $skip[$cursor + 1] = true;
                    $cursor += 2;
                    continue;
                }
                $cursor++;
                continue;
            }
            break;
        }

        // 2) Cierre del material: pie institucional obligatorio y fuentes utilizadas (incluso con «* » en linea).
        for ($i = $n - 1; $i >= 0 && $i >= $n - 5; $i--) {
            if (!empty($skip[$i])) {
                continue;
            }
            $el = $nodes[$i];
            $tag = strtolower($el->nodeName);
            $txt = trim(preg_replace('/\s+/u', ' ', $el->textContent));
            if ($tag === 'hr') {
                $skip[$i] = true;
                continue;
            }
            if ($pie === '' && $tag === 'p' && stripos($txt, 'Material producido con apoyo de IA') !== false) {
                $pie = $txt;
                $skip[$i] = true;
                continue;
            }
            if (!$fuentes && ($tag === 'p' || $tag === 'ul' || $tag === 'ol')) {
                if ($tag === 'p' && preg_match('/^Fuentes\s+utilizadas\s*:\s*(.*)$/iu', $txt, $mf)) {
                    $resto = trim($mf[1]);
                    if ($resto !== '') {
                        $items = preg_split('/\s+(?:\*|•)\s+|^\s*(?:\*|•)\s+/u', $resto);
                        foreach ($items as $it) {
                            $it = trim($it, " \t\n\r.;");
                            if ($it !== '') {
                                $fuentes[] = $it;
                            }
                        }
                    }
                    $skip[$i] = true;
                    continue;
                }
                if (($tag === 'ul' || $tag === 'ol') && $i > 0 && strtolower($nodes[$i - 1]->nodeName) === 'p'
                        && preg_match('/^Fuentes\s+utilizadas\s*:?\s*$/iu', trim($nodes[$i - 1]->textContent))) {
                    foreach ($el->getElementsByTagName('li') as $li) {
                        $t = trim(preg_replace('/\s+/u', ' ', $li->textContent));
                        if ($t !== '') {
                            $fuentes[] = $t;
                        }
                    }
                    $skip[$i] = true;
                    $skip[$i - 1] = true;
                    $i--;
                    continue;
                }
            }
            break;
        }

        // 3) Reconstruir el cuerpo con bloques semanticos anotados (callouts e ilustraciones sugeridas).
        $bloques = [];
        for ($i = 0; $i < $n; $i++) {
            if (!empty($skip[$i])) {
                continue;
            }
            $el = $nodes[$i];
            $tag = strtolower($el->nodeName);
            // Quitar separadores <hr> redundantes justo antes de un <h2> (las bandas de seccion ya separan).
            if ($tag === 'hr') {
                $sig = $i + 1;
                while ($sig < $n && !empty($skip[$sig])) {
                    $sig++;
                }
                if ($sig >= $n || strtolower($nodes[$sig]->nodeName) === 'h2') {
                    continue;
                }
            }
            $bloques[] = self::annotate_didactic_node($dom, $el, $innerHtml);
        }

        $tituloCompleto = $codigo !== '' ? ($codigo . ' · ' . $tituloLimpio) : $tituloLimpio;
        return [
            'codigo' => $codigo,
            'titulo' => $tituloLimpio,
            'titulo_completo' => $tituloCompleto,
            'subtitulo' => trim($subtitulo),
            'meta_items' => $metaItems,
            'lead' => $lead,
            'fuentes' => $fuentes,
            'pie' => $pie,
            'body_html' => implode("\n", array_filter($bloques, static fn($b) => trim($b) !== '')),
        ];
    }

    /**
     * Anota un nodo de bloque como tarjeta visual (.cr-ilustracion) o recuadro didactico (.cr-callout) si corresponde.
     *
     * @param \DOMDocument $dom
     * @param \DOMElement $el
     * @param callable $innerHtml
     * @return string
     */
    private static function annotate_didactic_node(\DOMDocument $dom, \DOMElement $el, callable $innerHtml): string {
        $tag = strtolower($el->nodeName);
        $txt = trim(preg_replace('/\s+/u', ' ', $el->textContent));
        $e = static fn(string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');

        // 1) Parrafo dedicado a un pictograma (o que empieza con un pictograma seguido de texto).
        if ($tag === 'p') {
            if (preg_match('/^\[(?:pictograma|[ií]cono)(?:\s*:\s*|\s+)([^\]]+)\]$/iu', $txt, $mp)) {
                $desc = trim($mp[1]);
                return '<div class="cr-ilustracion" data-icon="' . $e($desc) . '">' . $e($desc) . '</div>';
            }
            if (preg_match('/^\[(?:pictograma|[ií]cono)(?:\s*:\s*|\s+)([^\]]+)\]\s+(.+)$/isu', $innerHtml($el), $mp2)) {
                $desc = trim(html_entity_decode(strip_tags($mp2[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                return '<div class="cr-ilustracion" data-icon="' . $e($desc) . '">' . $e($desc) . '</div>'
                    . "\n<p>" . trim($mp2[2]) . '</p>';
            }
            if (preg_match('/^Ilustraci[oó]n\s+sugerida\s*:\s*(.+)$/iu', $txt, $mi)) {
                $desc = trim($mi[1]);
                return '<div class="cr-ilustracion" data-icon="' . $e($desc) . '">' . $e($desc) . '</div>';
            }
        }

        // 2) Blockquote -> siempre recuadro didactico (detectando su tipo por la etiqueta inicial si trae una).
        if ($tag === 'blockquote') {
            $interno = $innerHtml($el);
            $etiqueta = 'Nota didáctica';
            $cuerpo = $interno;
            if (preg_match('/^<p>\s*<(?:strong|b)[^>]*>([^<:]{2,65}?):?\s*<\/(?:strong|b)>\s*:?\s*(.*)$/isu', $interno, $mb)) {
                $etiqueta = rtrim(trim(strip_tags($mb[1])), ':');
                $cuerpo = '<p>' . trim($mb[2]);
            } else if (preg_match('/^([^:]{2,50}):\s*(.+)$/u', $txt, $mb)) {
                $cand = self::classify_callout($mb[1]);
                if ($cand !== null) {
                    $etiqueta = trim($mb[1]);
                    $cuerpo = '<p>' . $e(trim($mb[2])) . '</p>';
                }
            }
            $clas = self::classify_callout($etiqueta) ?? [
                'tipo' => 'nota', 'icono' => 'nota', 'bg' => 'EAF7FB', 'bdr' => self::CYAN,
                'bdr_soft' => 'BFE3F2', 'titulo_color' => self::CYAN_OSCURO,
            ];
            return '<div class="cr-callout cr-callout-' . $clas['tipo'] . '" data-label="' . $e($etiqueta)
                . '" data-icon="' . $e($clas['icono']) . '">' . $cuerpo . '</div>';
        }

        // 3) Parrafo encabezado por una clave didactica en negrita («Nota de profundización:», «Ejemplo:», «Importante:»...).
        if ($tag === 'p') {
            $interno = $innerHtml($el);
            if (preg_match('/^<(?:strong|b)[^>]*>([^<:]{2,65}?):?\s*<\/(?:strong|b)>\s*:?\s*(.+)$/isu', $interno, $mc)) {
                $rotulo = rtrim(trim(strip_tags($mc[1])), ':');
                $clas = self::classify_callout($rotulo);
                if ($clas !== null) {
                    return '<div class="cr-callout cr-callout-' . $clas['tipo'] . '" data-label="' . $e($rotulo)
                        . '" data-icon="' . $e($clas['icono']) . '"><p>' . trim($mc[2]) . '</p></div>';
                }
            }
            // 4) Parrafo con viñetas escritas en linea («Su función operativa es: * A... * B... * C...»): pasar a <ul>.
            $expandido = self::expand_inline_bullets($interno);
            if ($expandido !== null) {
                return $expandido;
            }
        }

        return trim((string) $dom->saveHTML($el));
    }

    /**
     * Convierte un parrafo que contiene una lista Markdown sin salto de linea previo («...: * Item 1. * Item 2.») en
     * un parrafo introductorio (si existe) seguido de una lista <ul> limpia. Devuelve null si no aplica. Funcion pura.
     *
     * @param string $interno HTML interior de un <p>.
     * @return string|null
     */
    public static function expand_inline_bullets(string $interno): ?string {
        if (strpos($interno, '* ') === false) {
            return null;
        }
        // Requiere al menos dos marcadores « * » seguidos de letra o etiqueta de inicio (evita multiplicaciones «2 * 3»).
        if (preg_match_all('/(?:^|[\s:;.\)])\*\s+(?=[\p{Lu}<])/u', $interno) < 2) {
            return null;
        }
        $partes = preg_split('/\s+\*\s+(?=[\p{Lu}<])/u', trim($interno));
        if (!$partes || count($partes) < 2) {
            return null;
        }
        $intro = trim($partes[0]);
        $items = array_slice($partes, 1);
        if (preg_match('/^\*\s+(.+)$/su', $intro, $m0)) {
            array_unshift($items, trim($m0[1]));
            $intro = '';
        }
        $items = array_values(array_filter(array_map('trim', $items), 'strlen'));
        if (count($items) < 2) {
            return null;
        }
        $lis = implode('', array_map(static fn($it) => '<li>' . $it . '</li>', $items));
        return ($intro !== '' ? '<p>' . $intro . '</p>' . "\n" : '') . '<ul>' . $lis . '</ul>';
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
                return '<span class="cr-picto" style="display:inline-flex;align-items:center;gap:6px;background:#EDF7FC;'
                    . 'border:1px solid #BFE0F2;border-left:3px solid #1EA7E1;border-radius:8px;padding:4px 10px;'
                    . 'margin:3px 0;color:#1B2B5E;font-size:13px;">'
                    . self::svg_pictogram($etiqueta, $size) . '<span><strong style="color:#0E8FC4;font-size:11px;'
                    . 'letter-spacing:0.4px;text-transform:uppercase;margin-right:4px;">Apoyo visual</strong>'
                    . '<em>Ilustración sugerida: ' . htmlspecialchars($etiqueta, ENT_QUOTES, 'UTF-8') . '</em></span></span>';
            }, $html);
    }

    /**
     * Separa un titulo de seccion numerado («Sección 1 — Bioseguridad...» o «1. Bioseguridad...») en [insignia, titulo].
     * Funcion pura.
     *
     * @param string $texto
     * @param int $indice Indice correlativo (1, 2, 3...) si el titulo no trae numero.
     * @return array [badge, tituloLimpio]
     */
    public static function split_section_badge(string $texto, int $indice = 0): array {
        $t = trim(preg_replace('/\s+/u', ' ', $texto));
        if (preg_match('/^(?:secci[oó]n|m[oó]dulo|parte|bloque|unidad|estaci[oó]n|paso)\s+(\d+|[A-Z])\s*[:.\-–—]?\s*(.+)$/iu', $t, $m)) {
            $num = ctype_digit($m[1]) ? str_pad($m[1], 2, '0', STR_PAD_LEFT) : strtoupper($m[1]);
            return [$num, trim($m[2])];
        }
        if (preg_match('/^(\d+(?:\.\d+)?)\.?\s+(.+)$/u', $t, $m)) {
            $num = ctype_digit($m[1]) ? str_pad($m[1], 2, '0', STR_PAD_LEFT) : $m[1];
            return [$num, trim($m[2])];
        }
        return [$indice > 0 ? str_pad((string) $indice, 2, '0', STR_PAD_LEFT) : '', $t];
    }

    /**
     * Limpia y empaqueta HTML moderno, didactico y compatible con el editor de Moodle: estilos en linea seguros,
     * sin scripts, sin iframes ni recursos externos, con cabecera moderna, chips de contexto, secciones numeradas,
     * recuadros didacticos con pictogramas SVG y marcas [REVISAR: ...] destacadas.
     *
     * @param string $html Contenido HTML del material.
     * @param string $titulo Titulo del material.
     * @param string $codigo Codigo (ej. M1).
     * @return string HTML listo para pegar en vista de codigo fuente de Moodle o mostrar en pantalla.
     */
    public static function to_moodle_html(string $html, string $titulo = '', string $codigo = ''): string {
        $e = static fn(string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        $tituloComp = ($codigo !== '' && $titulo !== '' && stripos($titulo, $codigo) !== 0)
            ? ($codigo . ' · ' . $titulo) : $titulo;
        $parts = self::prepare_material_parts($tituloComp, '', $html);
        if ($codigo !== '' && $parts['codigo'] === '') {
            $parts['codigo'] = strtoupper(trim($codigo));
        }

        $body = self::render_pictograms($parts['body_html'], 22);
        // Destacar marcas [REVISAR] con estilo en linea seguro (conserva #fff3cd para compatibilidad).
        $body = (string) preg_replace_callback('/\[REVISAR(?:\s*:\s*[^\]]+)?\]/u', static function(array $m): string {
            return '<span style="background:#fff3cd;color:#854d0e;border:1px solid #f6d57a;padding:2px 7px;'
                . 'border-radius:5px;font-weight:700;font-size:0.92em;">'
                . htmlspecialchars($m[0], ENT_QUOTES, 'UTF-8') . '</span>';
        }, $body);

        // Estilizar bloques semanticos en HTML inline-safe (100% compatible con Moodle).
        $body = (string) preg_replace_callback('#<div class="cr-ilustracion" data-icon="([^"]*)">(.*?)</div>#isu',
            static function(array $m): string {
                $desc = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $ico = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                return '<div style="display:flex;align-items:center;gap:12px;background:#EDF7FC;border:1px solid #C2E0F2;'
                    . 'border-left:4px solid #1EA7E1;border-radius:10px;padding:10px 14px;margin:10px 0 14px 0;">'
                    . '<div style="background:#FFFFFF;border:1px solid #D3E8F5;border-radius:8px;padding:6px;line-height:0;">'
                    . self::svg_pictogram($ico, 24, false) . '</div>'
                    . '<div style="font-size:13.5px;color:#334155;">'
                    . '<div style="font-size:10.5px;font-weight:700;letter-spacing:0.6px;color:#0E8FC4;text-transform:uppercase;margin-bottom:2px;">Apoyo visual sugerido</div>'
                    . '<em>' . htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') . '</em></div></div>';
            }, $body);

        $body = (string) preg_replace_callback('#<div class="cr-callout cr-callout-([a-z]+)" data-label="([^"]*)" data-icon="([^"]*)">(.*?)</div>#isu',
            static function(array $m): string {
                $lbl = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $ico = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $clas = self::classify_callout($lbl) ?? [
                    'bg' => 'EAF7FB', 'bdr' => self::CYAN, 'bdr_soft' => 'BFE3F2', 'titulo_color' => self::CYAN_OSCURO,
                ];
                return '<div style="display:flex;align-items:flex-start;gap:12px;background:#' . $clas['bg']
                    . ';border:1px solid #' . $clas['bdr_soft'] . ';border-left:4px solid #' . $clas['bdr']
                    . ';border-radius:10px;padding:12px 15px;margin:14px 0;">'
                    . '<div style="padding-top:2px;line-height:0;">' . self::svg_pictogram($ico, 22, false) . '</div>'
                    . '<div style="flex:1;font-size:14px;color:#1E293B;">'
                    . '<div style="font-weight:700;color:#' . $clas['titulo_color'] . ';font-size:13px;margin-bottom:4px;">'
                    . htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8') . '</div>'
                    . trim($m[4]) . '</div></div>';
            }, $body);

        // Titulos de seccion (h2) con insignia numerada y subtitulos (h3) modernos.
        $secNum = 0;
        $body = (string) preg_replace_callback('#<h2\b[^>]*>(.*?)</h2>#isu', static function(array $m) use (&$secNum): string {
            $secNum++;
            $txt = trim(strip_tags($m[1]));
            [$badge, $limpio] = self::split_section_badge($txt, $secNum);
            return '<div style="display:flex;align-items:center;gap:10px;background:#EEF7FC;border-bottom:2px solid #1EA7E1;'
                . 'border-radius:8px;padding:8px 12px;margin:22px 0 12px 0;">'
                . ($badge !== '' ? '<span style="background:#1B2B5E;color:#FFFFFF;font-weight:700;font-size:12px;'
                    . 'padding:3px 9px;border-radius:6px;letter-spacing:0.5px;">'
                    . htmlspecialchars($badge, ENT_QUOTES, 'UTF-8') . '</span>' : '')
                . '<h3 style="margin:0;color:#1B2B5E;font-size:17px;font-weight:700;">'
                . htmlspecialchars($limpio, ENT_QUOTES, 'UTF-8') . '</h3></div>';
        }, $body);
        $body = (string) preg_replace('#<h3\b[^>]*>(.*?)</h3>#isu',
            '<h4 style="color:#0C6B8A;font-size:15px;font-weight:700;margin:16px 0 8px 0;padding-left:8px;border-left:3px solid #1EA7E1;">$1</h4>', $body);
        $body = (string) preg_replace('#<li\b[^>]*>\s*<strong>([^<]+)</strong>#isu',
            '<li style="margin-bottom:6px;"><strong style="color:#1B2B5E;">$1</strong>', $body);

        // Estilizar tablas normales con cabecera indigo y filas alternadas; conservar diagramas y tarjetas.
        $body = (string) preg_replace_callback('#<table\b[^>]*>.*?</table>#isu', static function(array $m): string {
            $tbl = $m[0];
            if (preg_match('/m2-(diagrama|tarjetas)/i', $tbl, $clase)) {
                return (string) preg_replace('/<table\b[^>]*>/i',
                    '<table class="m2-' . $clase[1] . '" style="width:100%;border-collapse:separate;border-spacing:8px;margin:14px 0;font-size:14px;">', $tbl, 1);
            }
            $tbl = (string) preg_replace('/<table\b[^>]*>/i',
                '<table style="width:100%;border-collapse:collapse;margin:14px 0;font-size:14px;border:1px solid #D5E4F0;">', $tbl, 1);
            $tbl = (string) preg_replace('/<th\b([^>]*)>/i',
                '<th$1 style="background:#1B2B5E;color:#FFFFFF;padding:9px 11px;border:1px solid #2A3F7A;text-align:left;font-weight:700;">', $tbl);
            $rowIdx = 0;
            $tbl = (string) preg_replace_callback('/<tr\b([^>]*)>(.*?)<\/tr>/isu', static function(array $rm) use (&$rowIdx): string {
                if (stripos($rm[2], '<th') !== false) {
                    return '<tr' . $rm[1] . '>' . $rm[2] . '</tr>';
                }
                $bg = ($rowIdx++ % 2 === 1) ? '#F4F9FC' : '#FFFFFF';
                $cells = (string) preg_replace('/<td\b([^>]*)>/i',
                    '<td$1 style="background:' . $bg . ';padding:8px 11px;border:1px solid #D5E4F0;vertical-align:top;">', $rm[2]);
                return '<tr' . $rm[1] . '>' . $cells . '</tr>';
            }, $tbl);
            return $tbl;
        }, $body);

        // Cabecera moderna con gradiente Interfases + chips de metadatos.
        $cab = '';
        if ($parts['titulo'] !== '' || $titulo !== '') {
            $titShow = $parts['titulo_completo'] !== '' ? $parts['titulo_completo'] : $titulo;
            $badge = $parts['codigo'] !== ''
                ? '<div style="display:inline-block;background:#1EA7E1;color:#FFFFFF;font-size:11px;font-weight:700;'
                    . 'letter-spacing:0.8px;padding:3px 10px;border-radius:999px;margin-bottom:8px;text-transform:uppercase;">'
                    . 'Recurso ' . $e($parts['codigo']) . '</div>'
                : '';
            $cab = '<div style="background:#1B2B5E;background-image:linear-gradient(135deg,#1B2B5E 0%,#0E6E9E 100%);'
                . 'color:#FFFFFF;padding:18px 22px;border-bottom:4px solid #1EA7E1;">'
                . $badge
                . '<h2 style="margin:0;color:#FFFFFF;font-size:21px;line-height:1.3;font-weight:700;">'
                . $e($titShow) . '</h2></div>';
        }

        $metaBar = '';
        if ($parts['meta_items']) {
            $chips = [];
            foreach ($parts['meta_items'] as $mi) {
                $chips[] = '<span style="display:inline-block;background:#FFFFFF;border:1px solid #CBE3F2;border-radius:6px;'
                    . 'padding:4px 10px;font-size:12.5px;color:#1B2B5E;margin:2px 4px 2px 0;">'
                    . '<strong style="color:#0E8FC4;font-size:11px;text-transform:uppercase;margin-right:4px;">'
                    . $e($mi['etiqueta']) . ':</strong> ' . $e($mi['valor']) . '</span>';
            }
            $metaBar = '<div style="background:#EDF7FC;padding:10px 22px;border-bottom:1px solid #D5E4F0;">'
                . implode(' ', $chips) . '</div>';
        }

        $leadBox = '';
        if ($parts['lead'] !== '') {
            $leadBox = '<div style="background:#F7FBFE;border:1px solid #D3E7F5;border-left:4px solid #1B2B5E;'
                . 'border-radius:10px;padding:12px 16px;margin:0 0 16px 0;font-size:14.5px;color:#243447;">'
                . '<div style="font-size:11px;font-weight:700;letter-spacing:0.6px;color:#0E8FC4;text-transform:uppercase;margin-bottom:4px;">'
                . 'Propósito del recurso</div>' . $parts['lead'] . '</div>';
        }

        $fuentesBox = '';
        if ($parts['fuentes']) {
            $lis = implode('', array_map(static fn($f) => '<li style="margin-bottom:3px;">' . $e($f) . '</li>', $parts['fuentes']));
            $fuentesBox = '<div style="background:#F4F7FA;border:1px solid #D5DFE9;border-left:4px solid #475569;'
                . 'border-radius:10px;padding:12px 15px;margin:18px 0 8px 0;font-size:13px;color:#475569;">'
                . '<div style="font-weight:700;color:#1B2B5E;font-size:12px;letter-spacing:0.5px;text-transform:uppercase;margin-bottom:6px;">'
                . 'Fuentes utilizadas</div><ul style="margin:0;padding-left:18px;">' . $lis . '</ul></div>';
        }

        $pieBar = '';
        if ($parts['pie'] !== '') {
            $pieBar = '<div style="background:#F8FAFC;border-top:1px solid #E2E8F0;padding:10px 22px;'
                . 'font-size:12px;color:#64748B;font-style:italic;">' . $e($parts['pie']) . '</div>';
        }

        return '<div style="font-family:\'Plus Jakarta Sans\',\'Segoe UI\',Arial,Helvetica,sans-serif;color:#1E293B;line-height:1.6;'
            . 'background:#FFFFFF;border:1px solid #D5E4F0;border-radius:12px;overflow:hidden;">'
            . $cab . $metaBar . '<div style="padding:18px 22px;">' . $leadBox . trim($body) . $fuentesBox . '</div>'
            . $pieBar . '</div>';
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
     * Genera un archivo Word (.docx) editable con diseño didactico moderno Interfases:
     * cabecera moderna con insignia de codigo, banda de metadatos, secciones con acento lateral,
     * recuadros didacticos, sugerencias visuales, fuentes ordenadas y marcas [REVISAR] resaltadas.
     *
     * @param string $ruta Ruta de salida .docx.
     * @param string $titulo
     * @param string $subtitulo
     * @param string $html
     * @param string $logo Ruta a pix/logo_interfases.png (opcional).
     * @return bool
     */
    public static function build_docx(string $ruta, string $titulo, string $subtitulo, string $html, string $logo = ''): bool {
        $parts = self::prepare_material_parts($titulo, $subtitulo, $html);
        $e = static fn(string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        $cuerpo = self::pictogram_captions($parts['body_html']);
        $extra = '';
        if ($parts['lead'] !== '') {
            $extra .= '<div class="cr-lead">' . $parts['lead'] . '</div>';
        }
        $extra .= $cuerpo;
        if ($parts['fuentes']) {
            $lis = implode('', array_map(static fn($f) => '<li>' . $e($f) . '</li>', $parts['fuentes']));
            $extra .= '<div class="cr-sources"><ul>' . $lis . '</ul></div>';
        }
        if ($parts['pie'] !== '') {
            $extra .= '<div class="cr-pie">' . $e($parts['pie']) . '</div>';
        }
        if (class_exists('\local_chatbot\documento_final')) {
            $ok = documento_final::build($ruta, [
                'portada' => false,
                'estilo' => 'recurso',
                'codigo' => $parts['codigo'],
                'titulo' => $parts['titulo'] ?: $titulo,
                'subtitulo' => $parts['subtitulo'],
                'meta_items' => $parts['meta_items'],
                'encabezado' => trim($parts['titulo_completo'] . ($subtitulo !== '' ? ' · ' . $subtitulo : '')),
            ], $extra, $logo);
            if ($ok) {
                return true;
            }
        }
        return diseno::html_to_docx($ruta, $titulo, $subtitulo, diseno::normalize_product(self::pictogram_captions($html)));
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
        // En Moodle web PhpSpreadsheet ya viene autocargado; en CLI probamos autoloader si existe.
        if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            $autoload = __DIR__ . '/../../../lib/phpspreadsheet/vendor/autoload.php';
            if (is_file($autoload)) {
                require_once($autoload);
            }
        }
        if (class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            try {
                $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
                $book->getProperties()->setTitle($titulo)->setCreator('Centro de producción de recursos');
                $book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11)->getColor()->setARGB('FF' . self::TEXTO);
                foreach (array_values($hojas) as $idx => $h) {
                    $sheet = $idx === 0 ? $book->getActiveSheet() : $book->createSheet($idx);
                    $sheetName = self::safe_sheet_name((string) ($h['nombre'] ?? ('Hoja ' . ($idx + 1))), $idx + 1);
                    $sheet->setTitle($sheetName);
                    $row = 1;
                    $enc = (array) ($h['encabezados'] ?? []);
                    $filas = (array) ($h['filas'] ?? []);
                    $maxCol = max(1, count($enc), ...array_map(static fn($f) => count((array) $f), $filas ?: [[]]));
                    $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($maxCol);
                    if ($enc) {
                        foreach (array_values($enc) as $c => $val) {
                            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c + 1);
                            $sheet->setCellValue($col . $row, (string) $val);
                        }
                        $hdrRange = 'A1:' . $lastCol . '1';
                        $sheet->getStyle($hdrRange)->getFont()->setBold(true)->setSize(11)->getColor()->setARGB('FFFFFFFF');
                        $sheet->getStyle($hdrRange)->getFill()
                            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                            ->getStartColor()->setARGB('FF' . self::INDIGO);
                        $sheet->getStyle($hdrRange)->getAlignment()
                            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)
                            ->setWrapText(true);
                        $sheet->getStyle($hdrRange)->getBorders()->getBottom()
                            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM)
                            ->getColor()->setARGB('FF' . self::CYAN_BRILLO);
                        $sheet->getRowDimension(1)->setRowHeight(26);
                        $sheet->freezePane('A2');
                        $row++;
                    }
                    $dataIdx = 0;
                    foreach ($filas as $fila) {
                        foreach (array_values((array) $fila) as $c => $val) {
                            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c + 1);
                            $sheet->setCellValue($col . $row, is_scalar($val) ? $val : '');
                        }
                        $rowRange = 'A' . $row . ':' . $lastCol . $row;
                        if ($dataIdx % 2 === 1) {
                            $sheet->getStyle($rowRange)->getFill()
                                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                                ->getStartColor()->setARGB('FF' . self::CYAN_CLARO);
                        }
                        $sheet->getStyle($rowRange)->getAlignment()
                            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP)
                            ->setWrapText(true);
                        $sheet->getStyle($rowRange)->getBorders()->getAllBorders()
                            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
                            ->getColor()->setARGB('FF' . self::BORDE_SUAVE);
                        $dataIdx++;
                        $row++;
                    }
                    for ($c = 1; $c <= $maxCol; $c++) {
                        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
                        $maxLen = isset($enc[$c - 1]) ? mb_strlen((string) $enc[$c - 1], 'UTF-8') : 12;
                        foreach ($filas as $f) {
                            $vals = array_values((array) $f);
                            if (isset($vals[$c - 1]) && is_scalar($vals[$c - 1])) {
                                $maxLen = max($maxLen, mb_strlen((string) $vals[$c - 1], 'UTF-8'));
                            }
                        }
                        $sheet->getColumnDimension($col)->setWidth(min(48, max(14, $maxLen + 4)));
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
     * Respaldo OOXML puro (.xlsx) con ZipArchive sin dependencias externas (cabecera indigo, filas alternadas,
     * bordes suaves, texto ajustado, anchos equilibrados y primera fila inmovilizada).
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
            $enc = (array) ($h['encabezados'] ?? []);
            if ($enc) {
                $todas[] = ['vals' => $enc, 'style' => 1, 'ht' => 26];
            }
            $dataIdx = 0;
            foreach ((array) ($h['filas'] ?? []) as $fila) {
                // Estilo 2 = fila blanca con borde suave; estilo 3 = fila alternada celeste suave.
                $todas[] = ['vals' => (array) $fila, 'style' => ($dataIdx % 2 === 1 ? 3 : 2), 'ht' => 0];
                $dataIdx++;
            }
            $colLens = [];
            foreach ($todas as $item) {
                $cells = '';
                foreach (array_values($item['vals']) as $c => $val) {
                    $ref = $colName($c + 1) . $rnum;
                    $sattr = ' s="' . $item['style'] . '"';
                    $str = is_scalar($val) ? (string) $val : '';
                    $colLens[$c] = max($colLens[$c] ?? 12, mb_strlen($str, 'UTF-8'));
                    if ($str !== '' && $str[0] === '=') {
                        $cells .= '<c r="' . $ref . '"' . $sattr . '><f>' . $esc(substr($str, 1)) . '</f></c>';
                    } else if (is_numeric($str) && !preg_match('/^0\d/', $str)) {
                        $cells .= '<c r="' . $ref . '"' . $sattr . '><v>' . $str . '</v></c>';
                    } else {
                        $cells .= '<c r="' . $ref . '" t="inlineStr"' . $sattr . '><is><t>' . $esc($str) . '</t></is></c>';
                    }
                }
                $htAttr = $item['ht'] > 0 ? ' ht="' . $item['ht'] . '" customHeight="1"' : '';
                $rows[] = '<row r="' . $rnum . '"' . $htAttr . '>' . $cells . '</row>';
                $rnum++;
            }
            $colsXml = '';
            if ($colLens) {
                $colsXml = '<cols>';
                foreach ($colLens as $c => $len) {
                    $w = min(48, max(14, $len + 4));
                    $colsXml .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . $w . '" customWidth="1"/>';
                }
                $colsXml .= '</cols>';
            }
            $views = $enc
                ? '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
                : '';
            $sheetsXml[$sid] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . $views . $colsXml . '<sheetData>' . implode('', $rows) . '</sheetData></worksheet>';
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
        $bdrSuave = '<left style="thin"><color rgb="FF' . self::BORDE_SUAVE . '"/></left>'
            . '<right style="thin"><color rgb="FF' . self::BORDE_SUAVE . '"/></right>'
            . '<top style="thin"><color rgb="FF' . self::BORDE_SUAVE . '"/></top>'
            . '<bottom style="thin"><color rgb="FF' . self::BORDE_SUAVE . '"/></bottom>';
        $bdrHdr = '<left style="thin"><color rgb="FF2A3F7A"/></left><right style="thin"><color rgb="FF2A3F7A"/></right>'
            . '<top style="thin"><color rgb="FF' . self::INDIGO . '"/></top>'
            . '<bottom style="medium"><color rgb="FF' . self::CYAN_BRILLO . '"/></bottom>';
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><color rgb="FF' . self::TEXTO . '"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
            . '<fills count="4"><fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF' . self::INDIGO . '"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF' . self::CYAN_CLARO . '"/></patternFill></fill></fills>'
            . '<borders count="3"><border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border>' . $bdrSuave . '<diagonal/></border>'
            . '<border>' . $bdrHdr . '<diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="4">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            . '</cellXfs>'
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
            . '<a:dk1><a:srgbClr val="1E293B"/></a:dk1><a:lt1><a:srgbClr val="FFFFFF"/></a:lt1>'
            . '<a:dk2><a:srgbClr val="' . self::INDIGO . '"/></a:dk2><a:lt2><a:srgbClr val="' . self::CYAN_CLARO . '"/></a:lt2>'
            . '<a:accent1><a:srgbClr val="' . self::CYAN_BRILLO . '"/></a:accent1><a:accent2><a:srgbClr val="' . self::VERDE . '"/></a:accent2>'
            . '<a:accent3><a:srgbClr val="D97706"/></a:accent3><a:accent4><a:srgbClr val="' . self::TEXTO_SUAVE . '"/></a:accent4>'
            . '<a:accent5><a:srgbClr val="' . self::CYAN_OSCURO . '"/></a:accent5><a:accent6><a:srgbClr val="' . self::BORDE_SUAVE . '"/></a:accent6>'
            . '<a:hlink><a:srgbClr val="' . self::CYAN . '"/></a:hlink><a:folHlink><a:srgbClr val="' . self::INDIGO . '"/></a:folHlink>'
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
     * XML de una diapositiva PPTX moderna y didactica (portada geometrica Interfases o diapositiva luminosa con
     * tarjetas 2x2 cuando los puntos tienen formato «Titulo: detalle», o lista limpia con terminos destacados).
     */
    private static function pptx_slide_xml(array $s, int $num, int $total, string $pie, callable $esc): string {
        $box = static function(int $id, string $name, int $x, int $y, int $cx, int $cy, string $fillHex, string $parrafos,
                string $borderHex = '', string $geom = 'rect'): string {
            $fill = $fillHex !== '' ? '<a:solidFill><a:srgbClr val="' . $fillHex . '"/></a:solidFill>' : '<a:noFill/>';
            $ln = $borderHex !== ''
                ? '<a:ln w="12700"><a:solidFill><a:srgbClr val="' . $borderHex . '"/></a:solidFill></a:ln>'
                : '<a:ln><a:noFill/></a:ln>';
            return '<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="' . $name . '"/><p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>'
                . '<p:spPr><a:xfrm><a:off x="' . $x . '" y="' . $y . '"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
                . '<a:prstGeom prst="' . $geom . '"><a:avLst/></a:prstGeom>' . $fill . $ln . '</p:spPr>'
                . '<p:txBody><a:bodyPr wrap="square" lIns="150000" tIns="100000" rIns="150000" bIns="100000"/><a:lstStyle/>'
                . $parrafos . '</p:txBody></p:sp>';
        };
        $p = static function(string $txt, int $sz, string $color, bool $bold = false, bool $bullet = false,
                string $align = 'l') use ($esc): string {
            $bu = $bullet
                ? '<a:buClr><a:srgbClr val="' . self::CYAN_BRILLO . '"/></a:buClr><a:buChar char="●"/>'
                : '<a:buNone/>';
            $mar = $bullet ? ' marL="320000" indent="-240000"' : '';
            $al = $align !== 'l' ? ' algn="' . $align . '"' : '';
            // Si es viñeta con «Termino: explicacion», destacar el termino inicial en indigo negrita.
            if ($bullet && preg_match('/^([^:]{2,55}):\s+(.+)$/u', $txt, $mp)) {
                return '<a:p><a:pPr' . $mar . $al . '><a:spcAft><a:spcPts val="900"/></a:spcAft>' . $bu . '</a:pPr>'
                    . '<a:r><a:rPr lang="es-CL" sz="' . $sz . '" b="1"><a:solidFill><a:srgbClr val="' . self::INDIGO
                    . '"/></a:solidFill></a:rPr><a:t>' . $esc(trim($mp[1]) . ': ') . '</a:t></a:r>'
                    . '<a:r><a:rPr lang="es-CL" sz="' . $sz . '"><a:solidFill><a:srgbClr val="' . $color
                    . '"/></a:solidFill></a:rPr><a:t>' . $esc(trim($mp[2])) . '</a:t></a:r></a:p>';
            }
            return '<a:p><a:pPr' . $mar . $al . '><a:spcAft><a:spcPts val="800"/></a:spcAft>' . $bu . '</a:pPr>'
                . '<a:r><a:rPr lang="es-CL" sz="' . $sz . '"' . ($bold ? ' b="1"' : '') . '>'
                . '<a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill></a:rPr><a:t>' . $esc($txt) . '</a:t></a:r></a:p>';
        };

        $shapes = '';
        if (!empty($s['portada'])) {
            $shapes .= $box(2, 'Fondo', 0, 0, 12192000, 6858000, self::INDIGO, '<a:p/>');
            $shapes .= $box(3, 'AcentoIzq', 0, 0, 260000, 6858000, self::CYAN_BRILLO, '<a:p/>');
            $shapes .= $box(4, 'AcentoInf', 260000, 5650000, 11932000, 90000, self::CYAN, '<a:p/>');
            $shapes .= $box(5, 'Etiqueta', 860000, 1300000, 3200000, 440000, self::CYAN_BRILLO,
                $p('MATERIAL DIDÁCTICO', 1200, 'FFFFFF', true, false, 'ctr'), '', 'roundRect');
            $shapes .= $box(6, 'Titulo', 860000, 1920000, 10400000, 2500000, '',
                $p((string) ($s['titulo'] ?? ''), 3600, 'FFFFFF', true)
                . (!empty($s['subtitulo']) ? $p((string) $s['subtitulo'], 1800, 'CBE7F7', false) : ''));
            if ($pie !== '') {
                $shapes .= $box(7, 'Pie', 860000, 5950000, 10400000, 520000, '', $p($pie, 1050, 'B8D3E8'));
            }
        } else {
            // Diapositiva limpia sobre blanco, franja cyan superior, insignia numerica y titulo indigo.
            $shapes .= $box(2, 'Fondo', 0, 0, 12192000, 6858000, 'FFFFFF', '<a:p/>');
            $shapes .= $box(3, 'FranjaSup', 0, 0, 12192000, 110000, self::CYAN_BRILLO, '<a:p/>');
            $badgeTxt = str_pad((string) max(1, $num - 1), 2, '0', STR_PAD_LEFT);
            $shapes .= $box(4, 'Badge', 609600, 340000, 640000, 580000, self::INDIGO,
                $p($badgeTxt, 1800, 'FFFFFF', true, false, 'ctr'), '', 'roundRect');
            $shapes .= $box(5, 'Titulo', 1380000, 310000, 10200000, 700000, '',
                $p((string) ($s['titulo'] ?? ''), 2500, self::INDIGO, true));
            $shapes .= $box(6, 'Linea', 609600, 1120000, 10972800, 26000, self::BORDE_SUAVE, '<a:p/>');

            $puntos = array_values(array_filter(array_map('trim', array_slice((array) ($s['puntos'] ?? []), 0, 10)), 'strlen'));
            // Si hay 2 a 4 puntos concisos con formato «Titulo: detalle», distribuirlos en tarjetas visuales modernas.
            $tarjetas = [];
            if (count($puntos) >= 2 && count($puntos) <= 4) {
                $okTarj = true;
                foreach ($puntos as $pt) {
                    if (preg_match('/^([^:]{2,50}):\s+(.+)$/u', $pt, $mp) && mb_strlen($pt, 'UTF-8') <= 210) {
                        $tarjetas[] = [trim($mp[1]), trim($mp[2])];
                    } else {
                        $okTarj = false;
                        break;
                    }
                }
                if (!$okTarj) {
                    $tarjetas = [];
                }
            }

            $idShape = 7;
            if ($tarjetas) {
                $cols = count($tarjetas) === 3 ? 3 : 2;
                $rows = (int) ceil(count($tarjetas) / $cols);
                $gapX = 280000;
                $gapY = 260000;
                $areaW = 10972800;
                $areaH = 4680000;
                $cardW = (int) floor(($areaW - $gapX * ($cols - 1)) / $cols);
                $cardH = (int) floor(($areaH - $gapY * ($rows - 1)) / $rows);
                $acentos = [self::CYAN_BRILLO, self::INDIGO, self::VERDE, self::CYAN];
                foreach ($tarjetas as $ti => [$ctit, $cdesc]) {
                    $col = $ti % $cols;
                    $row = intdiv($ti, $cols);
                    $cx = 609600 + $col * ($cardW + $gapX);
                    $cy = 1360000 + $row * ($cardH + $gapY);
                    $ac = $acentos[$ti % count($acentos)];
                    $shapes .= $box($idShape++, 'Card' . $ti, $cx, $cy, $cardW, $cardH, 'F4FAFE',
                        $p($ctit, 1700, self::INDIGO, true) . $p($cdesc, 1450, self::TEXTO, false), self::BORDE_SUAVE, 'roundRect');
                    $shapes .= $box($idShape++, 'CardTop' . $ti, $cx, $cy, $cardW, 85000, $ac, '<a:p/>');
                }
            } else {
                $cuerpo = '';
                $sz = count($puntos) > 6 ? 1450 : 1650;
                foreach ($puntos as $pt) {
                    $cuerpo .= $p($pt, $sz, self::TEXTO, false, true);
                }
                if ($cuerpo === '') {
                    $cuerpo = '<a:p/>';
                }
                $shapes .= $box($idShape++, 'AcentoCuerpo', 609600, 1360000, 95000, 4680000, self::CYAN_BRILLO, '<a:p/>');
                $shapes .= $box($idShape++, 'Cuerpo', 704600, 1360000, 10877800, 4680000, 'F7FBFE', $cuerpo, self::BORDE_SUAVE);
            }

            $shapes .= $box($idShape++, 'LineaPie', 609600, 6220000, 10972800, 18000, self::BORDE_SUAVE, '<a:p/>');
            if ($pie !== '') {
                $shapes .= $box($idShape++, 'PieIzq', 609600, 6280000, 9200000, 400000, '', $p($pie, 950, self::TEXTO_SUAVE));
            }
            $shapes .= $box($idShape++, 'PieNum', 9900000, 6280000, 1682400, 400000, '',
                $p($num . ' / ' . $total, 1050, self::INDIGO, true, false, 'r'));
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
     * Genera un archivo PDF moderno, didactico y amigable en el tamano de hoja correspondiente al formato del material
     * (A4, Carta, A3, A2 o tarjeta 9x6 cm), sin marca de agua de TCPDF, con encabezado/pie institucional, iconos SVG
     * vectoriales, recuadros didacticos, tarjetas de pasos y proteccion contra titulos huerfanos.
     *
     * @param string $ruta Ruta de salida .pdf.
     * @param string $titulo
     * @param string $subtitulo
     * @param string $html
     * @param string $formato Texto del formato (ej. «PDF A2», «PDF tarjeta 9x6 cm»).
     * @param string $impresion Formato preferido ('carta' o 'a4').
     * @param string $logo Ruta opcional a pix/logo_interfases.png.
     * @return bool
     */
    public static function build_pdf(string $ruta, string $titulo, string $subtitulo, string $html,
            string $formato = 'PDF A4', string $impresion = 'carta', string $logo = ''): bool {
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

        $parts = self::prepare_material_parts($titulo, $subtitulo, $html);
        $cursoHdr = '';
        foreach ($parts['meta_items'] as $mi) {
            if (preg_match('/organismo|curso/iu', $mi['etiqueta'])) {
                $cursoHdr .= ($cursoHdr !== '' ? ' · ' : '') . $mi['valor'];
            }
        }
        $piePdf = $parts['pie'] !== ''
            ? $parts['pie']
            : (($parts['codigo'] !== '' ? $parts['codigo'] . ' · ' : '') . 'Material producido con apoyo de IA. Revisado por la persona facilitadora antes de su uso.');

        $metaPdf = [
            'titulo' => $parts['titulo_completo'] ?: $titulo,
            'curso' => $cursoHdr,
            'pie' => $piePdf,
            'tipo' => $spec['tipo'],
        ];

        $pdf = new class($spec['orientacion'], 'mm', $spec['tamano'], true, 'UTF-8', false, $metaPdf) extends \TCPDF {
            /** @var array */
            private $crMeta;

            public function __construct($orientation, $unit, $format, $unicode, $encoding, $diskcache, array $meta) {
                parent::__construct($orientation, $unit, $format, $unicode, $encoding, $diskcache);
                $this->tcpdflink = false;
                $this->crMeta = $meta;
            }

            public function Header(): void {
                // La portada/cabecera editorial vive en la pagina 1; las paginas siguientes llevan encabezado sutil.
                if ($this->getPage() < 2 || in_array($this->crMeta['tipo'], ['tarjeta', 'afiche'], true)) {
                    return;
                }
                $l = $this->original_lMargin;
                $r = $this->original_rMargin;
                $w = $this->w - $l - $r;
                $y = 7.5;
                $trunc = static function(string $s, int $max): string {
                    if (mb_strlen($s, 'UTF-8') <= $max) {
                        return $s;
                    }
                    $cut = mb_substr($s, 0, $max - 1, 'UTF-8');
                    $sp = mb_strrpos($cut, ' ', 0, 'UTF-8');
                    return rtrim($sp !== false && $sp > $max * 0.5 ? mb_substr($cut, 0, $sp, 'UTF-8') : $cut, " ,·:-") . '…';
                };
                $this->SetY($y);
                $this->SetFont('freesans', 'B', 8);
                $this->SetTextColor(27, 43, 94);
                $this->Cell($w * 0.62, 4.5, $trunc((string) $this->crMeta['titulo'], 68), 0, 0, 'L', false, '', 1);
                $this->SetFont('freesans', '', 7.5);
                $this->SetTextColor(100, 116, 139);
                $this->Cell($w * 0.38, 4.5, $trunc((string) $this->crMeta['curso'], 46), 0, 1, 'R', false, '', 1);
                $this->SetLineStyle(['width' => 0.35, 'color' => [213, 228, 240]]);
                $this->Line($l, $y + 5.2, $this->w - $r, $y + 5.2);
                $this->SetLineStyle(['width' => 0.75, 'color' => [30, 167, 225]]);
                $this->Line($l, $y + 5.2, $l + 24, $y + 5.2);
            }

            public function Footer(): void {
                if ($this->crMeta['tipo'] === 'tarjeta') {
                    return;
                }
                $l = $this->original_lMargin;
                $r = $this->original_rMargin;
                $w = $this->w - $l - $r;
                $y = $this->h - 11.5;
                $this->SetLineStyle(['width' => 0.35, 'color' => [213, 228, 240]]);
                $this->Line($l, $y, $this->w - $r, $y);
                $this->SetLineStyle(['width' => 0.8, 'color' => [30, 167, 225]]);
                $this->Line($l, $y, $l + 20, $y);
                $this->SetXY($l, $y + 1.4);
                $this->SetFont('freesans', '', 7.3);
                $this->SetTextColor(100, 116, 139);
                $this->Cell($w - 28, 4.5, (string) $this->crMeta['pie'], 0, 0, 'L', false, '', 1);
                $this->SetFont('freesans', 'B', 7.8);
                $this->SetTextColor(27, 43, 94);
                $pag = 'Página ' . $this->getAliasNumPage() . ' de ' . $this->getAliasNbPages();
                $this->Cell(28, 4.5, $pag, 0, 0, 'R');
            }
        };

        $pdf->SetCreator('Centro de producción de recursos');
        $pdf->SetAuthor('Interfases · Asistente de Diseño Instruccional');
        $pdf->SetTitle($parts['titulo_completo'] ?: $titulo);
        $pdf->setPrintHeader(!in_array($spec['tipo'], ['tarjeta', 'afiche'], true));
        $pdf->setPrintFooter($spec['tipo'] !== 'tarjeta');
        $pdf->setHeaderMargin(6);
        $pdf->setFooterMargin(12);

        if ($spec['tipo'] === 'tarjeta') {
            $pdf->SetMargins(4, 3.8, 4);
            $pdf->SetAutoPageBreak(true, 3.5);
            $fontSize = 8.4;
            $pdf->setCellHeightRatio(1.24);
        } else if ($spec['tipo'] === 'afiche') {
            $pdf->SetMargins(16, 15, 16);
            $pdf->SetAutoPageBreak(true, 17);
            $fontSize = 15.5;
            $pdf->setCellHeightRatio(1.38);
        } else if ($spec['tipo'] === 'tablero') {
            $pdf->SetMargins(13, 14, 13);
            $pdf->SetAutoPageBreak(true, 15);
            $fontSize = 11;
            $pdf->setCellHeightRatio(1.36);
        } else {
            $pdf->SetMargins(13, 14.5, 13);
            $pdf->SetAutoPageBreak(true, 15);
            $fontSize = 9.6;
            $pdf->setCellHeightRatio(1.38);
        }
        $pdf->setHtmlVSpace([
            'p' => [0 => ['h' => 1.2, 'n' => 1], 1 => ['h' => 1.8, 'n' => 1]],
            'ul' => [0 => ['h' => 1.0, 'n' => 1], 1 => ['h' => 2.0, 'n' => 1]],
            'ol' => [0 => ['h' => 1.0, 'n' => 1], 1 => ['h' => 2.0, 'n' => 1]],
            'li' => [0 => ['h' => 0.4, 'n' => 1], 1 => ['h' => 0.9, 'n' => 1]],
            'h3' => [0 => ['h' => 2.4, 'n' => 1], 1 => ['h' => 1.0, 'n' => 1]],
            'h4' => [0 => ['h' => 1.8, 'n' => 1], 1 => ['h' => 0.8, 'n' => 1]],
        ]);
        $pdf->SetFont('freesans', '', $fontSize);
        $pdf->SetTextColor(30, 41, 59);
        $pdf->AddPage();

        // En la pagina 1 de documentos normales empezamos un poco mas arriba porque no hay encabezado corrido.
        if ($spec['tipo'] === 'doc' || $spec['tipo'] === 'tablero') {
            $pdf->SetY(11);
        }

        // Paginacion inteligente bloque a bloque con transacciones TCPDF: si una unidad didactica (seccion + diagrama,
        // tarjeta, recuadro, tabla compacta o subtitulo + lista) se partiria entre paginas, retrocede y salta limpia
        // a la pagina siguiente sin cortar cajas, pasos ni titulos.
        $bloques = self::format_pdf_blocks($parts, $spec, $logo);
        $topMin = $pdf->getMargins()['top'] + 10.0;
        foreach ($bloques as $bloque) {
            $bHtml = (string) $bloque['html'];
            if (trim($bHtml) === '') {
                continue;
            }
            if (!empty($bloque['atomic']) && $spec['tipo'] !== 'tarjeta') {
                $p0 = $pdf->getPage();
                $y0 = $pdf->GetY();
                $pdf->startTransaction();
                $pdf->writeHTML($bHtml, true, false, true, false, '');
                if ($pdf->getPage() > $p0 && $y0 > $topMin) {
                    $pdf = $pdf->rollbackTransaction();
                    $pdf->AddPage();
                    $pdf->writeHTML($bHtml, true, false, true, false, '');
                } else {
                    $pdf->commitTransaction();
                }
            } else {
                $minMm = (float) ($bloque['min_space_mm'] ?? 0);
                if ($minMm > 0 && $spec['tipo'] !== 'tarjeta') {
                    $libre = $pdf->getPageHeight() - $pdf->getBreakMargin() - $pdf->GetY();
                    if ($libre < $minMm && $pdf->GetY() > $topMin) {
                        $pdf->AddPage();
                    }
                }
                $pdf->writeHTML($bHtml, true, false, true, false, '');
            }
        }
        $pdf->Output($ruta, 'F');
        return is_file($ruta) && filesize($ruta) > 0;
    }

    /**
     * Arma el HTML editorial didactico completo para TCPDF uniendo las unidades atomicas de format_pdf_blocks().
     * Funcion pura.
     *
     * @param array $parts Resultado de prepare_material_parts().
     * @param array $spec Resultado de parse_pdf_spec().
     * @param string $logo Ruta opcional al logo PNG.
     * @return string
     */
    public static function format_pdf_html(array $parts, array $spec, string $logo = ''): string {
        return implode("\n", array_column(self::format_pdf_blocks($parts, $spec, $logo), 'html'));
    }

    /**
     * Divide el documento PDF en unidades didacticas semanticas con marca «atomic» para que build_pdf() evalue con
     * transacciones TCPDF si cada bloque cabe completo en la pagina actual antes de dibujarlo (evitando cajas cortadas,
     * diagramas partidos por la mitad y titulos huerfanos). Funcion pura.
     *
     * @param array $parts Resultado de prepare_material_parts().
     * @param array $spec Resultado de parse_pdf_spec().
     * @param string $logo Ruta opcional al logo PNG.
     * @return array[] Lista de ['html' => string, 'atomic' => bool, 'min_space_mm' => float].
     */
    public static function format_pdf_blocks(array $parts, array $spec, string $logo = ''): array {
        $e = static fn(string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        $esTarjeta = $spec['tipo'] === 'tarjeta';
        $esAfiche = $spec['tipo'] === 'afiche';
        $spacer = '<div style="font-size:' . ($esTarjeta ? '2pt' : '4pt') . ';line-height:' . ($esTarjeta ? '2pt' : '4pt') . ';">&nbsp;</div>';
        $bloques = [];
        $push = static function(string $html, bool $atomic = true, float $minMm = 0.0) use (&$bloques): void {
            if (trim($html) !== '') {
                $bloques[] = ['html' => $html, 'atomic' => $atomic, 'min_space_mm' => $minMm];
            }
        };

        // 1) Cabecera Hero + Banda de metadatos (+ tarjeta de proposito si existe).
        $cab = '';
        $codigo = $parts['codigo'];
        $tit = $parts['titulo'] ?: $parts['titulo_completo'];
        $sub = $parts['subtitulo'];

        if ($esTarjeta) {
            $cab .= '<table width="100%" cellpadding="4" cellspacing="0" nobr="true">'
                . '<tr><td style="background-color:#' . self::INDIGO . ';color:#FFFFFF;border-bottom:2px solid #' . self::CYAN_BRILLO . ';">'
                . ($codigo !== '' ? '<span style="color:#7DD3FC;font-size:7pt;font-weight:bold;">' . $e($codigo) . ' · </span>' : '')
                . '<span style="font-size:10pt;font-weight:bold;color:#FFFFFF;">' . $e($tit) . '</span>'
                . '</td></tr></table>' . $spacer;
        } else {
            $kicker = $codigo !== '' ? ('RECURSO DIDÁCTICO · ' . $codigo) : 'RECURSO DIDÁCTICO';
            $titSz = $esAfiche ? '22pt' : ($spec['tipo'] === 'tablero' ? '16pt' : '14pt');
            $kickerSz = $esAfiche ? '10pt' : '7.5pt';
            $pad = $esAfiche ? '12' : '9';
            $logoSrc = '';
            if ($logo !== '' && is_readable($logo) && extension_loaded('gd')) {
                $png = (string) file_get_contents($logo);
                if ($png !== '') {
                    $logoSrc = '@' . base64_encode($png);
                }
            }
            if ($logoSrc !== '') {
                $logoH = $esAfiche ? 38 : 26;
                $cab .= '<table width="100%" cellpadding="' . $pad . '" cellspacing="0" nobr="true">'
                    . '<tr>'
                    . '<td width="76%" valign="middle" style="background-color:#' . self::INDIGO . ';border-bottom:3px solid #' . self::CYAN_BRILLO . ';">'
                    . '<span style="font-size:' . $kickerSz . ';font-weight:bold;color:#7DD3FC;">' . $e($kicker) . '</span><br>'
                    . '<span style="font-size:' . $titSz . ';font-weight:bold;color:#FFFFFF;">' . $e($tit) . '</span>'
                    . ($sub !== '' ? '<br><span style="font-size:8.5pt;color:#CBE7F7;">' . $e($sub) . '</span>' : '')
                    . '</td>'
                    . '<td width="24%" align="center" valign="middle" style="background-color:#FFFFFF;border-top:0.6px solid #'
                    . self::BORDE_SUAVE . ';border-right:0.6px solid #' . self::BORDE_SUAVE . ';border-bottom:3px solid #' . self::CYAN_BRILLO . ';">'
                    . '<div style="font-size:4pt;line-height:4pt;">&nbsp;</div><img src="' . $logoSrc . '" height="' . $logoH . '">'
                    . '</td></tr></table>';
            } else {
                $cab .= '<table width="100%" cellpadding="' . $pad . '" cellspacing="0" nobr="true">'
                    . '<tr><td style="background-color:#' . self::INDIGO . ';border-bottom:3px solid #' . self::CYAN_BRILLO . ';">'
                    . '<span style="font-size:' . $kickerSz . ';font-weight:bold;color:#7DD3FC;">' . $e($kicker) . '</span><br>'
                    . '<span style="font-size:' . $titSz . ';font-weight:bold;color:#FFFFFF;">' . $e($tit) . '</span>'
                    . ($sub !== '' ? '<br><span style="font-size:8.5pt;color:#CBE7F7;">' . $e($sub) . '</span>' : '')
                    . '</td></tr></table>';
            }

            if ($parts['meta_items']) {
                $cols = count($parts['meta_items']);
                $w = (int) floor(100 / max(1, $cols));
                $celdas = '';
                foreach (array_values($parts['meta_items']) as $idx => $mi) {
                    $cw = ($idx === $cols - 1) ? (100 - $w * ($cols - 1)) : $w;
                    $sep = $idx > 0 ? 'border-left:0.5px solid #CBE1F0;' : '';
                    $celdas .= '<td width="' . $cw . '%" style="' . $sep . 'background-color:#' . self::CYAN_CLARO . ';">'
                        . '<span style="font-size:6.8pt;font-weight:bold;color:#' . self::CYAN_OSCURO . ';">'
                        . $e(mb_strtoupper($mi['etiqueta'], 'UTF-8')) . '</span><br>'
                        . '<span style="font-size:8.5pt;font-weight:bold;color:#' . self::INDIGO . ';">'
                        . $e($mi['valor']) . '</span></td>';
                }
                $cab .= '<table width="100%" cellpadding="5" cellspacing="0" nobr="true" style="border-left:3px solid #'
                    . self::CYAN_BRILLO . ';border-right:0.6px solid #CBE1F0;border-bottom:0.6px solid #CBE1F0;">'
                    . '<tr>' . $celdas . '</tr></table>';
            }
            $cab .= $spacer;
        }
        $push($cab, false);

        // 2) Tarjeta de proposito del recurso.
        if ($parts['lead'] !== '') {
            $push('<table width="100%" cellpadding="7" cellspacing="0" nobr="true">'
                . '<tr><td style="background-color:#F6FBFE;border-top:0.6px solid #CFE3F1;border-right:0.6px solid #CFE3F1;'
                . 'border-bottom:0.6px solid #CFE3F1;border-left:3.5px solid #' . self::INDIGO . ';color:#243447;">'
                . '<span style="font-size:7.2pt;font-weight:bold;color:#' . self::CYAN . ';">PROPÓSITO DEL RECURSO</span><br>'
                . self::pdf_inline_polish($parts['lead'])
                . '</td></tr></table>' . $spacer, true);
        }

        // 3) Recorrer nodos del cuerpo agrupando unidades didacticas coherentes que no deben partirse entre paginas.
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $parts['body_html'] . '</body></html>');
        libxml_clear_errors();
        $body = $dom->getElementsByTagName('body')->item(0);
        $nodes = [];
        if ($body) {
            foreach ($body->childNodes as $child) {
                if ($child instanceof \DOMElement) {
                    $nodes[] = $child;
                }
            }
        }

        $isVisualTable = static function(\DOMElement $el): bool {
            if (strtolower($el->nodeName) !== 'table') {
                return false;
            }
            $cls = ' ' . $el->getAttribute('class') . ' ';
            return strpos($cls, ' m2-diagrama ') !== false || strpos($cls, ' m2-tarjetas ') !== false;
        };
        $isCompactTable = static function(\DOMElement $el) use ($isVisualTable): bool {
            if (strtolower($el->nodeName) !== 'table') {
                return false;
            }
            if ($isVisualTable($el)) {
                return true;
            }
            $trs = $el->getElementsByTagName('tr')->length;
            return $trs <= 6 && mb_strlen(trim($el->textContent), 'UTF-8') <= 1500;
        };
        $isCompactList = static function(\DOMElement $el): bool {
            $t = strtolower($el->nodeName);
            if ($t !== 'ul' && $t !== 'ol') {
                return false;
            }
            return $el->getElementsByTagName('li')->length <= 7 && mb_strlen(trim($el->textContent), 'UTF-8') <= 1400;
        };

        $secNum = 0;
        $n = count($nodes);
        for ($i = 0; $i < $n; $i++) {
            $el = $nodes[$i];
            $tag = strtolower($el->nodeName);
            $cls = ' ' . $el->getAttribute('class') . ' ';

            // Unidad A: Seccion <h2> + su apoyo visual + intro breve + diagrama/tarjetas inmediatos.
            if ($tag === 'h2') {
                $secNum++;
                $unidad = $spacer . self::pdf_section_banner($el->textContent, $secNum, $esTarjeta, $esAfiche);
                $j = $i + 1;
                // 1) Apoyo visual inmediato.
                if ($j < $n && strpos(' ' . $nodes[$j]->getAttribute('class') . ' ', ' cr-ilustracion ') !== false) {
                    $unidad .= $spacer . self::pdf_render_node($dom, $nodes[$j], $esTarjeta);
                    $j++;
                }
                // 2) Parrafo introductorio breve.
                if ($j < $n && strtolower($nodes[$j]->nodeName) === 'p'
                        && mb_strlen(trim($nodes[$j]->textContent), 'UTF-8') <= 460) {
                    $unidad .= $spacer . self::pdf_render_node($dom, $nodes[$j], $esTarjeta);
                    $j++;
                }
                // 3) Si inmediatamente sigue un diagrama o tarjetas (como en Seccion 2 de M1 o Seccion 3 de M3), mantenerlo unido.
                if ($j < $n && $isVisualTable($nodes[$j])) {
                    $unidad .= self::pdf_render_node($dom, $nodes[$j], $esTarjeta);
                    $j++;
                } else if ($j === $i + 1 && $j < $n && strtolower($nodes[$j]->nodeName) === 'h3') {
                    // Si tras <h2> viene directo un <h3> + contenido compacto, arrastrarlos juntos.
                    $unidad .= self::pdf_render_node($dom, $nodes[$j], $esTarjeta);
                    $j++;
                    if ($j < $n && ($isCompactList($nodes[$j]) || $isCompactTable($nodes[$j])
                            || (strtolower($nodes[$j]->nodeName) === 'p' && mb_strlen(trim($nodes[$j]->textContent), 'UTF-8') <= 480))) {
                        $unidad .= self::pdf_render_node($dom, $nodes[$j], $esTarjeta);
                        $j++;
                    }
                }
                $i = $j - 1;
                $push($unidad . $spacer, true);
                continue;
            }

            // Unidad B: Subtitulo <h3> / <h4> unido a su lista corta, parrafo, diagrama o tabla compacta.
            if ($tag === 'h3' || $tag === 'h4') {
                $unidad = self::pdf_render_node($dom, $el, $esTarjeta);
                $esAtomico = true;
                $minMm = 0.0;
                if ($i + 1 < $n) {
                    $sig = $nodes[$i + 1];
                    $sigTag = strtolower($sig->nodeName);
                    if ($isCompactList($sig) || $isCompactTable($sig)
                            || ($sigTag === 'p' && mb_strlen(trim($sig->textContent), 'UTF-8') <= 540)
                            || strpos(' ' . $sig->getAttribute('class') . ' ', ' cr-callout ') !== false) {
                        $unidad .= self::pdf_render_node($dom, $sig, $esTarjeta);
                        $i++;
                        // Si un <p> introductorio bajo <h3> precede a una lista corta (ej. «Rol de los pares» + lista), unirlos.
                        if ($sigTag === 'p' && $i + 1 < $n && $isCompactList($nodes[$i + 1])
                                && mb_strlen(trim($sig->textContent) . trim($nodes[$i + 1]->textContent), 'UTF-8') <= 1300) {
                            $unidad .= self::pdf_render_node($dom, $nodes[$i + 1], $esTarjeta);
                            $i++;
                        }
                    } else {
                        // Lista muy larga o tabla extensa: garantizar espacio minimo para el titulo + primeras filas.
                        $esAtomico = false;
                        $minMm = 36.0;
                    }
                }
                $push($unidad, $esAtomico, $minMm);
                continue;
            }

            // Unidad C: Parrafo introductorio breve justo antes de un diagrama, tarjetas o tabla compacta.
            if ($tag === 'p' && mb_strlen(trim($el->textContent), 'UTF-8') <= 360 && $i + 1 < $n
                    && ($isVisualTable($nodes[$i + 1]) || $isCompactTable($nodes[$i + 1]) || $isCompactList($nodes[$i + 1]))) {
                $unidad = self::pdf_render_node($dom, $el, $esTarjeta) . self::pdf_render_node($dom, $nodes[$i + 1], $esTarjeta);
                $i++;
                $push($unidad, true);
                continue;
            }

            // Unidad D: Recuadros, ilustraciones, diagramas, tarjetas y tablas compactas: siempre indivisibles.
            if (strpos($cls, ' cr-callout ') !== false || strpos($cls, ' cr-ilustracion ') !== false
                    || $isCompactTable($el) || $isCompactList($el)) {
                $push(self::pdf_render_node($dom, $el, $esTarjeta), true);
                continue;
            }

            // Unidad E: Tablas o listas extensas (fluyen en varias paginas con <thead> repetido y filas nobr="true").
            $push(self::pdf_render_node($dom, $el, $esTarjeta), $tag === 'p', $tag === 'table' ? 32.0 : 0.0);
        }

        // 4) Tarjeta de fuentes utilizadas (indivisible).
        if ($parts['fuentes'] && !$esTarjeta) {
            $icoSrc = self::svg_tcpdf_src('fuentes', 18);
            $itemsHtml = '';
            foreach ($parts['fuentes'] as $f) {
                $itemsHtml .= '<br><span style="color:#' . self::CYAN . ';font-weight:bold;">•</span> ' . $e($f);
            }
            $push($spacer . '<table width="100%" cellpadding="6" cellspacing="0" nobr="true">'
                . '<tr>'
                . '<td width="7%" align="center" valign="top" style="background-color:#' . self::FUENTE_BG
                . ';border-top:0.6px solid #D5DFE9;border-bottom:0.6px solid #D5DFE9;border-left:3px solid #' . self::TEXTO_SUAVE . ';">'
                . '<img src="' . $icoSrc . '" width="16" height="16"></td>'
                . '<td width="93%" valign="top" style="background-color:#' . self::FUENTE_BG
                . ';border-top:0.6px solid #D5DFE9;border-bottom:0.6px solid #D5DFE9;border-right:0.6px solid #D5DFE9;color:#'
                . self::TEXTO_SUAVE . ';font-size:8.3pt;">'
                . '<span style="font-size:7.6pt;font-weight:bold;color:#' . self::INDIGO . ';">FUENTES UTILIZADAS</span>'
                . $itemsHtml . '</td></tr></table>', true);
        }

        if ($esTarjeta && $parts['pie'] !== '') {
            $push('<p style="font-size:6.2pt;color:#' . self::TEXTO_SUAVE . ';"><em>' . $e($parts['pie']) . '</em></p>', false);
        }

        return $bloques;
    }

    /**
     * Cinta numerada de seccion (h2) para PDF.
     */
    private static function pdf_section_banner(string $texto, int $secNum, bool $esTarjeta, bool $esAfiche): string {
        $e = static fn(string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        [$badge, $limpio] = self::split_section_badge($texto, $secNum);
        $sz = $esAfiche ? '16pt' : ($esTarjeta ? '9.5pt' : '11pt');
        $pad = $esTarjeta ? '4' : ($esAfiche ? '8' : '6');
        if ($badge === '') {
            return '<table width="100%" cellpadding="' . $pad . '" cellspacing="0"><tr>'
                . '<td style="background-color:#EAF5FC;border-left:3.5px solid #' . self::INDIGO
                . ';border-bottom:1.5px solid #' . self::CYAN_BRILLO . ';font-size:' . $sz
                . ';font-weight:bold;color:#' . self::INDIGO . ';">' . $e($limpio) . '</td></tr></table>';
        }
        $bw = $esTarjeta ? '14%' : '8%';
        $tw = $esTarjeta ? '86%' : '92%';
        return '<table width="100%" cellpadding="' . $pad . '" cellspacing="0"><tr>'
            . '<td width="' . $bw . '" align="center" valign="middle" style="background-color:#' . self::INDIGO
            . ';color:#FFFFFF;font-size:' . ($esAfiche ? '14pt' : ($esTarjeta ? '8.5pt' : '10pt')) . ';font-weight:bold;">'
            . $e($badge) . '</td>'
            . '<td width="' . $tw . '" valign="middle" style="background-color:#EAF5FC;border-bottom:1.5px solid #'
            . self::CYAN_BRILLO . ';font-size:' . $sz . ';font-weight:bold;color:#' . self::INDIGO . ';">'
            . $e($limpio) . '</td></tr></table>';
    }

    /**
     * Renderiza un bloque del DOM del material en HTML optimizado para TCPDF.
     */
    private static function pdf_render_node(\DOMDocument $dom, \DOMElement $el, bool $esTarjeta): string {
        $tag = strtolower($el->nodeName);
        $cls = ' ' . $el->getAttribute('class') . ' ';
        $e = static fn(string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        $inner = '';
        foreach ($el->childNodes as $c) {
            $inner .= $dom->saveHTML($c);
        }

        // Tarjeta de apoyo visual con pictograma SVG vectorial real.
        if (strpos($cls, ' cr-ilustracion ') !== false) {
            $desc = trim($el->textContent);
            $ico = $el->getAttribute('data-icon') ?: $desc;
            $svgSrc = self::svg_tcpdf_src($ico, 24);
            $iw = $esTarjeta ? '14%' : '7%';
            $tw = $esTarjeta ? '86%' : '93%';
            return '<table width="100%" cellpadding="' . ($esTarjeta ? '3' : '5') . '" cellspacing="0" nobr="true"><tr>'
                . '<td width="' . $iw . '" align="center" valign="middle" style="background-color:#F2F9FD;border-top:0.6px solid #C4E0F0;'
                . 'border-bottom:0.6px solid #C4E0F0;border-left:3px solid #' . self::CYAN_BRILLO . ';">'
                . '<img src="' . $svgSrc . '" width="' . ($esTarjeta ? '15' : '21') . '" height="' . ($esTarjeta ? '15' : '21') . '"></td>'
                . '<td width="' . $tw . '" valign="middle" style="background-color:#F2F9FD;border-top:0.6px solid #C4E0F0;'
                . 'border-bottom:0.6px solid #C4E0F0;border-right:0.6px solid #C4E0F0;color:#334155;">'
                . '<span style="font-size:' . ($esTarjeta ? '6.2pt' : '7pt') . ';font-weight:bold;color:#' . self::CYAN . ';">APOYO VISUAL SUGERIDO</span><br>'
                . '<span style="font-size:' . ($esTarjeta ? '7.6pt' : '8.6pt') . ';color:#334155;"><em>' . $e($desc) . '</em></span>'
                . '</td></tr></table>';
        }

        // Recuadro didactico (Nota de profundizacion, Importante, Ejemplo, Recuerda...).
        if (strpos($cls, ' cr-callout ') !== false) {
            $lbl = $el->getAttribute('data-label') ?: 'Nota didáctica';
            $ico = $el->getAttribute('data-icon') ?: 'nota';
            $clas = self::classify_callout($lbl) ?? [
                'bg' => 'EAF7FB', 'bdr' => self::CYAN, 'bdr_soft' => 'BFE3F2', 'titulo_color' => self::CYAN_OSCURO,
            ];
            $svgSrc = self::svg_tcpdf_src($ico, 20);
            $cuerpo = preg_replace('#^\s*<p>(.*)</p>\s*$#isu', '$1', trim($inner));
            $cuerpo = self::pdf_inline_polish((string) $cuerpo);
            return '<div style="font-size:3pt;line-height:3pt;">&nbsp;</div>'
                . '<table width="100%" cellpadding="6" cellspacing="0" nobr="true"><tr>'
                . '<td width="7%" align="center" valign="top" style="background-color:#' . $clas['bg']
                . ';border-top:0.6px solid #' . $clas['bdr_soft'] . ';border-bottom:0.6px solid #' . $clas['bdr_soft']
                . ';border-left:3.5px solid #' . $clas['bdr'] . ';">'
                . '<img src="' . $svgSrc . '" width="18" height="18"></td>'
                . '<td width="93%" valign="top" style="background-color:#' . $clas['bg']
                . ';border-top:0.6px solid #' . $clas['bdr_soft'] . ';border-bottom:0.6px solid #' . $clas['bdr_soft']
                . ';border-right:0.6px solid #' . $clas['bdr_soft'] . ';color:#1E293B;font-size:9pt;">'
                . '<span style="font-size:8.8pt;font-weight:bold;color:#' . $clas['titulo_color'] . ';">' . $e($lbl) . '</span><br>'
                . $cuerpo . '</td></tr></table>'
                . '<div style="font-size:3pt;line-height:3pt;">&nbsp;</div>';
        }

        // Subtitulos h3 y h4 con barra lateral cyan (con aire entre la barra y el texto).
        if ($tag === 'h3') {
            return '<div style="font-size:3pt;line-height:3pt;">&nbsp;</div>'
                . '<table width="100%" cellpadding="3" cellspacing="0" nobr="true"><tr>'
                . '<td style="border-left:3px solid #' . self::CYAN_BRILLO . ';color:#' . self::CYAN_OSCURO
                . ';font-size:10.3pt;font-weight:bold;">&nbsp;' . $e(trim($el->textContent)) . '</td></tr></table>';
        }
        if ($tag === 'h4') {
            return '<h4 style="color:#' . self::INDIGO . ';font-size:9.8pt;font-weight:bold;">'
                . $e(trim($el->textContent)) . '</h4>';
        }

        // Tablas: diagramas de pasos, tarjetas comparativas o tabla de datos moderna con filas alternadas.
        if ($tag === 'table') {
            if (strpos($cls, ' m2-diagrama ') !== false) {
                return self::pdf_diagram_html((string) $dom->saveHTML($el));
            }
            if (strpos($cls, ' m2-tarjetas ') !== false) {
                return self::pdf_cards_html($el);
            }
            return self::pdf_table_html($dom, $el);
        }

        // Listas y parrafos normales con enfasis de terminos clave y marcas [REVISAR].
        $raw = (string) $dom->saveHTML($el);
        if ($tag === 'ul' || $tag === 'ol') {
            $raw = (string) preg_replace('#<li\b[^>]*>\s*<strong>([^<]+)</strong>#isu',
                '<li><strong style="color:#' . self::INDIGO . ';">$1</strong>', $raw);
        }
        return self::pdf_inline_polish($raw);
    }

    /**
     * Diagrama de pasos (m2-diagrama) moderno para PDF:
     *  - 2 a 4 pasos concisos: tira horizontal con cabeceras de color («PASO 1»...) y flechas vectoriales SVG.
     *  - 5+ pasos o textos largos: filas horizontales numeradas con insignia lateral y conectores SVG hacia abajo.
     */
    private static function pdf_diagram_html(string $tableHtml): string {
        $cajas = diagrama::extract_cards($tableHtml);
        if (!$cajas) {
            return diagrama::pdf_flows($tableHtml);
        }
        $e = static fn(string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');

        $pasos = [];
        $maxLen = 0;
        foreach ($cajas as $idx => $cHtml) {
            $partes = array_values(array_filter(array_map(
                static fn($s) => trim(html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                preg_split('#<br\s*/?>#i', $cHtml)
            ), 'strlen'));
            $rotulo = 'PASO ' . ($idx + 1);
            $principal = $partes[0] ?? '';
            $detalle = '';
            if (count($partes) >= 2 && preg_match('/^(paso|momento|fase|etapa|estaci[oó]n|parte)\s+\S{1,4}$/iu', $partes[0])) {
                $rotulo = mb_strtoupper($partes[0], 'UTF-8');
                $principal = $partes[1];
                $detalle = implode(' ', array_slice($partes, 2));
            } else if (count($partes) >= 2) {
                $detalle = implode(' ', array_slice($partes, 1));
            }
            $maxLen = max($maxLen, mb_strlen($principal . ' ' . $detalle, 'UTF-8'));
            $pasos[] = ['rotulo' => $rotulo, 'principal' => $principal, 'detalle' => $detalle];
        }

        $arrowRight = '@' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24">'
            . '<polygon points="6,4 19,12 6,20" fill="#' . self::CYAN_BRILLO . '"/></svg>');
        $arrowDown = '@' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="14" height="10" viewBox="0 0 24 16">'
            . '<polygon points="4,2 20,2 12,14" fill="#' . self::CYAN_BRILLO . '"/></svg>');

        $n = count($pasos);
        $colores = [self::INDIGO, self::CYAN, self::CYAN_OSCURO, self::INDIGO];

        // Tira horizontal para 2 a 4 pasos concisos (aspecto de infografia real).
        if ($n >= 2 && $n <= 4 && $maxLen <= 135) {
            $aw = 3;
            $cw = (int) floor((100 - $aw * ($n - 1)) / $n);
            $rem = 100 - $aw * ($n - 1) - $cw * $n;
            $hdrRow = '';
            $bodyRow = '';
            foreach ($pasos as $i => $p) {
                $w = $cw + ($i === $n - 1 ? $rem : 0);
                $bgHdr = $colores[$i % count($colores)];
                $hdrRow .= '<td width="' . $w . '%" align="center" valign="middle" style="background-color:#' . $bgHdr
                    . ';color:#FFFFFF;font-size:7.8pt;font-weight:bold;border:0.6px solid #' . $bgHdr . ';">'
                    . $e($p['rotulo']) . '</td>';
                $bodyRow .= '<td width="' . $w . '%" align="center" valign="middle" style="background-color:#F4FAFE;'
                    . 'border-left:0.7px solid #B9D9EC;border-right:0.7px solid #B9D9EC;border-bottom:0.7px solid #B9D9EC;color:#'
                    . self::INDIGO . ';font-size:8.6pt;">'
                    . '<strong>' . $e($p['principal']) . '</strong>'
                    . ($p['detalle'] !== '' ? '<br><span style="font-size:8pt;color:#334155;">' . $e($p['detalle']) . '</span>' : '')
                    . '</td>';
                if ($i < $n - 1) {
                    $hdrRow .= '<td width="' . $aw . '%" style="background-color:#FFFFFF;"></td>';
                    $bodyRow .= '<td width="' . $aw . '%" align="center" valign="middle" style="background-color:#FFFFFF;">'
                        . '<img src="' . $arrowRight . '" width="9" height="9"></td>';
                }
            }
            return '<div style="font-size:4pt;line-height:4pt;">&nbsp;</div>'
                . '<table width="100%" cellpadding="5" cellspacing="0" nobr="true">'
                . '<tr>' . $hdrRow . '</tr><tr>' . $bodyRow . '</tr></table>'
                . '<div style="font-size:4pt;line-height:4pt;">&nbsp;</div>';
        }

        // Filas horizontales numeradas para 5+ pasos o pasos detallados.
        $filas = [];
        foreach ($pasos as $i => $p) {
            $bgHdr = $colores[$i % count($colores)];
            $filas[] = '<tr nobr="true">'
                . '<td width="15%" align="center" valign="middle" style="background-color:#' . $bgHdr
                . ';color:#FFFFFF;font-size:8pt;font-weight:bold;">' . $e($p['rotulo']) . '</td>'
                . '<td width="85%" valign="middle" style="background-color:#F4FAFE;border-top:0.7px solid #B9D9EC;'
                . 'border-right:0.7px solid #B9D9EC;border-bottom:0.7px solid #B9D9EC;color:#1E293B;font-size:9pt;">'
                . '<strong style="color:#' . self::INDIGO . ';">' . $e($p['principal']) . '</strong>'
                . ($p['detalle'] !== '' ? '<br><span style="font-size:8.4pt;color:#334155;">' . $e($p['detalle']) . '</span>' : '')
                . '</td></tr>';
        }
        $conector = '<tr nobr="true"><td width="15%" align="center" valign="middle" style="padding:1px;">'
            . '<img src="' . $arrowDown . '" width="10" height="7"></td><td width="85%"></td></tr>';
        $nobrTbl = $n <= 6 ? ' nobr="true"' : '';
        return '<div style="font-size:3pt;line-height:3pt;">&nbsp;</div>'
            . '<table width="100%" cellpadding="5" cellspacing="0"' . $nobrTbl . '>' . implode($conector, $filas) . '</table>'
            . '<div style="font-size:3pt;line-height:3pt;">&nbsp;</div>';
    }

    /**
     * Tarjetas comparativas lado a lado (m2-tarjetas) con barra superior de color para PDF.
     */
    private static function pdf_cards_html(\DOMElement $table): string {
        $e = static fn(string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        $acentos = [self::CYAN, self::INDIGO, self::VERDE, self::AMBAR];
        $out = '';
        foreach ($table->getElementsByTagName('tr') as $tr) {
            $tds = [];
            foreach ($tr->childNodes as $c) {
                if ($c instanceof \DOMElement && ($c->nodeName === 'td' || $c->nodeName === 'th')) {
                    $tds[] = $c;
                }
            }
            if (!$tds) {
                continue;
            }
            $n = count($tds);
            $gap = $n > 1 ? 2 : 0;
            $cw = (int) floor((100 - $gap * ($n - 1)) / $n);
            $rem = 100 - $gap * ($n - 1) - $cw * $n;
            $cells = '';
            foreach ($tds as $idx => $td) {
                $w = $cw + ($idx === $n - 1 ? $rem : 0);
                $ac = $acentos[$idx % count($acentos)];
                $inner = '';
                foreach ($td->childNodes as $ch) {
                    $inner .= $td->ownerDocument->saveHTML($ch);
                }
                $inner = self::pdf_inline_polish($inner);
                $cells .= '<td width="' . $w . '%" valign="top" style="background-color:#F6FBFE;border-top:3px solid #' . $ac
                    . ';border-left:0.7px solid #C5DFEE;border-right:0.7px solid #C5DFEE;border-bottom:0.7px solid #C5DFEE;color:#1E293B;font-size:8.8pt;">'
                    . $inner . '</td>';
                if ($idx < $n - 1) {
                    $cells .= '<td width="' . $gap . '%"></td>';
                }
            }
            $out .= '<div style="font-size:3pt;line-height:3pt;">&nbsp;</div>'
                . '<table width="100%" cellpadding="7" cellspacing="0" nobr="true"><tr>' . $cells . '</tr></table>'
                . '<div style="font-size:3pt;line-height:3pt;">&nbsp;</div>';
        }
        return $out;
    }

    /**
     * Tabla de datos moderna para PDF (encabezado indigo con borde inferior cyan, filas alternadas y nobr por fila).
     */
    private static function pdf_table_html(\DOMDocument $dom, \DOMElement $table): string {
        $rows = [];
        foreach ($table->getElementsByTagName('tr') as $tr) {
            $cells = [];
            $isHead = false;
            foreach ($tr->childNodes as $c) {
                if ($c instanceof \DOMElement && ($c->nodeName === 'th' || $c->nodeName === 'td')) {
                    if ($c->nodeName === 'th') {
                        $isHead = true;
                    }
                    $inner = '';
                    foreach ($c->childNodes as $ch) {
                        $inner .= $dom->saveHTML($ch);
                    }
                    $cells[] = [
                        'tag' => $c->nodeName,
                        'span' => max(1, (int) $c->getAttribute('colspan')),
                        'html' => self::pdf_inline_polish(trim($inner)),
                    ];
                }
            }
            if ($cells) {
                $rows[] = ['head' => $isHead, 'cells' => $cells];
            }
        }
        if (!$rows) {
            return '';
        }
        $thead = '';
        $tbody = '';
        $bodyIdx = 0;
        foreach ($rows as $rIdx => $row) {
            $esCab = $row['head'] && $rIdx === 0;
            $trHtml = '<tr nobr="true">';
            foreach ($row['cells'] as $c) {
                $spanAttr = $c['span'] > 1 ? ' colspan="' . $c['span'] . '"' : '';
                if ($esCab) {
                    $trHtml .= '<th' . $spanAttr . ' style="background-color:#' . self::INDIGO
                        . ';color:#FFFFFF;font-weight:bold;font-size:8.6pt;border:0.6px solid #2A3F7A;'
                        . 'border-bottom:1.8px solid #' . self::CYAN_BRILLO . ';">' . $c['html'] . '</th>';
                } else {
                    $bg = ($bodyIdx % 2 === 1) ? '#F3F8FC' : '#FFFFFF';
                    $trHtml .= '<td' . $spanAttr . ' style="background-color:' . $bg
                        . ';color:#1E293B;font-size:8.6pt;border:0.6px solid #' . self::BORDE_SUAVE . ';">'
                        . $c['html'] . '</td>';
                }
            }
            $trHtml .= '</tr>';
            if ($esCab) {
                $thead .= $trHtml;
            } else {
                $tbody .= $trHtml;
                $bodyIdx++;
            }
        }
        return '<div style="font-size:3pt;line-height:3pt;">&nbsp;</div>'
            . '<table width="100%" cellpadding="5" cellspacing="0">'
            . ($thead !== '' ? '<thead>' . $thead . '</thead>' : '')
            . '<tbody>' . $tbody . '</tbody></table>'
            . '<div style="font-size:3pt;line-height:3pt;">&nbsp;</div>';
    }

    /**
     * Ajustes en linea para PDF: casillas ZapfDingbats, pictogramas en linea y marcas [REVISAR] en ambar suave.
     */
    private static function pdf_inline_polish(string $html): string {
        $html = self::pictogram_captions($html);
        $html = str_replace('☐', '<span style="font-family:zapfdingbats;color:#' . self::CYAN . ';">o</span>', $html);
        return (string) preg_replace_callback('/\[REVISAR(?:\s*:\s*[^\]]+)?\]/u', static function(array $m): string {
            return '<span style="background-color:#FEF3C7;color:#92400E;font-weight:bold;"> '
                . htmlspecialchars($m[0], ENT_QUOTES, 'UTF-8') . ' </span>';
        }, $html);
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
            $autoload = __DIR__ . '/../../../lib/phpspreadsheet/vendor/autoload.php';
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
