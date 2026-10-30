<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Interruptores de las mejoras de experiencia.
 *
 * Cada mejora visible se puede apagar desde la administracion del plugin sin desplegar nada.
 * Es la forma de retroceder si al cliente no le gusta una: bajar de version el codigo no sirve,
 * porque Moodle se niega a arrancar con la base por delante del codigo.
 *
 * Todas vienen encendidas salvo que se diga lo contrario en DEFAULTS.
 */
class ux {

    /** @var int[] Mejora => valor por defecto (1 encendida, 0 apagada). */
    const DEFAULTS = [
        'screencontext' => 1,   // El asistente sabe en que pantalla esta la persona (v1.11.0).
        'routebar' => 1,        // Barra de ruta con «Estas aqui» en cada pantalla (v1.12.2).
        'pdpguide' => 1,        // Guia del paso dentro del PDP (v1.11.0).
        'goalorder' => 1,       // Metas ordenadas, agrupadas y numeradas (v1.11.1-2).
        'gapchange' => 1,       // Cambio de la brecha por meta y aviso en la guia (v1.11.3).
        'supportfocus' => 1,    // El apoyo no se pierde: ancla, resalte y enlaces (v1.11.4).
        'supporthelp' => 1,     // Ayuda del Tutor y revision de borrador en el apoyo (v1.11.5, v1.12.0).
        'nextaction' => 1,      // «Tu proximo paso» unico y concreto (v1.13.0).
        'coursebar' => 1,       // Ruta y proximo paso dentro del curso de Moodle (v1.13.0).
        'modebyscreen' => 1,    // El widget abre en el modo que corresponde a la pantalla (v1.13.0).
        'resourcechips' => 1,   // Pildoras segun el recurso y aviso durante el quiz (v1.13.0).
        'feedback' => 1,        // Valoracion de cada respuesta (v1.13.0).
        'retry' => 1,           // Reintento ante fallas pasajeras del servicio (v1.13.0).
        'kbcache' => 1,         // Base de conocimiento al inicio del prompt, para la cache del proveedor (v1.13.0).
        'streaming' => 1,       // Respuestas que aparecen mientras se escriben (v1.13.0).
        'goalprogress' => 1,    // «Mi avance» por meta (v1.14.0).
        'reminders' => 1,       // Recordatorios por las notificaciones de Moodle (v1.14.0).
        'draftsave' => 1,       // Borrador de evidencia guardado en el navegador (v1.14.0).
        'goalthreads' => 1,     // Conversacion propia por meta (v1.14.0).
        'tour' => 1,            // Recorrido guiado la primera vez (v1.14.0).
        // Ajustes del cliente del 08-10-2026 («Ajustes al Entorno de Aprendizaje del Modulo 1»).
        'tutoronly' => 1,       // El chat es solo Tutor y no navega; Coach y Evaluador trabajan en las paginas (v1.15.0).
        'backtocourse' => 1,    // «Volver al curso» tambien arriba de cada pantalla (v1.15.0).
        'projectfirst' => 1,    // El proximo paso sugerido pone el proyecto antes que el PDP (v1.15.0).
        'route6' => 1,          // Ruta de 6 etapas: Ruta Formativa Base, PDP opcional despues del proyecto, bloqueo de etapas (v1.16.0).
        'unlockmodules' => 1,   // Al cerrar el Modulo 1 se abren los Modulos 2 y 3 configurados (v1.16.0).
        'clearback' => 1,       // «Regresar al curso» como boton solido de alto contraste, arriba y abajo (v1.16.1).
        'coursebarfull' => 1,   // La ruta del curso con la misma estructura que la de las pantallas (v1.16.4).
        'diagrefresh' => 1,     // Al cambiar el diagnostico se rehacen la matriz de desarrollo y las propuestas sin decidir (v1.16.5).
        'autopdp' => 0,         // El diagnostico arma solo el PDP y lo rehace en versiones con «atras» y «adelante» (v1.16.6). Apagado por defecto: pisa metas aceptadas, a confirmar con el cliente.
        'perfilacta' => 1,      // Pagina de perfil segun el acta del 13-10-2026: titulo, sin «Comienza aqui», boton aceptar y guardar (v1.16.8).
        'diagacta' => 1,        // Autodiagnostico segun el acta del 13-10-2026: texto instructivo y «Tus prioridades» (v1.16.9).
        'perfilpopup' => 1,     // Ficha en ventana emergente: sin «Regresar al curso» y con «Cerrar» (v1.16.10).
        // Pedido de Interfases del 21-10-2026 para el Modulo 2.
        'fichaexante' => 1,     // Modulo 2, Fase 1: ficha de diagnostico ex-ante validada con el checklist (v1.17.0).
        'pdfvisor' => 1,        // Recursos PDF incrustados: visor sin panel de miniaturas y ajustado al ancho (v1.19.17).
        'disenofase2' => 1,     // Modulo 2, Fases 2 y 3: hojas de trabajo de las etapas 1 a 8 del diseno (v1.18.0, v1.19.0).
        'navestudiante' => 1,   // Sin la franja Curso / Participantes / Calificaciones / ... para quien cursa (v1.20.2).
        'iaapoyo' => 1,         // Modulo 2: «No aprobado» solo por motivo grave citado; en la Fase 3, un 4 sin evidencia es 3 (v1.20.6).
        'avisosficha' => 1,     // Modulo 2: avisos con reglas fijas al validar la ficha (v1.20.6; sin pausa desde v1.20.8).
        'descargafinal' => 1,   // Modulo 2, Fase 3: documento final descargable siempre, con puntaje y banda impresos (v1.20.8).
        'bienvenidam2' => 1,    // Modulo 2: bienvenida textual del Prompt Maestro al comienzo de la ficha (v1.20.9).
    ];

