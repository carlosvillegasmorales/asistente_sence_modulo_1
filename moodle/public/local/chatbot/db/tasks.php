<?php
/**
 * Scheduled tasks for local_chatbot.
 *
 * @package    local_chatbot
 * @copyright  2026 Carlos Villegas Morales
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => 'local_chatbot\task\purge_messages_task',
        'blocking' => 0,
        'minute' => '15',
        'hour' => '3',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*',
    ],
    [
        // Recordatorios del asistente: una vez al dia, en horario de oficina.
        'classname' => 'local_chatbot\task\send_reminders',
        'blocking' => 0,
        'minute' => '0',
        'hour' => '9',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*',
    ],
];
