<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Course completion progress report
 *
 * @package    report
 * @subpackage completion
 * @copyright  2024 onwards WIDE Services {@link https://www.wideservices.gr}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\report_helper;
use report_completion\course_report_pdf;
use report_completion\engine;
use report_completion\output\report;
use report_completion\renderer;

require('../../config.php');
require_once("{$CFG->libdir}/completionlib.php");

/**
 * Configuration.
 */
define('COMPLETION_REPORT_PAGE',        25);
define('COMPLETION_REPORT_COL_TITLES',  true);

/*
 * Setup page.
 */
// Get parameters.
$courseid = required_param('course', PARAM_INT);
$format = optional_param('format','',PARAM_ALPHA);
$sort = optional_param('sort','',PARAM_ALPHA);
$edituser = optional_param('edituser', 0, PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($course->id);
$url = new moodle_url('/report/completion/index.php', ['course' => $course->id]);

$firstnamesort = ($sort == 'firstname');

$excel = ($format == 'excelcsv');
$csv = ($format == 'csv' || $excel);

$dateformat = $csv ? get_string('strftimedatetimeshort', 'langconfig') : "%F %T";

// DEFINE ACCESS.
require_login($course);
require_capability('report/completion:view', $context);

// The group parameter is optional, but if it is present, it must be valid.
// It serves two purposes.
// 1. To verify that the group exists in the course, and if it doesn´t to verify that the course has groups.
// And that the user has the capability to access all groups.
// 2. It is used to filter the users that are going to be displayed in the report.
$group = groups_get_course_group($course, true); // Supposed to verify group.
if ($group === 0 && $course->groupmode == SEPARATEGROUPS) {
    require_capability('moodle/site:accessallgroups', $context);
}

// Retrieve course_module data for all modules in the course.
$modinfo = get_fast_modinfo($course);

// DATA PREPARATION.
// Get completion information for course.
$completion = new completion_info($course);
// Create a criteria array to store the criteria.
$criteria = [];

// The criteria will on one hand contain the completion criteria for the course and the completion criteria for activities.

// These are references to the mdl_course_completion_criteria table and are defined in the completionlib.php file.
$criteria = array_merge(
    $completion->get_criteria(COMPLETION_CRITERIA_TYPE_COURSE),
    $completion->get_criteria(COMPLETION_CRITERIA_TYPE_ACTIVITY),
    array_filter(
        $completion->get_criteria(),
        fn($criterion) => !in_array($criterion->criteriatype, [
            COMPLETION_CRITERIA_TYPE_COURSE, COMPLETION_CRITERIA_TYPE_ACTIVITY,
        ])
    )
);

// Generate where clause.
// The following variables will be used as a part of a sql query within the get_progress_all function.
// This function gets parts of the query as parameters and returns a list of users with their completion status.
$start   = optional_param('start', 0, PARAM_INT);
$sifirst = optional_param('sifirst', 'all', PARAM_NOTAGS);
$silast  = optional_param('silast', 'all', PARAM_NOTAGS);
$preferences = ['ifirst' => $sifirst, 'ilast' => $silast];
array_map( fn($key, $value) => $value !== 'all'
            ? set_user_preference($key, $value)
            : null,
            array_keys($preferences),
        $preferences);

$sifirst = $USER->preference['ifirst'] ?? 'all';
$silast  = $USER->preference['ilast'] ?? 'all';

$where = [];
$whereparams = [];

$fields = [
    'sifirst' => 'u.firstname',
    'silast' => 'u.lastname',
];

// Iterate through the fields array and assign values.
array_walk($fields, function($column, $param) use (&$where, &$whereparams, $sifirst, $silast, $DB) {
    $paramvalue = ($param === 'sifirst') ? $sifirst : $silast;
    if ($paramvalue !== 'all') {
        $where[] = $DB->sql_like($column, ":$param", false, false);
        $whereparams[$param] = $paramvalue . '%';
    }
});

// Get user match count.
$total = $completion->get_num_tracked_users(implode(' AND ', $where), $whereparams, $group);
// Total user count.
$grandtotal = $completion->get_num_tracked_users('', [], $group);
$totalheader = ($total == $grandtotal) ? $total : "{$total}/{$grandtotal}";

// Get user data.
// Obtains progress information across a course for all users on that course.
// Or for all users in a specific group. Intended for use when displaying progress.
$progress = ($total) ? $completion->get_progress_all(
                            implode(' AND ', $where), // AND LIKE u.firstname = :sifirst AND LIKE u.lastname = :silast.
                            $whereparams, // Placeholders: firstname% and lastname%.
                            $group, // Active group in course.
                            'u.lastname ASC',
                            0,
                            0,
                            $context)
            : [];

// CREATE DATA TO EXPORT TO TEMPLATE.
$extrafields = (array) \core_user\fields::get_identity_fields($context, true);
$leftcols = 1 + count($extrafields);

$engine = new engine($courseid, $format);

// Print criteria titles.
if (COMPLETION_REPORT_COL_TITLES) {
    $criteriatitles = array_map(function($criterion) {
        return $criterion->get_title_detailed();
    }, $criteria);
}

if ($csv) {
    $criteriaheaders = [];
    $criteriaheaders[] = get_string('id', 'report_completion');
    $criteriaheaders[] = get_string('name', 'report_completion');
    foreach ($extrafields as $field) {
        $criteriaheaders[] = \core_user\fields::get_display_name($field);
    }
    require_once("{$CFG->libdir}/csvlib.class.php");
    $shortname = format_string($course->shortname, true, ['context' => $context]);
    $shortname = preg_replace('/[^a-z0-9-]/', '_', core_text::strtolower(strip_tags($shortname)));
    $export = new csv_export_writer('comma', '"', 'application/download', $excel);
    $export->set_filename('completion-'.$shortname);

    $criteriaheaders = array_merge($criteriaheaders, array_reduce($criteria, function($carry, $criterion) use ($modinfo) {
        if ($criterion->criteriatype == COMPLETION_CRITERIA_TYPE_ACTIVITY) {
            $mod = $criterion->get_mod_instance();
            $activity = $modinfo->cms[$criterion->moduleinstance];
            $sectionname = get_section_name($activity->course, $activity->sectionnum);
            $formattedname = format_string($mod->name, true, ['context' => context_module::instance($criterion->moduleinstance)]);
            $carry[] = $formattedname . ' - ' . $sectionname;
            $carry[] = $formattedname . ' - ' . get_string('completiondate', 'report_completion');
        } else {
            $carry[] = strip_tags($criterion->get_title_detailed());
        }
        return $carry;
    }, []));

    $criteriaheaders[] = get_string('coursecomplete', 'completion');
    $export->add_data($criteriaheaders);

    $progress = array_map(function($user) use ($extrafields, $criteria,
                            $completion, $modinfo, $dateformat, $context, $course, $export) {
        $usersarray = [
            $user->id,
            fullname($user, has_capability('moodle/site:viewfullnames', $context)),
        ];

        $usersarray = array_merge($usersarray, array_map(fn($field) => $user->{$field}, $extrafields));

        array_map(function($criterion) use ($user, $completion, $modinfo, $dateformat, $course, &$usersarray) {
            $criteriacompletion = $completion->get_user_completion($user->id, $criterion);
            if ($criterion->criteriatype == COMPLETION_CRITERIA_TYPE_ACTIVITY) {
                $activity = $modinfo->cms[$criterion->moduleinstance];
                $state = COMPLETION_INCOMPLETE;
                $iscomplete = $criteriacompletion->is_complete();
                if (array_key_exists($activity->id, $user->progress)) {
                    $state = $user->progress[$activity->id]->completionstate;
                } else if ($iscomplete) {
                    $state = COMPLETION_COMPLETE;
                }
                $completiontype = match ($state) {
                    COMPLETION_INCOMPLETE => 'n',
                    COMPLETION_COMPLETE => 'y',
                    COMPLETION_COMPLETE_PASS => 'pass',
                    COMPLETION_COMPLETE_FAIL => 'fail',
                    default => '',
                };
                $usersarray[] = get_string('completion-'.$completiontype, 'completion');
                $usersarray[] = $iscomplete ? userdate($criteriacompletion->timecompleted, $dateformat) : '';
            }
        }, $criteria);

        $ccompletion = new completion_completion(['userid' => $user->id, 'course' => $course->id]);
        $usersarray[] = $ccompletion->is_complete() ? userdate($ccompletion->timecompleted, $dateformat) : '';

        $export->add_data($usersarray);
        return $usersarray;
    }, $progress);
    $export->download_file();
    exit;
}
// END CSV.

// CREATE HTML.
$a = $course->fullname;

$renderable = new report($courseid, $format, $grandtotal, $leftcols, $completion, $progress);
$renderer = $PAGE->get_renderer('report_completion');
$html = $renderer->render_completion_report($renderable);

if ($format == 'pdf') {
    require_once("{$CFG->libdir}/pdflib.php");
    // SEND TO PDF OUTPUT.
    $pdf = new course_report_pdf();
    $pdf->writeHTML($html);
    $pdf->Output('completion.pdf', 'I');
    exit;
}
// PAGE SETUP.
// If no users in this course what-so-ever.
if (!$grandtotal) {
    echo $OUTPUT->container(get_string('err_nousers', 'completion'), 'errorbox errorboxcontent');
    echo $OUTPUT->footer();
    exit;
}
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->set_context($context);
$PAGE->set_title(get_string('coursecompletion'));
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();

$pluginname = get_string('pluginname', 'report_completion');
report_helper::print_report_selector($pluginname);

echo $engine->pagingbar($course, $sort, $sifirst, $silast, $total, $url, $start);
if (!$total) {
    echo $OUTPUT->notification(get_string('nothingtodisplay'), 'info', false);
    echo $OUTPUT->footer();
    exit;
}

echo $html;
echo $OUTPUT->footer($course);
