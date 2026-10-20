<?php
/**
 * Capability definitions for local_chatbot.
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [

    // Usar el asistente y trabajar el propio recorrido del Modulo 1
    // (perfilamiento, diagnostico, PDP y proyecto integrador). Cada persona
    // solo ve y edita lo suyo; esto no habilita ver el trabajo de otros.
    'local/chatbot:use' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'user' => CAP_ALLOW,
            'student' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],

    // Cargar y retirar los documentos que el asistente cita como recursos
    // internos del modulo antes de recurrir a conocimiento general.
    'local/chatbot:managekb' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // Configurar el instrumento oficial del modulo: dimensiones e items del
    // diagnostico, criterios de la rubrica del proyecto integrador y las
    // versiones de prompt de cada modo del asistente. Es deliberadamente
    // restrictiva: define como la IA evalua y recomienda a las personas.
    'local/chatbot:manageinstrument' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'riskbitmask' => RISK_CONFIG,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // Ver los indicadores iniciales del modulo. Los datos que expone son
    // agregados y anonimizados por perfil, dimension y uso, tal como pide la
    // seccion de gestion institucional del documento de arquitectura.
    'local/chatbot:viewanalytics' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
];
