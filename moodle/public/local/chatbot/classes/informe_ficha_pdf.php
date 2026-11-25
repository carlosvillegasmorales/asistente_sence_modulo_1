<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Genera el Informe de validacion de la ficha en PDF con identidad visual y logo de Interfases Consultores.
 *
 * Comparte un unico motor limpio para las tres fichas del ecosistema:
 *  - Modulo 2, Fase 1: Ficha de diagnostico (evaluacion ex-ante, ficha_exante.php)
 *  - Modulo 3, Fase 1: Ficha de registro de la implementacion (ficha_implementacion.php)
 *  - Modulo 3, Fase 4: Ficha de evaluacion ex-post (ficha_expost.php)
 *
 * Usa el TCPDF incluido en Moodle (sin servicios externos) y paginacion inteligente bloque a bloque con
 * transacciones TCPDF para evitar tarjetas partidas, filas de checklist cortadas o titulos huerfanos.
 * Todo el armado de bloques y PDF es puro (no consulta BD) y lo ejercita cli/verificar_informe_ficha_pdf.php.
 */
class informe_ficha_pdf {

    /** Paleta institucional armonizada con el logo de Interfases Consultores. */
    const NAVY = '#1B2B5E';
    const CYAN = '#1EA7E1';
    const CYAN_OSCURO = '#0E7490';
    const CYAN_SUAVE = '#EAF6FC';
    const TEXTO = '#1E293B';
    const GRIS = '#64748B';
    const BORDE = '#CBD5E1';
    const BORDE_SUAVE = '#E2E8F0';
    const ZEBRA = '#F8FAFC';

    /**
     * Resumen numerico de estados del checklist (Cumple, Parcial, No cumple, Sin evaluar). Funcion pura.
     *
     * @param array $checklist Secciones del checklist (id => texto).
     * @param array $items Mapa id => ['estado' => ...].
     * @return array ['cumple' => int, 'parcial' => int, 'no_cumple' => int, 'sin_evaluar' => int, 'total' => int]
     */
    public static function summary_counts(array $checklist, array $items): array {
        $c = ['cumple' => 0, 'parcial' => 0, 'no_cumple' => 0, 'sin_evaluar' => 0, 'total' => 0];
        foreach ($checklist as $sec) {
            foreach ((array) ($sec['items'] ?? []) as $id => $_) {
                $c['total']++;
                $st = (string) ($items[$id]['estado'] ?? '');
                if ($st === 'cumple') {
                    $c['cumple']++;
                } else if ($st === 'parcial') {
                    $c['parcial']++;
                } else if ($st === 'no_cumple') {
                    $c['no_cumple']++;
                } else {
                    $c['sin_evaluar']++;
                }
            }
        }
        return $c;
    }

    /**
     * Texto apto para la fuente del PDF (freesans): quita los simbolos y emojis de las cadenas de idioma («✔ Cumple»,
     * «✅ Aprobado», «⚠»), que freesans no trae y TCPDF dibuja como un cuadro vacio. El color de la insignia ya indica
     * el estado. Funcion pura.
     *
     * @param string $t
     * @return string
     */
    public static function pdf_text(string $t): string {
        $limpio = preg_replace('/[\x{2300}-\x{23FF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{1F000}-\x{1FAFF}\x{FE0F}\x{200D}]/u', '', $t);
        if ($limpio === null || $limpio === $t) {
            return $t;
        }
        return trim(preg_replace('/ {2,}/u', ' ', $limpio));
    }

    /**
     * Colores de insignia segun condicion o estado. Funcion pura.
     *
     * @param string $clave
     * @return array ['bg' => hex, 'fg' => hex, 'bd' => hex]
     */
    public static function badge_colors(string $clave): array {
        $mapa = [
            'aprobado' => ['bg' => '#DCFCE7', 'fg' => '#166534', 'bd' => '#86EFAC'],
            'cumple' => ['bg' => '#DCFCE7', 'fg' => '#166534', 'bd' => '#86EFAC'],
            'observaciones' => ['bg' => '#FEF3C7', 'fg' => '#92400E', 'bd' => '#FCD34D'],
            'parcial' => ['bg' => '#FEF3C7', 'fg' => '#92400E', 'bd' => '#FCD34D'],
            'no_aprobado' => ['bg' => '#FEE2E2', 'fg' => '#991B1B', 'bd' => '#FCA5A5'],
            'no_cumple' => ['bg' => '#FEE2E2', 'fg' => '#991B1B', 'bd' => '#FCA5A5'],
        ];
        return $mapa[$clave] ?? ['bg' => '#F1F5F9', 'fg' => '#475569', 'bd' => '#CBD5E1'];
    }

