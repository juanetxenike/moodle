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

namespace report_progress;
use report_progress\local\helper;
use core\url as moodle_url;
use core_user\fields;

/**
 * Class engine
 *
 * @package    report_progress
 * @copyright  2024 onwards WIDE Services {@link https://www.wideservices.gr}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class engine {


    /**
     * Retrieves extra fields based on the provided context.
     *
     * @param mixed $context The context for which extra fields are to be retrieved.
     * @return array An array of extra fields.
     */
    public function extrafields($context): array {
        $userfields = fields::for_identity($context);
        return $userfields->get_required_fields([fields::PURPOSE_IDENTITY]);
    }
    /**
     * Formats the given activities.
     *
     * @param array $activities An array of activities to be formatted.
     * @return mixed The formatted activities.
     */
    public function formatted_activities($activities) {
        return array_map(function($activity) {
            $datepassed = $activity->completionexpected && $activity->completionexpected <= time();
            $datetext = $activity->completionexpected
                ? userdate($activity->completionexpected, get_string('strftimedate', 'langconfig'))
                : '';

            // Some names (labels) come URL-encoded and can be very long, so shorten them.
            $displayname = ucfirst($activity->modname) .' '
                            . format_string($activity->name, true, ['context' => $activity->context]);
            $shortenedname = shorten_text($displayname);

            return [
                'title' => ($datetext == '') ? $shortenedname : $shortenedname . ' - ' . $datetext,
                'datepassedclass' => $datepassed ? 'date-passed' : 'date-not-passed', // Assuming you have a class for date passed.
                'displayname' => $displayname,
            ];
        }, $activities);
    }

    /**
     * Generates the section headers for the given activities.
     *
     * @param array $activities An array of activities to generate headers for.
     * @return void
     */
    public function section_headers($activities) {
        // Filter out repeated section values, keeping only the last occurrence.
        $sectionsarray = array_values(array_reduce($activities, function ($acc, $activity) {
            $sectionname = get_section_name($activity->course, $activity->sectionnum);
            $acc[$sectionname] = [
                'section' => $activity->section,
                'sectionname' => $sectionname,
            ];
            return $acc;
        }, []));

        // Count the occurrences of each "section".
        // Since we forced the former array to have unique values, we can now get the count of each "method" value.
        $sectioncounts = array_map(function($activity) {
            return [
                'type' => get_section_name($activity->course, $activity->sectionnum),
            ];
        }, $activities);
        $sectioncountsarray = array_count_values(array_column($sectioncounts, 'type'));

        // Map the unique "section" values to include the colcount.
        // The colcount will be the number of times the "section" value appears in the $criteriamethods array.
        // This will allow us to span a column for each "section" value along the number of times it appears.
        return array_map(function($section) use ($sectioncountsarray) {
            return [
                'sectionname' => $section['sectionname'],
                'colcount' => $sectioncountsarray[$section['sectionname']],
            ];
        }, array_values($sectionsarray));
    }
    /**
     * Generates and returns the icons representing the completion criteria for a given module.
     *
     * @param array $activities An array of activities that need to be met for completion.
     * @param string $format Information about the module for which the criteria icons are being generated.
     * @return array The generated icons.
     */
    public function activity_icons(array $activities, string $format): array {
        global $DB, $CFG, $OUTPUT;
        $activityicons = [];
        foreach ($activities as $activity) {
            // Generate icon details.
            $iconlink = '';
            $iconalt = ''; // Required.
            $iconattributes = ['class' => 'icon'];
            $iconlink = new moodle_url('/mod/'.$activity->modname.'/view.php', ['id' => $activity->id]);
            $icontitle = format_string($activity->name, true, ['context' => $activity->context]);
            $activityicons[] = [
                'icon' => ($format != 'csv' && $format != 'pdf' && $format != 'excelcsv')
                    ? $OUTPUT->image_icon('monologo', get_string('modulename', $activity->modname), $activity->modname)
                    : '',
                'url' => $iconlink->out(),
                'title' => $icontitle,
            ];
        }
        return $activityicons;
    }
    /**
     * Calculates and returns the progress of users.
     *
     * @param array $progress An array containing progress data for users.
     * @param object $context The context in which the progress is being calculated.
     * @param array $extrafields Additional fields that may be required for progress calculation.
     * @param array $activities An array of activities to consider in the progress calculation.
     * @param string $format The format in which the progress is being calculated.
     * @return array The calculated progress for each user.
     */
    public function users_progress(array $progress, object $context,
                                    array $extrafields, array $activities, string $format) {
        global $OUTPUT;
        return array_map(function ($user) use ($context, $extrafields, $activities, $format, $OUTPUT) {
            // For each user: Progress for each activity.
            $activityprogress = array_map(function ($activity) use ($user, $format, $OUTPUT) {

                // Get progress information and state.
                $thisprogress = $user->progress[$activity->id] ?? new \stdClass();
                $thisprogress->completionstate = $thisprogress->completionstate ?? COMPLETION_INCOMPLETE;
                $state = (int) $thisprogress->completionstate ?? (int) COMPLETION_INCOMPLETE;
                $overrideby = $thisprogress->overrideby ?? 0;
                $date = ($state != 0) ? userdate($thisprogress->timemodified, '%a %d-%b-%y %H:%M') : '';

                // Work out how it corresponds to an icon.
                switch($state){
                    case COMPLETION_INCOMPLETE:
                        $completiontype = 'n' . ($overrideby ? '-override' : '');
                    break;
                    case COMPLETION_COMPLETE:
                        $completiontype = 'y' . ($overrideby ? '-override' : '');
                    break;
                    case COMPLETION_COMPLETE_PASS:
                        $completiontype = 'pass';
                    break;
                    case COMPLETION_COMPLETE_FAIL:
                        $completiontype = 'fail';
                    break;
                    default:
                        throw new \UnexpectedValueException('Unexpected state value');
                }
                $auto = $activity->completion == COMPLETION_TRACKING_AUTOMATIC;
                $completionicon = 'completion-'.($auto ? 'auto' : 'manual').'-'.$completiontype;
                $completiontrackingstring = $activity->completion == COMPLETION_TRACKING_AUTOMATIC ? 'auto' : 'manual';
                $describe = get_string('completion-' . $completiontype, 'completion');
                $a = new \StdClass;
                $a->state = $describe;
                $fulldescribe = get_string('progress-title', 'completion', $a);
                $describe = $format == "pdf" ?
                                        ($completiontype == 'n' ? '6' : '3')
                                        : $OUTPUT->pix_icon('i/' . $completionicon, $fulldescribe);
                return [
                    'date' => $date,
                    'describe' => $describe,
                ];
            }, $activities);

            // Initialize accumulator for array_reduce.
            $initial = [
                'grouped_array' => [],
                'prev_date' => null,
                'prev_describe' => null,
                'colcount' => 0,
            ];

            $result = array_reduce($activityprogress, function($carry, $item) {
                $currentdate = $item['date'];
                $currentdescribe = $item['describe'];

                if ($carry['prev_date'] === $currentdate && $carry['prev_describe'] === $currentdescribe) {
                    $carry['colcount']++;
                } else {
                    if ($carry['prev_date'] !== null) {
                        $carry['grouped_array'][] = [
                            'date' => $carry['prev_date'],
                            'describe' => $carry['prev_describe'],
                            'colcount' => $carry['colcount'] == '' ? 1 : $carry['colcount'],
                        ];
                    }
                    $carry['prev_date'] = $currentdate;
                    $carry['prev_describe'] = $currentdescribe;
                    $carry['colcount'] = 1;
                }
                return $carry;
            }, $initial);

            // Add the last group.
            if ($result['prev_date'] !== null) {
                $result['grouped_array'][] = [
                    'date' => $result['prev_date'],
                    'describe' => $result['prev_describe'],
                    'colcount' => $result['colcount'] != '' ? $result['colcount'] : 1,
                ];
            }

            return [
                'fullname' => fullname($user, has_capability('moodle/site:viewfullnames', $context)),
                'extrafields' => array_map(fn($field) => s($user->{$field}), $extrafields),
                'activityprogress' => array_values($result['grouped_array']),
            ];
        }, $progress);
    }

    /**
     * Generates a paging bar for navigating through a list of items.
     *
     * @param object $course The course object.
     * @param string $sort The sorting criteria.
     * @param string $sifirst The first initial of the user's surname.
     * @param string $silast The last initial of the user's surname.
     * @param int $total The total number of items.
     * @param string $url The URL for the paging bar links.
     * @param int $page The current page number.
     *
     * @return string
     */
    public function pagingbar($course, $sort, $sifirst, $silast, $total, $url, $page): string {
        global $OUTPUT, $CFG;
        // Build link for paging.
        $link = $CFG->wwwroot.'/report/completion/index.php?course='.$course->id;
        if (strlen($sort)) {
            $link .= '&amp;sort='.$sort;
        }
        $link .= '&amp;start=';

        $pagingbar = '';
        // Initials bar.
        $prefixfirst = 'sifirst';
        $prefixlast = 'silast';
        // The URL used in the initials bar should reset the 'start' parameter.
        $initialsbarurl = fullclone($url);
        $initialsbarurl->remove_params('page');
        $pagingbar .= $OUTPUT->initials_bar($sifirst, 'firstinitial', get_string('firstname'), $prefixfirst, $initialsbarurl);
        $pagingbar .= $OUTPUT->initials_bar($silast, 'lastinitial', get_string('lastname'), $prefixlast, $initialsbarurl);
        $pagingbar .= $OUTPUT->paging_bar($total, $page, helper::COMPLETION_REPORT_PAGE, $url);
        return $pagingbar;
    }
}
