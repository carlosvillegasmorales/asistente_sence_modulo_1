<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/config.php');
require_once($CFG->dirroot . '/enrol/locallib.php');

$course = $DB->get_record('course', ['shortname' => 'CURSO_GRANDE'], '*', MUST_EXIST);
$user = $DB->get_record('user', ['username' => 'admin'], '*', MUST_EXIST);

$enrol = enrol_get_plugin('manual');
$instances = enrol_get_instances($course->id, true);
$manualinstance = null;
foreach ($instances as $instance) {
    if ($instance->enrol === 'manual') {
        $manualinstance = $instance;
        break;
    }
}

if (!$manualinstance) {
    $courseid = $course->id;
    $enrol->add_default_instance($course);
    $instances = enrol_get_instances($course->id, true);
    foreach ($instances as $instance) {
        if ($instance->enrol === 'manual') {
            $manualinstance = $instance;
            break;
        }
    }
}

$role = $DB->get_record('role', ['shortname' => 'student']);
$enrol->enrol_user($manualinstance, $user->id, $role->id);

echo "Admin enrolled successfully into CURSO_GRANDE as student!\n";
