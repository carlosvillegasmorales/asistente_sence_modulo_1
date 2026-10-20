<?php
/**
 * Tipos de notificacion del asistente.
 *
 * Los recordatorios llegan por la campana de Moodle. El correo queda permitido pero apagado por
 * defecto: cada persona, o quien administra, lo puede encender en las preferencias de
 * notificacion. Enviar correos sin que el programa lo haya decidido seria una accion hacia afuera
 * que no le corresponde al plugin.
 *
 * @package    local_chatbot
 */

defined('MOODLE_INTERNAL') || die();

$messageproviders = [
    'reminder' => [
        'defaults' => [
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_PERMITTED,
        ],
    ],
];
