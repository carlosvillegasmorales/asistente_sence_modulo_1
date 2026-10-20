<?php
define('CLI_SCRIPT', true);
require('c:\ProyectoSence\moodle-latest-502\moodle\config.php');
global $PAGE, $OUTPUT, $DB;
$course = $DB->get_record('course', ['id' => 7]);
$PAGE->set_course($course);
$PAGE->set_context(context_course::instance($course->id));
$PAGE->set_url('/course/view.php', ['id' => $course->id]);
$PAGE->set_pagelayout('course');
$PAGE->set_heading($course->fullname);

// Render the course page header to see what is output
echo $OUTPUT->header();
