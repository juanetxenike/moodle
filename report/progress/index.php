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
 * Display Activity completion report
 *
 * @package    report_progress
 * @copyright  2024 onwards WIDE Services {@link https://www.wideservices.gr}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\exception\moodle_exception;
use core\report_helper;
use report_completion\course_report_pdf;
use report_progress\local\helper;
use report_progress\output\report;
use core\url as moodle_url;
use core\context\course;
use core\user;

require('../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once("{$CFG->libdir}/completionlib.php");
require_once($CFG->dirroot . '/report/progress/lib.php');

/**
 * Configuration
 */
define('COMPLETION_REPORT_PAGE',        25);
define('COMPLETION_REPORT_COL_TITLES',  true);

// GET PARAMETERS.
$courseid = required_param('course', PARAM_INT);
$format = optional_param('format', '', PARAM_ALPHA);
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = course::instance($course->id);
$url = new moodle_url('/report/progress/index.php', ['course' => $course->id]);

if (!$course) {
    throw new moodle_exception(errorcode: 'invalidcourseid');
}

$excel = ($format == 'excelcsv');
$csv = ($format == 'csv' || $excel);

$activityinclude = optional_param('activityinclude', 'all', PARAM_TEXT);
$activityorder = optional_param('activityorder', 'orderincourse', PARAM_TEXT);
$activitysection = optional_param('activitysection', -1, PARAM_INT);

// DEFINE ACCESS.

require_login( $course );
require_capability('report/progress:view', $context);

// Sort (default lastname, optionally firstname).
$sort = optional_param('sort', '', PARAM_ALPHA);
$firstnamesort = $sort == 'firstname';

// The group parameter is optional, but if it is present, it must be valid.
// It serves two purposes.
// 1. To verify that the group exists in the course, and if it doesn´t to verify that the course has groups.
// And that the user has the capability to access all groups.
// 2. It is used to filter the users that are going to be displayed in the report.
$group = groups_get_course_group($course, true); // Supposed to verify group.
if ($group === 0 && $course->groupmode == SEPARATEGROUPS) {
    require_capability('moodle/site:accessallgroups', $context);
}

$userfields = \core_user\fields::for_identity($context);
$extrafields = $userfields->get_required_fields([\core_user\fields::PURPOSE_IDENTITY]);

$completion = new completion_info($course);
list($activitytypes, $activities) = helper::get_activities_to_show($completion, $activityinclude, $activityorder, $activitysection);

// Generate where clause.
// The following variables will be used as a part of a sql query within the get_progress_all function.
// This function gets parts of the query as parameters and returns a list of users with their completion status.
$page = optional_param('page', 0, PARAM_INT);
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
$progress = [];

if ($total) {
    $progress = $completion->get_progress_all(
        implode(' AND ', $where),
        $whereparams,
        $group,
        $firstnamesort ? 'u.firstname ASC, u.lastname ASC' : 'u.lastname ASC, u.firstname ASC',
        0,
        0,
        $context
    );
}

if ( $csv ) {

    $shortname = format_string($course->shortname, true, array('context' => $context));
    $shortname = preg_replace('/[^a-z0-9-]/', '_', core_text::strtolower(strip_tags($shortname)));
    require_once("{$CFG->libdir}/csvlib.class.php");
    $export = new csv_export_writer('comma', '"', 'application/download', $excel);
    $export->set_filename('progress-'.$shortname);

    $headerrow = [];
    $headerrow[] = get_string('completion', 'completion');
    foreach ($extrafields as $field) {
        $headerrow[] = \core_user\fields::get_display_name($field);
    }

    foreach ($activities as $activity) {
        $datetext = $activity->completionexpected ? userdate($activity->completionexpected, "%F %T") : '';
        $displayname = format_string($activity->name, true, ['context' => $activity->context]);
        $headerrow[] = $displayname;
        $headerrow[] = $datetext;
    }

    $export->add_data($row);

    foreach ($progress as $user) {
        $usersrow = [];
        $usersrow[] = fullname($user, has_capability('moodle/site:viewfullnames', $context));
        foreach ($extrafields as $field) {
            $usersrow[] = $user->{$field};
        }
        foreach ($activities as $activity) {
            $state = COMPLETION_INCOMPLETE;
            $overrideby = 0;
            $date = '';
            if (array_key_exists($activity->id, $user->progress)) {
                $thisprogress = $user->progress[$activity->id];
                $state = $thisprogress->completionstate;
                $overrideby = $thisprogress->overrideby;
                $date = userdate($thisprogress->timemodified);
            }
            // Work out how it corresponds to an icon.
            switch($state) {
                case COMPLETION_INCOMPLETE :
                    $completiontype = 'n'.($overrideby ? '-override' : '');
                    break;
                case COMPLETION_COMPLETE :
                    $completiontype = 'y'.($overrideby ? '-override' : '');
                    break;
                case COMPLETION_COMPLETE_PASS :
                    $completiontype = 'pass';
                    break;
                case COMPLETION_COMPLETE_FAIL :
                    $completiontype = 'fail';
                    break;
            }
            $describe = get_string('completion-' . $completiontype, 'completion');
            if ($overrideby) {
                $overridebyuser = user::get_user($overrideby, '*', MUST_EXIST);
                $describe = get_string('completion-' . $completiontype, 'completion', fullname($overridebyuser));
            }
            $date = ($date != '') ? userdate($thisprogress->timemodified, "%F %T") : '';
            $usersrow[] = $describe . ' ' . $date;
        }
        $export->add_data($usersrow);
    }
    $export->download_file();
    exit;
}

// CREATE HTML.
$renderable = new report(
    $courseid,
    $format,
    $activityinclude,
    $activityorder,
    $activitysection,
    $totalheader,
    $progress
);
$renderer = $PAGE->get_renderer('report_progress');
$html = $renderer->render_activity_completion_report($renderable);

if ( $format == 'pdf' ) {
    require_once("{$CFG->libdir}/pdflib.php");
    // SEND TO PDF OUTPUT.
    $pdf = new course_report_pdf();
    $pdf->writeHTML($html);
    $pdf->Output('progress.pdf', 'I');
    exit;
}

// PAGE SETUP.
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'report_progress'));
echo $OUTPUT->header();
$pluginname = get_string('pluginname', 'report_progress');
report_helper::print_report_selector($pluginname);

echo $progressengine->pagingbar($course, $sort, $sifirst, $silast, $total, $url, $page);
if (!$total) {
    echo $OUTPUT->notification(get_string('nothingtodisplay'), 'info', false);
    echo $OUTPUT->footer();
    exit;
}
echo $html;
echo $OUTPUT->footer();
