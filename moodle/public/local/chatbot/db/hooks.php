<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Hook subscriptions for local_chatbot.
 */
$callbacks = [
    [
        'hook' => \core\hook\output\after_standard_main_region_html_generation::class,
        'callback' => '\local_chatbot\output_hook::after_main_region',
        'priority' => 100,
    ],
];