    /**
     * Construye los bloques HTML semanticos (con bandera «atomic» para paginacion inteligente TCPDF) del informe de
     * validacion de la ficha. Funcion pura.
     *
     * @param array $spec Datos normalizados del informe (titulo, modulo, curso, fecha, meta, checklist, items, condicion...).
     * @param string $logo Ruta opcional al PNG del logo de la consultora (pix/logo_interfases.png).
     * @return array[] Lista de ['html' => string, 'atomic' => bool, 'min_space_mm' => float].
     */
    public static function build_blocks(array $spec, string $logo = ''): array {
        $e = static fn(string $t): string => htmlspecialchars(self::pdf_text($t), ENT_QUOTES, 'UTF-8');
        $spacer = '<div style="font-size:4pt;line-height:4pt;">&nbsp;</div>';
        $bloques = [];
        $push = static function(string $html, bool $atomic = true, float $minMm = 0.0) use (&$bloques): void {
            if (trim($html) !== '') {
                $bloques[] = ['html' => $html, 'atomic' => $atomic, 'min_space_mm' => $minMm];
            }
        };

        $lbl = array_merge([
            'item' => 'Ítem',
            'eval' => 'Evaluación',
            'obs' => 'Observaciones y propuestas',
            'critico' => 'CRÍTICO',
            'calculado' => 'Calculado por plataforma',
            'como_mejorar' => 'Cómo mejorarlo:',
            'forma_texto' => 'Diferencia de forma: no detiene la ficha.',
            'estado_cumple' => 'Cumple',
            'estado_parcial' => 'Parcial',
            'estado_no_cumple' => 'No cumple',
            'estado_none' => 'No evaluado',
            'estado_none_help' => 'Ítem incorporado después de esta validación.',
            'sec_checklist' => 'Evaluación por ítem del checklist',
            'sec_condicion' => 'Condición final',
            'sec_tiempo' => 'Tiempo restante disponible',
            'sec_sugerencias' => 'Observaciones y propuestas de mejora',
            'sec_debilidades' => 'Principales debilidades detectadas',
            'sec_propuestas' => 'Propuestas concretas de mejora',
            'sec_pendientes' => 'Observaciones pendientes de resolver',
            'pendientes_ayuda' => '',
            'pendientes_ninguno' => 'No hay observaciones pendientes.',
            'debe_corregir' => 'Para avanzar debes corregir lo siguiente',
            'cita_label' => 'Fragmento de la ficha:',
            'sec_verificaciones' => 'Verificaciones numéricas de la plataforma',
            'fuentes_web' => 'Fuentes web consultadas:',
            'ninguno' => 'Ninguna.',
            'seccion_n' => 'Sección %s',
        ], (array) ($spec['etiquetas'] ?? []));

        $checklist = (array) ($spec['checklist'] ?? []);
        $items = (array) ($spec['items'] ?? []);
        $criticos = (array) ($spec['criticos'] ?? []);
        $counts = self::summary_counts($checklist, $items);

        // 1) Cabecera Hero con logo de la consultora + Tira de resumen (condicion y conteos).
        $kicker = trim((string) ($spec['modulo'] ?? 'VALIDACIÓN DE FICHA'));
        $titulo = trim((string) ($spec['titulo'] ?? 'Informe de validación de la ficha'));
        $subPartes = array_values(array_filter([
            trim((string) ($spec['curso'] ?? '')),
            trim((string) ($spec['fecha'] ?? '')),
        ], static fn($x) => $x !== ''));
        $subtitulo = implode('  ·  ', $subPartes);

        $logoSrc = '';
        if ($logo !== '' && is_readable($logo) && extension_loaded('gd')) {
            $png = (string) file_get_contents($logo);
            if ($png !== '') {
                $logoSrc = '@' . base64_encode($png);
            }
        }

        $cab = '';
        if ($logoSrc !== '') {
            $cab .= '<table width="100%" cellpadding="9" cellspacing="0"><tr>'
                . '<td width="74%" valign="middle" style="background-color:' . self::NAVY
                . ';border-bottom:3px solid ' . self::CYAN . ';">'
                . '<span style="font-size:7.5pt;font-weight:bold;color:#7DD3FC;">' . $e($kicker) . '</span><br>'
                . '<span style="font-size:14pt;font-weight:bold;color:#FFFFFF;">' . $e($titulo) . '</span>'
                . ($subtitulo !== '' ? '<br><span style="font-size:8.2pt;color:#CBE7F7;">' . $e($subtitulo) . '</span>' : '')
                . '</td>'
                . '<td width="26%" align="center" valign="middle" style="background-color:#FFFFFF;border-top:0.6px solid '
                . self::BORDE_SUAVE . ';border-right:0.6px solid ' . self::BORDE_SUAVE . ';border-bottom:3px solid ' . self::CYAN . ';">'
                . '<div style="font-size:3pt;line-height:3pt;">&nbsp;</div><img src="' . $logoSrc . '" height="28">'
                . '</td></tr></table>';
        } else {
            $cab .= '<table width="100%" cellpadding="9" cellspacing="0"><tr>'
                . '<td style="background-color:' . self::NAVY . ';border-bottom:3px solid ' . self::CYAN . ';">'
                . '<span style="font-size:7.5pt;font-weight:bold;color:#7DD3FC;">' . $e($kicker) . '</span><br>'
                . '<span style="font-size:14pt;font-weight:bold;color:#FFFFFF;">' . $e($titulo) . '</span>'
                . ($subtitulo !== '' ? '<br><span style="font-size:8.2pt;color:#CBE7F7;">' . $e($subtitulo) . '</span>' : '')
                . '</td></tr></table>';
        }

        // Tira KPI: Condicion final + Cumple / Parcial / No cumple.
        $cond = (string) ($spec['condicion'] ?? '');
        $condTitulo = trim((string) ($spec['condicion_titulo'] ?? ''));
        $cc = self::badge_colors($cond);
        $cab .= '<table width="100%" cellpadding="6" cellspacing="0"><tr>'
            . '<td width="46%" style="background-color:' . $cc['bg'] . ';border-left:3px solid ' . $cc['bd']
            . ';border-bottom:0.8px solid ' . $cc['bd'] . ';border-right:0.5px solid ' . self::BORDE_SUAVE . ';">'
            . '<span style="font-size:6.8pt;font-weight:bold;color:' . $cc['fg'] . ';">RESULTADO DE LA VALIDACIÓN</span><br>'
            . '<span style="font-size:10.5pt;font-weight:bold;color:' . $cc['fg'] . ';">' . $e($condTitulo) . '</span></td>'
            . '<td width="18%" align="center" style="background-color:#F0FDF4;border-bottom:0.8px solid ' . self::BORDE_SUAVE
            . ';border-right:0.5px solid ' . self::BORDE_SUAVE . ';">'
            . '<span style="font-size:6.6pt;font-weight:bold;color:#166534;">CUMPLE</span><br>'
            . '<span style="font-size:11pt;font-weight:bold;color:#166534;">' . $counts['cumple'] . '</span></td>'
            . '<td width="18%" align="center" style="background-color:#FFFBEB;border-bottom:0.8px solid ' . self::BORDE_SUAVE
            . ';border-right:0.5px solid ' . self::BORDE_SUAVE . ';">'
            . '<span style="font-size:6.6pt;font-weight:bold;color:#92400E;">PARCIAL</span><br>'
            . '<span style="font-size:11pt;font-weight:bold;color:#92400E;">' . $counts['parcial'] . '</span></td>'
            . '<td width="18%" align="center" style="background-color:#FEF2F2;border-bottom:0.8px solid ' . self::BORDE_SUAVE . ';">'
            . '<span style="font-size:6.6pt;font-weight:bold;color:#991B1B;">NO CUMPLE</span><br>'
            . '<span style="font-size:11pt;font-weight:bold;color:#991B1B;">' . $counts['no_cumple'] . '</span></td>'
            . '</tr></table>' . $spacer;

        // Aviso de informe no vigente si la ficha fue editada despues de validar.
        if (array_key_exists('vigente', $spec) && !$spec['vigente'] && !empty($spec['stale_text'])) {
            $cab .= '<table width="100%" cellpadding="6" cellspacing="0"><tr>'
                . '<td style="background-color:#FEF3C7;border-left:3px solid #D97706;border-top:0.5px solid #FCD34D;'
                . 'border-right:0.5px solid #FCD34D;border-bottom:0.5px solid #FCD34D;color:#92400E;font-size:8.2pt;">'
                . '<strong>Aviso:</strong> ' . $e((string) $spec['stale_text']) . '</td></tr></table>' . $spacer;
        }
        $push($cab, false);

        // 2) Ficha de identificacion (metadatos de la ficha validada).
        $meta = array_values(array_filter((array) ($spec['meta'] ?? []),
            static fn($m) => trim((string) ($m['valor'] ?? '')) !== ''));
        if ($meta) {
            $metaHtml = '<table width="100%" cellpadding="5" cellspacing="0" style="border:0.6px solid ' . self::BORDE . ';">';
            foreach ($meta as $i => $m) {
                $bg = ($i % 2 === 0) ? self::ZEBRA : '#FFFFFF';
                $metaHtml .= '<tr>'
                    . '<td width="28%" style="background-color:' . self::CYAN_SUAVE . ';color:' . self::NAVY
                    . ';font-size:8pt;font-weight:bold;border-bottom:0.5px solid ' . self::BORDE_SUAVE
                    . ';border-right:0.5px solid ' . self::BORDE_SUAVE . ';">' . $e((string) ($m['etiqueta'] ?? '')) . '</td>'
                    . '<td width="72%" style="background-color:' . $bg . ';color:' . self::TEXTO
                    . ';font-size:8.3pt;border-bottom:0.5px solid ' . self::BORDE_SUAVE . ';">'
                    . $e((string) ($m['valor'] ?? '')) . '</td></tr>';
            }
            $metaHtml .= '</table>' . $spacer;
            $push($metaHtml, true);
        }

        // 3) Seccion A: Evaluacion por item del checklist (una subtabla por seccion del checklist para paginacion limpia).
        $push(self::section_heading('a)', (string) $lbl['sec_checklist']) . $spacer, false, 30.0);
        foreach ($checklist as $n => $sec) {
            $secTitulo = $n . '. ' . (string) ($sec['titulo'] ?? '');
            $t = '<table width="100%" cellpadding="5" cellspacing="0" style="border:0.6px solid ' . self::BORDE . ';">'
                . '<thead>'
                . '<tr nobr="true"><th colspan="3" style="background-color:' . self::NAVY . ';color:#FFFFFF;font-size:8.3pt;'
                . 'font-weight:bold;border-bottom:2px solid ' . self::CYAN . ';">' . $e($secTitulo) . '</th></tr>'
                . '<tr nobr="true">'
                . '<th width="36%" style="background-color:' . self::CYAN_SUAVE . ';color:' . self::NAVY
                . ';font-size:7.6pt;font-weight:bold;border-bottom:0.6px solid ' . self::BORDE . ';">' . $e((string) $lbl['item']) . '</th>'
                . '<th width="16%" align="center" style="background-color:' . self::CYAN_SUAVE . ';color:' . self::NAVY
                . ';font-size:7.6pt;font-weight:bold;border-bottom:0.6px solid ' . self::BORDE . ';">' . $e((string) $lbl['eval']) . '</th>'
                . '<th width="48%" style="background-color:' . self::CYAN_SUAVE . ';color:' . self::NAVY
                . ';font-size:7.6pt;font-weight:bold;border-bottom:0.6px solid ' . self::BORDE . ';">' . $e((string) $lbl['obs']) . '</th>'
                . '</tr></thead><tbody>';
            $rowIdx = 0;
            foreach ((array) ($sec['items'] ?? []) as $id => $texto) {
                $bg = ($rowIdx % 2 === 0) ? '#FFFFFF' : self::ZEBRA;
                $rowIdx++;
                $item = $items[$id] ?? null;
                $tags = '';
                if (in_array($id, $criticos, true)) {
                    $tags .= ' <span style="color:#B91C1C;font-weight:bold;font-size:7pt;">[' . $e((string) $lbl['critico']) . ']</span>';
                }
                if (is_array($item) && ($item['origen'] ?? '') === 'calculo') {
                    $tags .= ' <span style="color:' . self::CYAN_OSCURO . ';font-size:7pt;">[' . $e((string) $lbl['calculado']) . ']</span>';
                }

                if ($item === null) {
                    $stTxt = (string) $lbl['estado_none'];
                    $stC = self::badge_colors('');
                    $obsHtml = '<span style="color:' . self::GRIS . ';">' . $e((string) $lbl['estado_none_help']) . '</span>';
                } else {
                    $st = (string) ($item['estado'] ?? '');
                    $stC = self::badge_colors($st);
                    $stTxt = (string) ($lbl['estado_' . $st] ?? $st);
                    $obsHtml = $e((string) ($item['observacion'] ?? ''));
                    if (!empty($item['forma'])) {
                        $obsHtml .= '<br><span style="color:' . self::GRIS . ';font-style:italic;font-size:7.6pt;">'
                            . $e((string) $lbl['forma_texto']) . '</span>';
                    }
                    if (!empty($item['propuesta'])) {
                        $obsHtml .= '<br><span style="color:#0F766E;font-weight:bold;">' . $e((string) $lbl['como_mejorar']) . '</span> '
                            . $e((string) $item['propuesta']);
                    }
                }

                $t .= '<tr nobr="true">'
                    . '<td width="36%" style="background-color:' . $bg . ';color:' . self::TEXTO . ';font-size:8pt;'
                    . 'border-bottom:0.5px solid ' . self::BORDE_SUAVE . ';border-right:0.5px solid ' . self::BORDE_SUAVE . ';">'
                    . $e((string) $texto) . $tags . '</td>'
                    . '<td width="16%" align="center" valign="middle" style="background-color:' . $stC['bg'] . ';color:' . $stC['fg']
                    . ';font-size:7.8pt;font-weight:bold;border-bottom:0.5px solid ' . self::BORDE_SUAVE
                    . ';border-right:0.5px solid ' . self::BORDE_SUAVE . ';">' . $e($stTxt) . '</td>'
                    . '<td width="48%" style="background-color:' . $bg . ';color:' . self::TEXTO . ';font-size:8pt;'
                    . 'border-bottom:0.5px solid ' . self::BORDE_SUAVE . ';">' . $obsHtml . '</td>'
                    . '</tr>';
            }
            $t .= '</tbody></table>' . $spacer;
            // Nunca envolver una tabla con <thead> y <tr nobr="true"> en una transaccion externa de TCPDF
            // (TCPDF sobrescribe objcopy internamente en cada nobr). Usamos flujo nativo + espacio minimo para titulo+primera fila.
            $push($t, false, 34.0);
        }

        // 4) Seccion B: Condicion final, regla aplicada e items que detienen la ficha (si aplica).
        $condBox = self::section_heading('b)', (string) $lbl['sec_condicion']) . $spacer
            . '<table width="100%" cellpadding="7" cellspacing="0"><tr>'
            . '<td style="background-color:' . $cc['bg'] . ';border-left:3.5px solid ' . $cc['bd']
            . ';border-top:0.5px solid ' . $cc['bd'] . ';border-right:0.5px solid ' . $cc['bd']
            . ';border-bottom:0.5px solid ' . $cc['bd'] . ';color:' . $cc['fg'] . ';font-size:8.6pt;">'
            . '<strong>' . $e($condTitulo) . '.</strong> ' . $e((string) ($spec['condicion_desc'] ?? ''));
        if (!empty($spec['regla_text'])) {
            $condBox .= '<br><br><span style="font-size:8pt;"><strong>' . $e((string) ($spec['regla_label'] ?? 'Regla aplicada:'))
                . '</strong> ' . $e((string) $spec['regla_text']) . '</span>';
        }
        $condBox .= '</td></tr></table>' . $spacer;
        $push($condBox, true);

        $detienen = (array) ($spec['detienen'] ?? []);
        if ($detienen) {
            $li = '';
            foreach ($detienen as $d) {
                $li .= '<li><strong>' . $e((string) ($d['texto'] ?? '')) . '</strong>';
                if (!empty($d['observacion'])) {
                    $li .= '<br>' . $e((string) $d['observacion']);
                }
                if (!empty($d['propuesta'])) {
                    $li .= '<br><strong>' . $e((string) $lbl['como_mejorar']) . '</strong> ' . $e((string) $d['propuesta']);
                }
                $li .= '</li>';
            }
            $push('<table width="100%" cellpadding="7" cellspacing="0"><tr>'
                . '<td style="background-color:#FEF2F2;border-left:3.5px solid #DC2626;border-top:0.5px solid #FCA5A5;'
                . 'border-right:0.5px solid #FCA5A5;border-bottom:0.5px solid #FCA5A5;color:#991B1B;font-size:8.2pt;">'
                . '<strong>' . $e((string) $lbl['debe_corregir']) . '</strong><ul>' . $li . '</ul></td></tr></table>' . $spacer, true);
        }

        $motivo = (array) ($spec['motivo'] ?? []);
        if (!empty($motivo['titulo'])) {
            $mHtml = '<strong>' . $e((string) $lbl['debe_corregir']) . '</strong><br>'
                . '<strong>' . $e((string) $motivo['titulo']) . '</strong>';
            if (!empty($motivo['cita'])) {
                $mHtml .= '<br>' . $e((string) $lbl['cita_label']) . ' «' . $e((string) $motivo['cita']) . '»';
            }
            if (!empty($motivo['corregir'])) {
                $mHtml .= '<br><strong>' . $e((string) $lbl['como_mejorar']) . '</strong> ' . $e((string) $motivo['corregir']);
            }
            $push('<table width="100%" cellpadding="7" cellspacing="0"><tr>'
                . '<td style="background-color:#FEF2F2;border-left:3.5px solid #DC2626;border-top:0.5px solid #FCA5A5;'
                . 'border-right:0.5px solid #FCA5A5;border-bottom:0.5px solid #FCA5A5;color:#991B1B;font-size:8.2pt;">'
                . $mHtml . '</td></tr></table>' . $spacer, true);
        }

        // 5) Bloque opcional de tiempo restante (Modulo 3, Fase 1).
        $tiempo = (array) ($spec['tiempo'] ?? []);
        if ($tiempo) {
            $okT = (($tiempo['estado'] ?? 'ok') === 'ok');
            $tRestBg = $okT ? self::CYAN_SUAVE : '#FEF3C7';
            $tRestFg = $okT ? self::NAVY : '#92400E';
            $tBox = self::section_heading('', (string) $lbl['sec_tiempo']) . $spacer
                . '<table width="100%" cellpadding="6" cellspacing="0" style="border:0.6px solid ' . self::BORDE . ';"><tr>'
                . '<td width="33%" align="center" style="background-color:' . self::ZEBRA . ';border-right:0.5px solid ' . self::BORDE_SUAVE . ';">'
                . '<span style="font-size:7pt;color:' . self::GRIS . ';">' . $e((string) ($tiempo['lbl_total'] ?? 'Tiempo total')) . '</span><br>'
                . '<span style="font-size:11pt;font-weight:bold;color:' . self::NAVY . ';">' . $e((string) ($tiempo['total'] ?? '—')) . '</span></td>'
                . '<td width="33%" align="center" style="background-color:' . self::ZEBRA . ';border-right:0.5px solid ' . self::BORDE_SUAVE . ';">'
                . '<span style="font-size:7pt;color:' . self::GRIS . ';">' . $e((string) ($tiempo['lbl_usado'] ?? 'Tiempo utilizado')) . '</span><br>'
                . '<span style="font-size:11pt;font-weight:bold;color:' . self::NAVY . ';">' . $e((string) ($tiempo['utilizado'] ?? '—')) . '</span></td>'
                . '<td width="34%" align="center" style="background-color:' . $tRestBg . ';">'
                . '<span style="font-size:7pt;color:' . $tRestFg . ';">' . $e((string) ($tiempo['lbl_restante'] ?? 'Tiempo restante')) . '</span><br>'
                . '<span style="font-size:11pt;font-weight:bold;color:' . $tRestFg . ';">' . $e((string) ($tiempo['restante'] ?? '—')) . '</span></td>'
                . '</tr></table>';
            if (!empty($tiempo['nota'])) {
                $tBox .= '<p style="font-size:8pt;color:' . ($okT ? self::GRIS : '#92400E') . ';">' . $e((string) $tiempo['nota']) . '</p>';
            }
            $push($tBox . $spacer, true);
        }

        // 6) Seccion C: Debilidades y sugerencias de mejora.
        $deb = array_values(array_filter(array_map('strval', (array) ($spec['debilidades'] ?? []))));
        $sug = array_values(array_filter(array_map('strval', (array) ($spec['sugerencias'] ?? []))));
        $secC = self::section_heading('c)', (string) $lbl['sec_sugerencias']) . $spacer;
        $secC .= '<p style="font-size:8.6pt;font-weight:bold;color:' . self::NAVY . ';">' . $e((string) $lbl['sec_debilidades']) . '</p>';
        if ($deb) {
            $secC .= '<ul style="font-size:8.3pt;color:' . self::TEXTO . ';">'
                . implode('', array_map(static fn($x) => '<li>' . $e($x) . '</li>', $deb)) . '</ul>';
        } else {
            $secC .= '<p style="font-size:8.2pt;color:' . self::GRIS . ';">' . $e((string) $lbl['ninguno']) . '</p>';
        }
        $push($secC, count($deb) <= 5, 22.0);

        $secProp = '<p style="font-size:8.6pt;font-weight:bold;color:' . self::NAVY . ';">' . $e((string) $lbl['sec_propuestas']) . '</p>';
        if ($sug) {
            $secProp .= '<ul style="font-size:8.3pt;color:' . self::TEXTO . ';">'
                . implode('', array_map(static fn($x) => '<li>' . $e($x) . '</li>', $sug)) . '</ul>';
        } else {
            $secProp .= '<p style="font-size:8.2pt;color:' . self::GRIS . ';">' . $e((string) $lbl['ninguno']) . '</p>';
        }
        $push($secProp . $spacer, count($sug) <= 5, 20.0);

        // 7) Seccion D: Observaciones pendientes de resolver.
        $pend = (array) ($spec['pendientes'] ?? []);
        $secD = self::section_heading('d)', (string) $lbl['sec_pendientes']) . $spacer;
        if ($pend) {
            if (!empty($lbl['pendientes_ayuda'])) {
                $secD .= '<p style="font-size:8pt;color:' . self::GRIS . ';">' . $e((string) $lbl['pendientes_ayuda']) . '</p>';
            }
            $lis = '';
            foreach ($pend as $p) {
                $st = (string) ($p['estado'] ?? '');
                $stC = self::badge_colors($st);
                $stTxt = (string) ($lbl['estado_' . $st] ?? $st);
                $secTxt = sprintf((string) $lbl['seccion_n'], (string) ($p['seccion'] ?? ''));
                $lis .= '<li><strong>' . $e($secTxt) . ':</strong> ' . $e((string) ($p['texto'] ?? ''))
                    . ' <span style="color:' . $stC['fg'] . ';font-weight:bold;">[' . $e($stTxt) . ']</span></li>';
            }
            $secD .= '<ul style="font-size:8.2pt;color:' . self::TEXTO . ';">' . $lis . '</ul>';
        } else {
            $secD .= '<p style="font-size:8.3pt;color:#166534;">' . $e((string) $lbl['pendientes_ninguno']) . '</p>';
        }
        $push($secD . $spacer, count($pend) <= 6, 20.0);

        // 8) Verificaciones numericas opcionales (Modulo 3).
        $verifs = array_values(array_filter(array_map('strval', (array) ($spec['verificaciones'] ?? []))));
        if ($verifs) {
            $vHtml = '<table width="100%" cellpadding="6" cellspacing="0"><tr>'
                . '<td style="background-color:' . self::ZEBRA . ';border-left:3px solid ' . self::CYAN
                . ';border-top:0.5px solid ' . self::BORDE_SUAVE . ';border-right:0.5px solid ' . self::BORDE_SUAVE
                . ';border-bottom:0.5px solid ' . self::BORDE_SUAVE . ';font-size:8pt;color:' . self::TEXTO . ';">'
                . '<strong style="color:' . self::NAVY . ';">' . $e((string) $lbl['sec_verificaciones']) . '</strong>'
                . '<ul>' . implode('', array_map(static fn($v) => '<li>' . $e($v) . '</li>', $verifs)) . '</ul>'
                . '</td></tr></table>' . $spacer;
            $push($vHtml, count($verifs) <= 8);
        }

        // 9) Pie documental: adjuntos considerados y fuentes web (la nota de gobernanza va en el pie de cada pagina).
        $pieLineas = [];
        if (!empty($spec['adjuntos_texto'])) {
            $pieLineas[] = $e((string) $spec['adjuntos_texto']);
        }
        $fuentes = (array) ($spec['fuentes_web'] ?? []);
        if ($fuentes) {
            $fTxt = implode(', ', array_map(static function($f) use ($e): string {
                $t = trim((string) ($f['title'] ?? ''));
                $u = trim((string) ($f['uri'] ?? ''));
                return $e($t !== '' ? ($t . ($u !== '' ? ' (' . $u . ')' : '')) : $u);
            }, $fuentes));
            $pieLineas[] = '<strong>' . $e((string) $lbl['fuentes_web']) . '</strong> ' . $fTxt;
        }
        if ($pieLineas) {
            $push('<table width="100%" cellpadding="6" cellspacing="0"><tr>'
                . '<td style="background-color:' . self::ZEBRA . ';border-top:1px solid ' . self::BORDE
                . ';font-size:7.6pt;color:' . self::GRIS . ';">'
                . implode('<br>', $pieLineas) . '</td></tr></table>', true);
        }

        return $bloques;
    }

