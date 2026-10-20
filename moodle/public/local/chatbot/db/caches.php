<?php
/**
 * Cache definitions for local_chatbot.
 *
 * @package    local_chatbot
 * @copyright  2026 Carlos Villegas Morales
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [
    'ratelimit' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl' => 3600,
        'staticacceleration' => true,
    ],
];
