<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Perfil de la persona facilitadora y aceptacion informada de las condiciones
 * de uso de IA (componente 0 del Modulo 1: bienvenida y perfilamiento).
 *
 * Recoge solo los antecedentes de contexto que el documento enumera —rol,
 * contexto, experiencia, modalidad, sector y necesidad declarada— porque la
 * regla de minimizacion obliga a no pedir mas de lo necesario para la
 * experiencia. Nada de esto es dato sensible ni se usa para clasificar a la
 * persona: sirve para que el asistente adapte sus ejemplos.
 *
 * El consentimiento es condicion previa: mientras no este aceptado, el
 * asistente no debe operar.
 */
class profile {

    /**
     * Los cuatro antecedentes de tratamiento de datos que la persona tiene
     * derecho a conocer antes de aceptar (RU-CONS-004): proveedor tecnologico,
     * region de procesamiento, conservacion y canal para ejercer derechos.
     *
     * Se leen de la configuracion del sitio en vez de escribirse en la cadena
     * de idioma porque cambian con el contrato y con el proveedor elegido, y
     * una declaracion de consentimiento que quedo desactualizada no es una
     * imprecision de redaccion: es un consentimiento que dejo de ser informado.
     *
     * Lo que el sitio todavia no declaro no se inventa ni se omite en silencio:
     * se devuelve como pendiente, para que la pantalla lo muestre como tal y la
     * puesta en produccion no pase por alto que falta.
     *
     * @return array Filas con label (string), value (string) y declared (bool).
     */
    public static function data_disclosure(): array {
        $pending = get_string('disclosure_pending', 'local_chatbot');

        $retention = (int) get_config('local_chatbot', 'message_retention_months');
        $retentionvalue = $retention > 0
            ? get_string('disclosure_retention_value', 'local_chatbot', $retention)
            : '';

        $fields = [
            'provider' => [
                'label' => get_string('disclosure_provider', 'local_chatbot'),
                'value' => trim((string) get_config('local_chatbot', 'provider_name')),
            ],
            'region' => [
                'label' => get_string('disclosure_region', 'local_chatbot'),
                'value' => trim((string) get_config('local_chatbot', 'data_region')),
            ],
            'retention' => [
                'label' => get_string('disclosure_retention', 'local_chatbot'),
                'value' => $retentionvalue,
            ],
            'rights' => [
                'label' => get_string('disclosure_rights', 'local_chatbot'),
                'value' => trim((string) get_config('local_chatbot', 'rights_contact')),
            ],
        ];

        foreach ($fields as $key => $field) {
            $fields[$key]['declared'] = ($field['value'] !== '');
            if ($field['value'] === '') {
                $fields[$key]['value'] = $pending;
            }
        }

        return $fields;
    }

    /**
     * Version de la declaracion vigente: huella de lo que hoy se le muestra a la persona.
     *
     * Un consentimiento vale para lo que se declaro cuando se dio. Si despues
     * cambia el proveedor, la region, la conservacion o el canal de derechos, lo
     * aceptado ya no corresponde a lo que ocurre, y hay que volver a preguntar
     * (RU-CONS-002). La huella se calcula sobre los valores declarados, no sobre
     * las etiquetas, para que un cambio de redaccion no invalide consentimientos.
     *
     * @return string Huella corta y estable, o cadena vacia si falta declarar algo.
     */
    public static function disclosure_version(): string {
        $valores = [];
        foreach (self::data_disclosure() as $clave => $campo) {
            if (!$campo['declared']) {
                return '';
            }
            $valores[] = $clave . '=' . $campo['value'];
        }
        return substr(sha1(implode('|', $valores)), 0, 12);
    }