    /**
     * HTML completo del informe uniendo todos los bloques. Funcion pura.
     *
     * @param array $spec
     * @param string $logo
     * @return string
     */
    public static function build_html(array $spec, string $logo = ''): string {
        return implode("\n", array_column(self::build_blocks($spec, $logo), 'html'));
    }

    /**
     * Banda visual de seccion (a, b, c, d) armonizada con la identidad Interfases. Funcion pura.
     *
     * @param string $prefijo
     * @param string $titulo
     * @return string
     */
    private static function section_heading(string $prefijo, string $titulo): string {
        $e = static fn(string $t): string => htmlspecialchars(self::pdf_text($t), ENT_QUOTES, 'UTF-8');
        return '<table width="100%" cellpadding="4" cellspacing="0"><tr>'
            . '<td style="background-color:' . self::CYAN_SUAVE . ';border-left:3.5px solid ' . self::CYAN
            . ';border-bottom:1px solid ' . self::CYAN . ';color:' . self::NAVY . ';font-size:9.5pt;font-weight:bold;">'
            . ($prefijo !== '' ? '<span style="color:' . self::CYAN_OSCURO . ';">' . $e($prefijo) . '</span>  ' : '')
            . $e($titulo)
            . '</td></tr></table>';
    }

    /**
     * Escribe el archivo PDF en disco con logo de Interfases (cabecera hero en pagina 1 y encabezado corrido desde pagina 2),
     * pie de pagina «Pagina X de Y» y paginacion inteligente TCPDF. Funcion pura.
     *
     * @param string $ruta Ruta de salida .pdf.
     * @param array $spec Datos del informe.
     * @param string $logo Ruta al PNG del logo de la consultora (pix/logo_interfases.png).
     * @return bool
     */
    public static function build_pdf(string $ruta, array $spec, string $logo = ''): bool {
        if (!class_exists('\TCPDF')) {
            if (!defined('K_PATH_CACHE')) {
                define('K_PATH_CACHE', rtrim(sys_get_temp_dir(), '/\\') . '/');
            }
            $tcpdf = __DIR__ . '/../../../lib/tcpdf/tcpdf.php';
            if (is_file($tcpdf)) {
                require_once($tcpdf);
            }
        }
        if (!class_exists('\TCPDF')) {
            if (class_exists('\local_chatbot\recurso_export')) {
                return recurso_export::build_pdf_minimal(
                    $ruta,
                    (string) ($spec['titulo'] ?? 'Informe de validación de la ficha'),
                    (string) ($spec['curso'] ?? ''),
                    self::build_html($spec, '')
                );
            }
            return false;
        }

        $metaHdr = [
            'titulo' => self::pdf_text((string) ($spec['titulo'] ?? 'Informe de validación de la ficha')),
            'modulo' => self::pdf_text((string) ($spec['modulo'] ?? '')),
            'pie' => self::pdf_text((string) ($spec['nota_pie'] ?? 'Informe de validación técnica · Interfases Consultores')),
            'logo' => ($logo !== '' && is_readable($logo) && extension_loaded('gd')) ? $logo : '',
        ];

        $pdf = new class('P', 'mm', 'LETTER', true, 'UTF-8', false, $metaHdr) extends \TCPDF {
            /** @var array */
            private $infMeta;

            public function __construct($orientation, $unit, $format, $unicode, $encoding, $diskcache, array $meta) {
                parent::__construct($orientation, $unit, $format, $unicode, $encoding, $diskcache);
                $this->tcpdflink = false;
                $this->infMeta = $meta;
            }

            public function Header(): void {
                // La pagina 1 muestra la cabecera hero completa con logo; desde pagina 2 va encabezado corrido con logo.
                if ($this->getPage() < 2) {
                    return;
                }
                $l = $this->original_lMargin;
                $r = $this->original_rMargin;
                $w = $this->w - $l - $r;
                $y = 6.5;
                $logo = (string) ($this->infMeta['logo'] ?? '');
                $wTexto = $logo !== '' ? ($w - 28) : $w;
                $this->SetXY($l, $y);
                $this->SetFont('freesans', 'B', 8);
                $this->SetTextColor(27, 43, 94);
                $this->Cell($wTexto, 4.2, (string) $this->infMeta['titulo'], 0, 1, 'L', false, '', 1);
                if ($this->infMeta['modulo'] !== '') {
                    $this->SetX($l);
                    $this->SetFont('freesans', '', 7.2);
                    $this->SetTextColor(100, 116, 139);
                    $this->Cell($wTexto, 3.6, (string) $this->infMeta['modulo'], 0, 0, 'L', false, '', 1);
                }
                if ($logo !== '') {
                    $this->Image($logo, $this->w - $r - 24, $y - 0.5, 24, 0, 'PNG', '', '', true, 300, '', false, false, 0, true);
                }
                $lineY = $y + 8.6;
                $this->SetLineStyle(['width' => 0.35, 'color' => [203, 213, 225]]);
                $this->Line($l, $lineY, $this->w - $r, $lineY);
                $this->SetLineStyle(['width' => 0.8, 'color' => [30, 167, 225]]);
                $this->Line($l, $lineY, $l + 24, $lineY);
            }

            public function Footer(): void {
                $l = $this->original_lMargin;
                $r = $this->original_rMargin;
                $w = $this->w - $l - $r;
                $y = $this->h - 11.5;
                $this->SetLineStyle(['width' => 0.35, 'color' => [203, 213, 225]]);
                $this->Line($l, $y, $this->w - $r, $y);
                $this->SetLineStyle(['width' => 0.8, 'color' => [30, 167, 225]]);
                $this->Line($l, $y, $l + 20, $y);
                $this->SetXY($l, $y + 1.4);
                $this->SetFont('freesans', '', 7.2);
                $this->SetTextColor(100, 116, 139);
                $this->Cell($w - 28, 4.5, (string) $this->infMeta['pie'], 0, 0, 'L', false, '', 1);
                $this->SetFont('freesans', 'B', 7.8);
                $this->SetTextColor(27, 43, 94);
                $pag = 'Página ' . $this->getAliasNumPage() . ' de ' . $this->getAliasNbPages();
                $this->Cell(28, 4.5, $pag, 0, 0, 'R');
            }
        };

        $pdf->SetCreator('Asistente IA · Interfases Consultores');
        $pdf->SetAuthor('Interfases Consultores');
        $pdf->SetTitle((string) ($spec['titulo'] ?? 'Informe de validación de la ficha'));
        $pdf->setPrintHeader(true);
        $pdf->setPrintFooter(true);
        $pdf->setHeaderMargin(6);
        $pdf->setFooterMargin(12);
        $pdf->SetMargins(13, 18, 13);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->setCellHeightRatio(1.34);
        $pdf->setHtmlVSpace([
            'p' => [0 => ['h' => 1.0, 'n' => 1], 1 => ['h' => 1.4, 'n' => 1]],
            'ul' => [0 => ['h' => 0.8, 'n' => 1], 1 => ['h' => 1.6, 'n' => 1]],
            'li' => [0 => ['h' => 0.4, 'n' => 1], 1 => ['h' => 0.8, 'n' => 1]],
        ]);
        $pdf->SetFont('freesans', '', 8.8);
        $pdf->SetTextColor(30, 41, 59);
        $pdf->AddPage();
        // En la pagina 1 empezamos un poco mas arriba porque el encabezado corrido solo aparece desde la pagina 2.
        $pdf->SetY(11);

        $bloques = self::build_blocks($spec, $logo);
        $topMin = $pdf->getMargins()['top'] + 10.0;
        foreach ($bloques as $bloque) {
            $bHtml = (string) $bloque['html'];
            if (trim($bHtml) === '') {
                continue;
            }
            if (!empty($bloque['atomic'])) {
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
                if ($minMm > 0) {
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
     * Etiquetas localizadas comunes para las tres fichas (Moodle web).
     *
     * @param string $prefijo 'exante'|'impl'|'exp'
     * @return array
     */
    public static function moodle_labels(string $prefijo): array {
        $esExante = ($prefijo === 'exante');
        $esExp = ($prefijo === 'exp');
        return [
            'item' => get_string('exante_report_item', 'local_chatbot'),
            'eval' => get_string('exante_report_eval', 'local_chatbot'),
            'obs' => get_string('exante_report_obs', 'local_chatbot'),
            'critico' => get_string('autocheck_critical', 'local_chatbot'),
            'calculado' => get_string('impl_report_calculated', 'local_chatbot'),
            'como_mejorar' => get_string('exante_report_howto', 'local_chatbot'),
            'forma_texto' => get_string($esExante ? 'exante_report_form' : ($esExp ? 'exp_report_form' : 'impl_report_form'), 'local_chatbot'),
            'estado_cumple' => get_string('exante_status_cumple', 'local_chatbot'),
            'estado_parcial' => get_string('exante_status_parcial', 'local_chatbot'),
            'estado_no_cumple' => get_string('exante_status_no_cumple', 'local_chatbot'),
            'estado_none' => get_string('exante_status_none', 'local_chatbot'),
            'estado_none_help' => get_string('exante_status_none_help', 'local_chatbot'),
            'sec_checklist' => get_string($esExante ? 'exante_report_checklist' : 'impl_report_sections', 'local_chatbot'),
            'sec_condicion' => get_string($esExante ? 'exante_report_condition' : 'impl_report_decision', 'local_chatbot'),
            'sec_tiempo' => get_string('impl_report_time', 'local_chatbot'),
            'sec_sugerencias' => get_string('exante_report_suggest', 'local_chatbot'),
            'sec_debilidades' => get_string('exante_report_weak', 'local_chatbot'),
            'sec_propuestas' => get_string($esExante ? 'exante_report_proposals' : 'impl_report_suggest', 'local_chatbot'),
            'sec_pendientes' => get_string('exante_report_unresolved', 'local_chatbot'),
            'pendientes_ayuda' => get_string($esExante ? 'exante_report_unresolved_help' : ($esExp ? 'exp_report_unresolved_help' : 'impl_report_unresolved_help'), 'local_chatbot'),
            'pendientes_ninguno' => get_string('exante_report_unresolved_none', 'local_chatbot'),
            'debe_corregir' => get_string('exante_report_mustfix', 'local_chatbot'),
            'cita_label' => get_string('exante_report_quote', 'local_chatbot'),
            'sec_verificaciones' => get_string($esExp ? 'exp_report_checks' : 'impl_report_checks', 'local_chatbot'),
            'fuentes_web' => get_string('exante_report_websources', 'local_chatbot'),
            'ninguno' => get_string('impl_report_none', 'local_chatbot'),
            'seccion_n' => get_string('exante_report_section', 'local_chatbot', '%s'),
        ];
    }
}