    /**
     * Si una mejora esta encendida.
     *
     * @param string $feature Clave de DEFAULTS.
     * @return bool
     */
    public static function on(string $feature): bool {
        if (!array_key_exists($feature, self::DEFAULTS)) {
            return false;
        }
        return self::resolve(get_config('local_chatbot', 'ux_' . $feature), self::DEFAULTS[$feature]);
    }

    /**
     * Si el asistente se oculta en un curso (ajuste «Cursos sin asistente», `nochatcourses`).
     *
     * @param int $courseid Curso de la pagina; 0 o el del sitio nunca se ocultan.
     * @return bool
     */
    public static function hidden_in_course(int $courseid): bool {
        return self::is_hidden($courseid, (string) get_config('local_chatbot', 'nochatcourses'),
            (string) get_config('local_chatbot', 'chatonlycourses'));
    }

    /**
     * Si el asistente se oculta en un curso, dadas las dos listas del ajuste. Funcion pura.
     *
     * «Solo en estos cursos» (`chatonlycourses`) manda cuando tiene algo: el asistente aparece unicamente
     * en esos cursos (y en las paginas del asistente abiertas desde ellos), para todos los roles; fuera de
     * los cursos (Area personal, id 0 o 1) y en cualquier otro curso, tambien los nuevos, se oculta.
     * «Cursos sin asistente» (`nochatcourses`) oculta ademas los que se listen; sola, nunca oculta el
     * asistente fuera de los cursos.
     *
     * @param int $courseid
     * @param string $nochat Lista de cursos sin asistente.
     * @param string $only Lista de cursos unicos con asistente; vacia = sin restriccion.
     * @return bool
     */
    public static function is_hidden(int $courseid, string $nochat, string $only): bool {
        if (self::is_listed($courseid, $nochat)) {
            return true;
        }
        return trim($only) !== '' && !self::is_listed($courseid, $only);
    }

    /**
     * Si un curso esta en una lista de ids separados por coma, punto y coma o espacio. Funcion pura.
     *
     * El curso 1 es el del sitio: no se puede listar, el asistente siempre se ve fuera de los cursos.
     *
     * @param int $courseid
     * @param string $raw Lista escrita en el ajuste.
     * @return bool
     */
    public static function is_listed(int $courseid, string $raw): bool {
        if ($courseid <= 1) {
            return false;
        }
        foreach (preg_split('/[\s,;]+/', trim($raw)) as $parte) {
            if (ctype_digit($parte) && (int) $parte === $courseid) {
                return true;
            }
        }
        return false;
    }

    /**
     * Valor efectivo de un interruptor. Funcion pura.
     *
     * Sin valor guardado (instalacion que aun no paso por la pagina de ajustes) manda el valor
     * por defecto; con valor guardado, manda lo que eligio quien administra.
     *
     * @param mixed $stored Lo que devuelve get_config (false si no existe).
     * @param int $default
     * @return bool
     */
    public static function resolve($stored, int $default): bool {
        if ($stored === false || $stored === null || $stored === '') {
            return (bool) $default;
        }
        return (string) $stored === '1';
    }
}