    /**
     * @return bool Si el sitio declaro los cuatro antecedentes de RU-CONS-004.
     */
    public static function disclosure_is_complete(): bool {
        foreach (self::data_disclosure() as $field) {
            if (!$field['declared']) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param int $userid
     * @param int $courseid
     * @return \stdClass|false
     */
    public static function get(int $userid, int $courseid) {
        global $DB;
        return $DB->get_record('local_chatbot_profile', ['userid' => $userid, 'courseid' => $courseid]);
    }

    /**
     * @param int $userid
     * @param int $courseid
     * @return bool Si la persona acepto las condiciones de uso de IA.
     */
    public static function has_consent(int $userid, int $courseid = 0): bool {
        global $DB;
        // Sin excepciones por rol. Antes las cuentas de administracion se daban
        // por consentidas, y eso contradice RU-CONS-002: sus consultas tambien
        // viajan al proveedor de IA, asi que tambien deben aceptar. De paso, su
        // recorrido dejaba de partir de cero y ensuciaba las pruebas.
        $record = self::get($userid, $courseid);
        if ($record && (int) $record->consentaccepted === 1) {
            return self::consent_is_current($record);
        }
        // Si no existe perfil en este curso puntual, verificar si acepto en el sitio o en cualquier curso.
        $otros = $DB->get_records_select('local_chatbot_profile',
            'userid = :uid AND consentaccepted = 1', ['uid' => $userid], 'id ASC', '*', 0, 5);
        foreach ($otros as $otro) {
            if (self::consent_is_current($otro)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Si un consentimiento aceptado sigue correspondiendo a lo que hoy se declara.
     *
     * Los consentimientos dados antes de que existiera el versionado no llevan
     * version: se respetan como vigentes. Invalidar en masa lo que las personas
     * ya aceptaron, sin que nadie cambiara la declaracion, seria un perjuicio
     * sin fundamento. Desde ahora cada aceptacion queda sellada con su version.
     *
     * @param \stdClass $record Fila de perfil.
     * @return bool
     */
    public static function consent_is_current(\stdClass $record): bool {
        $sellada = trim((string) ($record->consentversion ?? ''));
        if ($sellada === '') {
            return true;
        }
        $vigente = self::disclosure_version();
        // Si el sitio dejo de declarar algo, no se invalida lo aceptado: lo que
        // falta se muestra como pendiente y bloquea la puesta en produccion por
        // otra via (RU-CONS-004).
        return $vigente === '' || $vigente === $sellada;
    }

    /**
     * Por que el consentimiento no esta vigente, para decirselo a la persona.
     *
     * @param int $userid
     * @param int $courseid
     * @return string 'nunca' si no ha aceptado, 'cambio' si la declaracion cambio, '' si esta vigente.
     */
    public static function consent_status(int $userid, int $courseid = 0): string {
        if (self::has_consent($userid, $courseid)) {
            return '';
        }
        $record = self::get($userid, $courseid);
        if ($record && (int) $record->consentaccepted === 1) {
            return 'cambio';
        }
        return 'nunca';
    }

    /**
     * Crea o actualiza el perfil.
     *
     * @param int $userid
     * @param int $courseid
     * @param array $data Claves: rol, contexto, experiencia, modalidad, sector, necesidad, consent.
     * @return int id del registro.
     */
    public static function save(int $userid, int $courseid, array $data): int {
        global $DB;

        $now = time();
        $record = self::get($userid, $courseid);
        $isnew = ($record === false);

        if ($isnew) {
            $record = new \stdClass();
            $record->userid = $userid;
            $record->courseid = $courseid;
            $record->timecreated = $now;
            $record->consentaccepted = 0;
            $record->consenttime = 0;
        }

        // El tiempo disponible es una opcion cerrada que despues se traduce con
        // get_string('tiempo_...'): un valor fuera de la lista se descarta aqui
        // en vez de romper la pantalla o el contexto del Coach mas adelante.
        if (isset($data['tiempo']) && !in_array($data['tiempo'], self::get_options()['tiempo'], true)) {
            unset($data['tiempo']);
        }

        // Una alternativa cerrada fuera de su lista no se guarda (se traduce despues con get_string).
        $opciones = self::get_options();
        foreach (['rol', 'programa', 'modalidad', 'experiencia', 'familiaridad'] as $cerrada) {
            if (isset($data[$cerrada]) && !in_array($data[$cerrada], $opciones[$cerrada], true)) {
                unset($data[$cerrada]);
            }
        }
        if (isset($data['fortalecer'])) {
            $data['fortalecer'] = implode(',', array_slice(array_values(array_intersect(
                (array) $data['fortalecer'], $opciones['fortalecer'])), 0, self::MAX_FORTALECER));
        }

        foreach (['rol', 'rolotro', 'programa', 'programaotro', 'contexto', 'experiencia', 'modalidad', 'sector',
                'familiaridad', 'aplicacion', 'fortalecer', 'fortalecerotro', 'necesidad', 'tiempo'] as $field) {
            if (isset($data[$field])) {
                $record->{$field} = $data[$field];
            }
        }

        // El consentimiento queda sellado con la version de lo que se le declaro
        // a la persona: es la evidencia de que fue informada, y de que fue
        // informada de esto y no de otra cosa (RU-CONS-002). Si la institucion
        // cambia la declaracion, ese sello deja de coincidir y se vuelve a pedir.
        if (!empty($data['consent'])
                && ((int) $record->consentaccepted !== 1 || !self::consent_is_current($record))) {
            $record->consentaccepted = 1;
            $record->consenttime = $now;
            $record->consentversion = self::disclosure_version();
        }

        $record->timemodified = $now;

        if ($isnew) {
            $record->id = $DB->insert_record('local_chatbot_profile', $record);
        } else {
            $DB->update_record('local_chatbot_profile', $record);
        }

        return (int) $record->id;
    }

    /**
     * Opciones cerradas de los campos de contexto, para no pedir texto libre
     * donde una lista basta y evitar que la persona escriba datos personales
     * sin necesidad.
     *
     * @return array Mapa campo => lista de claves de opcion.
     */
    public static function get_options(): array {
        // Preguntas y alternativas del acta de la reunion con Interfases del 13-10-2026.
        return [
            'rol' => ['docente_aula', 'instructor_taller', 'facilitador_laboral', 'coordinador', 'otro'],
            'programa' => ['capacitacion', 'oficios', 'tecnico', 'empleabilidad', 'otro'],
            'modalidad' => ['presencial', 'virtual', 'hibrida'],
            'experiencia' => ['menos1', 'entre1y3', 'entre4y7', 'mas7', 'nr'],
            'familiaridad' => ['nada', 'poco', 'mediana', 'bastante', 'nr'],
            'fortalecer' => ['enfoque', 'ucl', 'actividades', 'evaluacion', 'retroalimentacion', 'ia', 'plan', 'otro'],
            // Horas semanales que la persona puede dedicar al modulo. RU-COA-001
            // lo lista entre los datos autorizados del Coach y Doc 3 lo usa como
            // sexto criterio de priorizacion.
            'tiempo' => ['menos1', 'entre1y2', 'entre3y5', 'mas5'],
        ];
    }

    /** @var int Cuantos aspectos a fortalecer se pueden marcar (acta del 13-10-2026). */
    const MAX_FORTALECER = 2;

    /**
     * Equivalencia de una respuesta del perfil anterior al 13-10-2026 con la alternativa
     * vigente. Funcion pura; la usa la migracion de la base.
     *
     * @param string $campo rol, experiencia, modalidad o tiempo.
     * @param string $valor
     * @return string La alternativa vigente, o la misma si ya lo era o no tiene equivalente.
     */
    public static function legacy_map(string $campo, string $valor): string {
        $mapa = [
            'rol' => ['docente' => 'docente_aula', 'relator' => 'docente_aula', 'instructor' => 'instructor_taller',
                'facilitador' => 'facilitador_laboral'],
            // «Entre 3 y 10» y «mas de 10» se acercan al rango nuevo mas proximo.
            'experiencia' => ['entre3y10' => 'entre4y7', 'mas10' => 'mas7'],
            'modalidad' => ['online' => 'virtual', 'mixta' => 'hibrida'],
            'tiempo' => ['entre3y4' => 'entre3y5'],
        ];
        return $mapa[$campo][$valor] ?? $valor;
    }

    /**
     * Revisa las respuestas del perfil antes de guardarlas. Funcion pura.
     *
     * @param array $data Respuestas del formulario.
     * @return string[] Claves de idioma de los errores (vacio si esta todo bien).
     */
    public static function validate(array $data): array {
        $opciones = self::get_options();
        $errores = [];
        foreach (['rol', 'programa', 'modalidad'] as $obligatoria) {
            if (!in_array($data[$obligatoria] ?? '', $opciones[$obligatoria], true)) {
                $errores[] = 'profile_error_' . $obligatoria;
            }
        }
        foreach (['rol' => 'rolotro', 'programa' => 'programaotro'] as $campo => $otro) {
            if (($data[$campo] ?? '') === 'otro' && trim((string) ($data[$otro] ?? '')) === '') {
                $errores[] = 'profile_error_' . $otro;
            }
        }
        $fortalecer = (array) ($data['fortalecer'] ?? []);
        if (count($fortalecer) > self::MAX_FORTALECER) {
            $errores[] = 'profile_error_fortalecer';
        }
        if (in_array('otro', $fortalecer, true) && trim((string) ($data['fortalecerotro'] ?? '')) === '') {
            $errores[] = 'profile_error_fortalecerotro';
        }
        return $errores;
    }

    /**
     * El perfil en palabras, para el contexto del asistente. Funcion pura (salvo get_string).
     *
     * Las respuestas «Otro» llevan lo que la persona especifico; las de perfiles anteriores al
     * 13-10-2026 (contexto institucional y necesidad en texto libre) se conservan.
     *
     * @param \stdClass $profile
     * @return array rol, programa, sector, modalidad, experiencia, familiaridad, aplicacion,
     *               fortalecer (lista) y tiempo; null lo que no se respondio.
     */
    public static function describe(\stdClass $profile): array {
        $etiqueta = static function (string $prefijo, ?string $valor): ?string {
            $valor = (string) $valor;
            return $valor === '' || $valor === 'nr' ? null : get_string($prefijo . $valor, 'local_chatbot');
        };
        $conotro = static function (?string $texto, ?string $otro): ?string {
            return $texto === null ? null : (trim((string) $otro) !== '' ? $texto . ': ' . trim((string) $otro) : $texto);
        };
        $fortalecer = [];
        foreach (array_filter(explode(',', (string) ($profile->fortalecer ?? ''))) as $codigo) {
            $fortalecer[] = $codigo === 'otro' && trim((string) ($profile->fortalecerotro ?? '')) !== ''
                ? trim((string) $profile->fortalecerotro) : get_string('fort_' . $codigo, 'local_chatbot');
        }
        if (empty($fortalecer) && trim((string) ($profile->necesidad ?? '')) !== '') {
            $fortalecer[] = trim((string) $profile->necesidad);
        }
        $programa = $conotro($etiqueta('prog_', $profile->programa ?? null), $profile->programaotro ?? null);
        if ($programa === null && trim((string) ($profile->contexto ?? '')) !== '') {
            $programa = trim((string) $profile->contexto);
        }
        $aplicacion = trim((string) ($profile->aplicacion ?? ''));
        return [
            'rol' => $conotro($etiqueta('rol_', $profile->rol ?? null), ($profile->rol ?? '') === 'otro' ? ($profile->rolotro ?? null) : null),
            'programa' => $programa,
            'sector' => trim((string) ($profile->sector ?? '')) ?: null,
            'modalidad' => $etiqueta('mod_', $profile->modalidad ?? null),
            'experiencia' => $etiqueta('exp_', $profile->experiencia ?? null),
            'familiaridad' => $etiqueta('fam_', $profile->familiaridad ?? null),
            'aplicacion' => ($aplicacion === '' || $aplicacion === 'nr') ? null : $aplicacion,
            'fortalecer' => $fortalecer,
            'tiempo' => $etiqueta('tiempo_', $profile->tiempo ?? null),
        ];
    }
}
