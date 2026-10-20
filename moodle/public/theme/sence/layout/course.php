<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/behat/lib.php');
require_once($CFG->dirroot . '/course/lib.php');

// Add block button in editing mode.
$addblockbutton = $OUTPUT->addblockbutton();

if (isloggedin()) {
    $courseindexopen = (get_user_preferences('drawer-open-index', true) == true);
    $blockdraweropen = (get_user_preferences('drawer-open-block') == true);
} else {
    $courseindexopen = false;
    $blockdraweropen = false;
}

if (defined('BEHAT_SITE_RUNNING') && get_user_preferences('behat_keep_drawer_closed') != 1) {
    $blockdraweropen = true;
}

$extraclasses = ['uses-drawers'];
if ($courseindexopen) {
    $extraclasses[] = 'drawer-open-index';
}

$blockshtml = $OUTPUT->blocks('side-pre');
$hasblocks = (strpos($blockshtml, 'data-block=') !== false || !empty($addblockbutton));
if (!$hasblocks) {
    $blockdraweropen = false;
}
$courseindex = core_course_drawer();
if (!$courseindex) {
    $courseindexopen = false;
}

$bodyattributes = $OUTPUT->body_attributes($extraclasses);
$forceblockdraweropen = $OUTPUT->firstview_fakeblocks();

$secondarynavigation = false;
$overflow = '';
if ($PAGE->has_secondary_navigation()) {
    $tablistnav = $PAGE->has_tablist_secondary_navigation();
    $moremenu = new \core\navigation\output\more_menu($PAGE->secondarynav, 'nav-tabs', true, $tablistnav);
    $secondarynavigation = $moremenu->export_for_template($OUTPUT);
    $overflowdata = $PAGE->secondarynav->get_overflow_menu_data();
    if (!is_null($overflowdata)) {
        $selectmenu = new \core\output\select_menu(
            'tertiarynavigation',
            $overflowdata->urls,
            $overflowdata->selected,
        );
        $selectmenu->set_label($overflowdata->label, $overflowdata->labelattributes);
        $overflow = $selectmenu->export_for_template($OUTPUT);
    }
}

$primary = new core\navigation\output\primary($PAGE);
$renderer = $PAGE->get_renderer('core');
$primarymenu = $primary->export_for_template($renderer);
$buildregionmainsettings = !$PAGE->include_region_main_settings_in_header_actions() && !$PAGE->has_secondary_navigation();
$regionmainsettingsmenu = $buildregionmainsettings ? $OUTPUT->region_main_settings_menu() : false;

$header = $PAGE->activityheader;
$headercontent = $header->export_for_template($renderer);

$coursefullname = ($PAGE->course?->fullname) ? format_string(
    $PAGE->course->fullname,
    true,
    ['context' => context_course::instance($PAGE->course->id), 'escape' => false],
) : '';
$courseurl = $PAGE->course ? new \core\url('/course/view.php', ['id' => $PAGE->course->id]) : null;

// =======================================================
// SENCE CUSTOM COURSE HERO BANNER LOGIC
// =======================================================
$sence_courseimage = '';
$sence_courseteacher = '';
$sence_courseprogress = null;
$sence_categoryname = '';

if ($PAGE->course && $PAGE->course->id != SITEID) {
    // 1. Course Image
    $coursecontext = context_course::instance($PAGE->course->id);
    $fs = get_file_storage();
    $files = $fs->get_area_files($coursecontext->id, 'course', 'overviewfiles', false, 'filename', false);
    if (!empty($files)) {
        $file = reset($files);
        $sence_courseimage = moodle_url::make_pluginfile_url($file->get_contextid(), $file->get_component(), $file->get_filearea(), null, $file->get_filepath(), $file->get_filename())->out();
    }
    
    // 2. Course Progress
    if (class_exists('\core_completion\progress')) {
        $progress = \core_completion\progress::get_course_progress_percentage($PAGE->course);
        if (isset($progress)) {
            $sence_courseprogress = floor($progress);
        }
    }
    
    // 3. Category Name and Teacher
    try {
        $cc = core_course_category::get($PAGE->course->category);
        $sence_categoryname = $cc->name;
        
        $courseobj = new core_course_list_element($PAGE->course);
        if ($courseobj->has_course_contacts()) {
            $contacts = $courseobj->get_course_contacts();
            if (!empty($contacts)) {
                $firstcontact = reset($contacts);
                $sence_courseteacher = $firstcontact['username'];
            }
        }
    } catch (\Exception $e) {
        // Ignore errors if category is deleted
    }
}

$templatecontext = [
    'sitename' => format_string($SITE->shortname, true, ['context' => context_course::instance(SITEID), "escape" => false]),
    'coursefullname' => $coursefullname,
    'courseurl' => $courseurl ? $courseurl->out(false) : null,
    'output' => $OUTPUT,
    'sidepreblocks' => $blockshtml,
    'hasblocks' => $hasblocks,
    'bodyattributes' => $bodyattributes,
    'courseindexopen' => $courseindexopen,
    'blockdraweropen' => $blockdraweropen,
    'courseindex' => $courseindex,
    'primarymoremenu' => $primarymenu['moremenu'],
    'secondarymoremenu' => $secondarynavigation ?: false,
    'mobileprimarynav' => $primarymenu['mobileprimarynav'],
    'usermenu' => $primarymenu['user'],
    'langmenu' => $primarymenu['lang'],
    'forceblockdraweropen' => $forceblockdraweropen,
    'regionmainsettingsmenu' => $regionmainsettingsmenu,
    'hasregionmainsettingsmenu' => !empty($regionmainsettingsmenu),
    'overflow' => $overflow,
    'headercontent' => $headercontent,
    'addblockbutton' => $addblockbutton,
    
    // Custom Sence variables
    'sence_courseimage' => $sence_courseimage,
    'sence_courseteacher' => $sence_courseteacher,
    'sence_courseprogress' => $sence_courseprogress,
    'sence_categoryname' => $sence_categoryname,
    'courseid' => $PAGE->course->id,
];

echo $OUTPUT->render_from_template('theme_sence/course', $templatecontext);
