<?php
/**
 * Observadores de eventos del plugin.
 *
 * @package local_chatbot
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        // Patrones del quiz formativo (Anexo G §5.1 y §8.3). Se escucha attempt_graded y no attempt_submitted:
        // en Moodle 5 el envio y la calificacion van separados, y al enviar las preguntas aun no tienen nota.
        'eventname' => '\mod_quiz\event\attempt_graded',
        'callback' => '\local_chatbot\observer::quiz_attempt_graded',
    ],
    [
        // Apertura de un recurso fijo: EVT-U[n]-[LF|CA|ER|QF]-VIEW (Anexo G §4).
        // Se observa la clase padre, que Moodle tambien entrega a los observadores,
        // asi cubre paginas, archivos y cuestionarios sin listar cada modulo.
        'eventname' => '\core\event\course_module_viewed',
        'callback' => '\local_chatbot\observer::module_viewed',
    ],
    [
        // Finalizacion de un recurso fijo: EVT-U[n]-[LF|CA|ER]-COMPLETE y EVT-U[n]-QF-COMPLETE.
        'eventname' => '\core\event\course_module_completion_updated',
        'callback' => '\local_chatbot\observer::module_completed',
    ],
    [
        // Inicio de intento del quiz formativo: EVT-U[n]-QF-START.
        'eventname' => '\mod_quiz\event\attempt_started',
        'callback' => '\local_chatbot\observer::quiz_attempt_started',
    ],
    [
        // Consulta de la retroalimentacion del intento: EVT-U[n]-QF-REVIEW.
        'eventname' => '\mod_quiz\event\attempt_reviewed',
        'callback' => '\local_chatbot\observer::quiz_attempt_reviewed',
    ],
    [
        // Puente con el curso: al entrar, las actividades de cada paso quedan iguales al recorrido.
        'eventname' => '\core\event\course_viewed',
        'callback' => '\local_chatbot\observer::course_viewed',
    ],
    [
        // Puente con el curso: un paso marcado "hecho" sin haberlo hecho se revierte.
        'eventname' => '\core\event\course_module_completion_updated',
        'callback' => '\local_chatbot\observer::bridge_completion_changed',
    ],
];
