<?php
/**
 * Upgrade steps for local_chatbot.
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade steps for local_chatbot.
 *
 * @param int $oldversion the version the site is upgrading from.
 * @return bool always true.
 */
function xmldb_local_chatbot_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026082210) {
        // New table backing the per-document knowledge base (replaces the
        // old single append-only knowledge_base.txt file).
        $table = new xmldb_table('local_chatbot_kb_documents');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('filename', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('content', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('sizebytes', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026082210, 'local', 'chatbot');
    }

    if ($oldversion < 2026082400) {
        // A file attached to a chat message used to be sent to the AI once
        // and then discarded. These two columns let ajax.php re-attach the
        // stored file when it rebuilds history for later turns.
        $table = new xmldb_table('local_chatbot_messages');

        $field = new xmldb_field('attachment_filename', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'is_bot');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('attachment_mimetype', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'attachment_filename');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026082400, 'local', 'chatbot');
    }

    if ($oldversion < 2026090900) {
        // Reenfoque completo del plugin hacia el Modulo 1 (Producto 4): el
        // asistente pasa a tener tres modos funcionales (tutor, coach,
        // evaluador) y el plugin pasa a sostener el diagnostico de brechas,
        // el PDP y el proyecto integrador. Lo que no aparece en el documento
        // de arquitectura se retira aqui.

        // 1. Retiro del checklist de cumplimiento documental: era un proceso
        // administrativo distinto (Inscripcion/Asistencia/Certificacion) que
        // el Modulo 1 no contempla.
        foreach (['local_chatbot_checklist_submission', 'local_chatbot_checklist_stage'] as $oldtablename) {
            $oldtable = new xmldb_table($oldtablename);
            if ($dbman->table_exists($oldtable)) {
                $dbman->drop_table($oldtable);
            }
        }

        // 2. Retiro de las alertas de sentimiento: etiquetaban a una persona
        // como "Frustracion/Desercion detectada", justo el tipo de
        // clasificacion estigmatizante y de diagnostico personal que las
        // reglas de gobernanza del Modulo 1 prohiben explicitamente.
        $oldtable = new xmldb_table('local_chatbot_alerts');
        if ($dbman->table_exists($oldtable)) {
            $dbman->drop_table($oldtable);
        }

        // 3. El historial de conversacion ahora registra en que modo ocurrio.
        $table = new xmldb_table('local_chatbot_messages');
        $field = new xmldb_field('mode', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'tutor', 'is_bot');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // 4. Tablas nuevas del Modulo 1. Se crean desde la definicion de
        // install.xml para no duplicar el esquema en dos lugares.
        $newtables = [
            'local_chatbot_profile',
            'local_chatbot_dimension',
            'local_chatbot_diag_item',
            'local_chatbot_diag_response',
            'local_chatbot_pdp',
            'local_chatbot_pdp_goal',
            'local_chatbot_pdp_history',
            'local_chatbot_criterion',
            'local_chatbot_project',
            'local_chatbot_project_fb',
            'local_chatbot_interaction',
            'local_chatbot_prompt',
        ];
        foreach ($newtables as $newtablename) {
            $newtable = new xmldb_table($newtablename);
            if (!$dbman->table_exists($newtable)) {
                $dbman->install_one_table_from_xmldb_file(
                    __DIR__ . '/install.xml', $newtablename);
            }
        }

        // 5. Instrumento base: dimensiones, items, rubrica y prompts por modo.
        \local_chatbot\seeder::seed_defaults();

        upgrade_plugin_savepoint(true, 2026090900, 'local', 'chatbot');
    }

    if ($oldversion < 2026090901) {
        // Cierre de brechas detectadas al cruzar la implementacion contra el
        // documento de arquitectura: faltaba el componente 5 completo y el
        // versionado de las bases documentales que exige la gobernanza.

        // Componente 5: seguimiento y cierre.
        $table = new xmldb_table('local_chatbot_closure');
        if (!$dbman->table_exists($table)) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_chatbot_closure');
        }

        // Versionado de los recursos internos del modulo.
        $table = new xmldb_table('local_chatbot_kb_documents');

        $field = new xmldb_field('version', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1', 'sizebytes');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'version');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $index = new xmldb_index('active', XMLDB_INDEX_NOTUNIQUE, ['active']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026090901, 'local', 'chatbot');
    }

    if ($oldversion < 2026090903) {
        // RF-GRD-02: toda activacion de guardrail debe quedar registrada con el
        // identificador de la regla. Sin esta columna no se puede calibrar los
        // falsos positivos que pide RF-GRD-03.
        $table = new xmldb_table('local_chatbot_interaction');
        $field = new xmldb_field('guardrail', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'useraccepted');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026090903, 'local', 'chatbot');
    }

    if ($oldversion < 2026090905) {
        // Telemetria de hardware, consumo de tokens, latencia, modelo y pseudoid (ERTF v3.0).
        $table = new xmldb_table('local_chatbot_interaction');

        $field = new xmldb_field('pseudoid', XMLDB_TYPE_CHAR, '30', null, null, null, null, 'guardrail');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('model', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'pseudoid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('input_tokens', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'model');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('output_tokens', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'input_tokens');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('latency_ms', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'output_tokens');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'ok', 'latency_ms');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026090905, 'local', 'chatbot');
    }


    if ($oldversion < 2026091001) {
        // Items situacionales y puntaje compuesto de orientacion (RU-CALC-001).
        $table = new xmldb_table('local_chatbot_diag_item');

        $field = new xmldb_field('kind', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'declarativa', 'statement');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('options', XMLDB_TYPE_TEXT, null, null, null, null, null, 'kind');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('correctoption', XMLDB_TYPE_INTEGER, '4', null, null, null, null, 'options');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('local_chatbot_diag_response');

        $field = new xmldb_field('kind', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'declarativa', 'score');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('chosenoption', XMLDB_TYPE_INTEGER, '4', null, null, null, null, 'kind');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('iscorrect', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '-1', 'chosenoption');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('scalemax', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '5', 'iscorrect');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);

            // Las respuestas anteriores se dieron en escala 1 a 4. Se reescalan
            // linealmente a 1 a 5 para que el promedio por dimension siga
            // significando lo mismo frente a los cortes de nivel, que tambien
            // son proporcionales. El redondeo a entero pierde los valores
            // intermedios exactos; por eso, de aqui en adelante, cada respuesta
            // deja registrado con que tope de escala se dio.
            $legacy = $DB->get_records_select('local_chatbot_diag_response', 'score > 0', null, '', 'id, score');
            foreach ($legacy as $row) {
                $rescaled = (int) round(1 + (((int) $row->score - 1) * 4 / 3));
                $DB->set_field('local_chatbot_diag_response', 'score', min(5, max(1, $rescaled)), ['id' => $row->id]);
            }
            if (!empty($legacy)) {
                upgrade_set_timeout(120);
                mtrace('local_chatbot: ' . count($legacy) . ' respuestas del diagnostico reescaladas de 1-4 a 1-5.');
            }
        }

        // Los cinco vinculos de cada prioridad del PDP (RU-COA-003).
        $table = new xmldb_table('local_chatbot_pdp_goal');

        $field = new xmldb_field('unidad', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'evidencia');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('recurso', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'unidad');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('microcapsula', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'recurso');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('actividad', XMLDB_TYPE_TEXT, null, null, null, null, null, 'microcapsula');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Prompt de la fase de diagnostico y items situacionales base.
        \local_chatbot\seeder::seed_defaults();

        upgrade_plugin_savepoint(true, 2026091001, 'local', 'chatbot');
    }


    if ($oldversion < 2026091101) {
        // Matriz RU/RS/EARS v4 (Doc 3 y Doc 5).

        // Tiempo disponible en el perfil (RU-COA-001, RU-COA-PRIOR-002).
        $table = new xmldb_table('local_chatbot_profile');
        $field = new xmldb_field('tiempo', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'necesidad');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Criterios formativos por dimension (RU-ADAPT-004).
        $table = new xmldb_table('local_chatbot_dimension');
        $field = new xmldb_field('criterios', XMLDB_TYPE_TEXT, null, null, null, null, null, 'description');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Plazo de cada meta (RU-COA-PDP-FLOW-001). Las metas existentes quedan
        // sin plazo: no se inventa una fecha por la persona. Un plan que ya
        // estaba validado sigue activo; uno nuevo no se activa sin plazos.
        $table = new xmldb_table('local_chatbot_pdp_goal');
        $field = new xmldb_field('plazo', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'actividad');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Matriz de desarrollo del Coach y motor de apoyos adaptativos.
        foreach (['local_chatbot_coach_report', 'local_chatbot_adapt', 'local_chatbot_adapt_review'] as $name) {
            if (!$dbman->table_exists($name)) {
                $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', $name);
            }
        }

        // Criterios base de las dimensiones y lo que falte del instrumento.
        \local_chatbot\seeder::seed_defaults();

        // Prompts alineados a v4: solo los que ninguna persona edito. Los
        // editados se respetan; las reglas que no pueden depender del prompt
        // (derivacion, autoria, alcance) ya se inyectan por codigo.
        $updated = \local_chatbot\seeder::refresh_system_prompts();
        if (!empty($updated)) {
            mtrace('local_chatbot: prompts actualizados a v4: ' . implode(', ', $updated) . '.');
        }

        upgrade_plugin_savepoint(true, 2026091101, 'local', 'chatbot');
    }

    if ($oldversion < 2026092103) {
        // Cinco componentes del feedback de evidencias (RU-GEN-003 y RU-ADAPT-007 del SPEC v5).
        // Las revisiones anteriores quedan con estos campos vacios: no se reconstruyen.
        $table = new xmldb_table('local_chatbot_adapt_review');
        $fields = [
            new xmldb_field('strength', XMLDB_TYPE_TEXT, null, null, null, null, null, 'feedbacksynthesis'),
            new xmldb_field('aspecttodeepen', XMLDB_TYPE_TEXT, null, null, null, null, null, 'strength'),
            new xmldb_field('whyitmatters', XMLDB_TYPE_TEXT, null, null, null, null, null, 'aspecttodeepen'),
            new xmldb_field('updateproposal', XMLDB_TYPE_CHAR, '30', null, null, null, null, 'whyitmatters'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026092103, 'local', 'chatbot');
    }

    if ($oldversion < 2026092104) {
        // Prompts operativos de los Anexos E (Tutor) y F (Coach). Solo reemplaza los prompts
        // que nadie edito desde la interfaz, y como version nueva (RU-PRIV-006).
        $updated = \local_chatbot\seeder::refresh_system_prompts();
        if (!empty($updated)) {
            mtrace('local_chatbot: prompts operativos de los Anexos E y F cargados en: ' . implode(', ', $updated) . '.');
        }
        upgrade_plugin_savepoint(true, 2026092104, 'local', 'chatbot');
    }

    if ($oldversion < 2026092105) {
        // Trazabilidad del Anexo G: registro del Tutor en la interaccion (Anexo E §11), catalogo
        // de eventos (§7) y patrones del quiz (§8.3).
        $table = new xmldb_table('local_chatbot_interaction');
        $fields = [
            new xmldb_field('interactioncode', XMLDB_TYPE_CHAR, '40', null, null, null, null, 'promptversion'),
            new xmldb_field('sourceresource', XMLDB_TYPE_CHAR, '60', null, null, null, null, 'interactioncode'),
            new xmldb_field('concepttags', XMLDB_TYPE_TEXT, null, null, null, null, null, 'sourceresource'),
            new xmldb_field('sourcesreferred', XMLDB_TYPE_TEXT, null, null, null, null, null, 'concepttags'),
            new xmldb_field('quizguard', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'sourcesreferred'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        foreach (['local_chatbot_trace', 'local_chatbot_quiz_pattern'] as $name) {
            if (!$dbman->table_exists($name)) {
                $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', $name);
            }
        }
        upgrade_plugin_savepoint(true, 2026092105, 'local', 'chatbot');
    }

    if ($oldversion < 2026092602) {
        // RU-CONS-002: cada aceptacion queda sellada con la version de lo que se
        // declaro. Las anteriores quedan sin version y se respetan como vigentes:
        // invalidar lo que ya aceptaron, sin que nadie cambiara la declaracion,
        // seria un perjuicio sin fundamento.
        $table = new xmldb_table('local_chatbot_profile');
        $field = new xmldb_field('consentversion', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'consenttime');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // RU-PRIV-006: la version del recurso citado queda en la interaccion, para
        // poder reconstruir con que texto se respondio.
        $table = new xmldb_table('local_chatbot_interaction');
        $field = new xmldb_field('sourceversion', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'sourceresource');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('kbversion', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'sourceversion');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // RU-ADAPT-007: registro estructurado de la intervencion del Coach sobre
        // una meta, con el contrato del Anexo F §10.
        $table = new xmldb_table('local_chatbot_adapt_review');
        $field = new xmldb_field('record', XMLDB_TYPE_TEXT, null, null, null, null, null, 'timeconfirmed');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026092602, 'local', 'chatbot');
    }

    if ($oldversion < 2026092803) {

        // La extraccion del PDF pasa a hacerse en una tarea, fuera del request
        // web: un documento largo tarda minutos y el servidor web cortaba antes.
        // Mientras esta en cola el documento existe pero no esta activo, de modo
        // que no alimenta al asistente hasta que la extraccion termina bien.
        $table = new xmldb_table('local_chatbot_kb_documents');

        $field = new xmldb_field('status', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1', 'timecreated');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('statusmsg', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'status');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026092803, 'local', 'chatbot');
    }

    if ($oldversion < 2026100700) {

        // Valoracion de las respuestas (indicadores de calidad, Anexo G 11.4): solo el voto y
        // un motivo codificado, nunca texto de la persona.
        $table = new xmldb_table('local_chatbot_feedback');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('interactionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('vote', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, null);
        $table->add_field('reason', XMLDB_TYPE_CHAR, '30', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('interactionid_userid', XMLDB_INDEX_UNIQUE, ['interactionid', 'userid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026100700, 'local', 'chatbot');
    }

    if ($oldversion < 2026100800) {

        // Conversacion por meta: cada mensaje puede pertenecer al hilo de una meta del PDP.
        $table = new xmldb_table('local_chatbot_messages');
        $field = new xmldb_field('goalid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'timecreated');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = new xmldb_index('goalid', XMLDB_INDEX_NOTUNIQUE, ['goalid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Tildes en los nombres del instrumento y la rubrica por defecto, que se cargaron sin
        // ellas. Solo se corrige el texto exacto sembrado: lo que alguien haya editado no se toca.
        foreach (\local_chatbot\seeder::accent_fixes() as $tabla => $cambios) {
            foreach ($cambios as $antes => $despues) {
                $DB->set_field($tabla, 'name', $despues, ['name' => $antes]);
            }
        }

        upgrade_plugin_savepoint(true, 2026100800, 'local', 'chatbot');
    }

    if ($oldversion < 2026100900) {

        // Ajustes del cliente del 08-10-2026: la persona no conversa con el Coach, asi que la
        // descripcion de la actividad del PDP en el curso deja de nombrarlo. Se corrige solo la frase
        // sembrada por cli/preparar_curso.php; lo que alguien haya editado a mano no se toca.
        $cmids = $DB->get_fieldset_select('course_modules', 'instance',
            "idnumber = 'M1-PASO-PDP' AND module = (SELECT id FROM {modules} WHERE name = 'url')");
        foreach ($cmids as $urlid) {
            $intro = (string) $DB->get_field('url', 'intro', ['id' => $urlid]);
            $nuevo = str_replace(['El Coach te propone y tú decides.', 'The Coach proposes and you decide.'],
                ['El asistente te propone y tú decides.', 'The assistant proposes and you decide.'], $intro);
            if ($nuevo !== $intro) {
                $DB->set_field('url', 'intro', $nuevo, ['id' => $urlid]);
            }
        }

        upgrade_plugin_savepoint(true, 2026100900, 'local', 'chatbot');
    }

    if ($oldversion < 2026101400) {

        // Versiones del plan: las metas que vienen del diagnostico se agrupan por version, para
        // reemplazarlas cuando cambia el diagnostico y poder volver a una version anterior.
        $table = new xmldb_table('local_chatbot_pdp');
        foreach (['ronda', 'rondamax'] as $nombre) {
            $field = new xmldb_field($nombre, XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        $table = new xmldb_table('local_chatbot_pdp_goal');
        $field = new xmldb_field('ronda', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'timemodified');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026101400, 'local', 'chatbot');
    }

    if ($oldversion < 2026101600) {

        // Perfilamiento segun el acta de la reunion con Interfases del 13-10-2026: preguntas nuevas
        // y alternativas distintas. Las respuestas anteriores pasan a la alternativa vigente mas proxima.
        $table = new xmldb_table('local_chatbot_profile');
        $campos = [
            ['rolotro', XMLDB_TYPE_CHAR, '255'], ['programa', XMLDB_TYPE_CHAR, '30'],
            ['programaotro', XMLDB_TYPE_CHAR, '255'], ['familiaridad', XMLDB_TYPE_CHAR, '20'],
            ['aplicacion', XMLDB_TYPE_TEXT, null], ['fortalecer', XMLDB_TYPE_CHAR, '255'],
            ['fortalecerotro', XMLDB_TYPE_CHAR, '255'],
        ];
        foreach ($campos as [$nombre, $tipo, $largo]) {
            $field = new xmldb_field($nombre, $tipo, $largo, null, null, null, null);
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        $perfiles = $DB->get_recordset('local_chatbot_profile', null, '', 'id, rol, experiencia, modalidad, tiempo');
        foreach ($perfiles as $perfil) {
            $cambios = [];
            foreach (['rol', 'experiencia', 'modalidad', 'tiempo'] as $campo) {
                $nuevo = \local_chatbot\profile::legacy_map($campo, (string) $perfil->$campo);
                if ($nuevo !== (string) $perfil->$campo) {
                    $cambios[$campo] = $nuevo;
                }
            }
            if ($cambios) {
                $DB->update_record('local_chatbot_profile', (object) (['id' => $perfil->id] + $cambios));
            }
        }
        $perfiles->close();

        upgrade_plugin_savepoint(true, 2026101600, 'local', 'chatbot');
    }

    return true;
}
