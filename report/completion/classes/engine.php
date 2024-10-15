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

namespace report_completion;

use completion_completion;
use completion_info;
use core\context\course;
use core_user\fields;
use moodle_url;

/**
 * Class engine
 *
 * @package    report_completion
 * @subpackage completion
 * @copyright  2024 onwards WIDE Services {@link https://www.wideservices.gr}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class engine {

    /**
     * @var int $courseid The ID of the course for which the completion report is generated.
     */
    private $courseid;
    /**
     * @var string $format The format used for the completion report.
     */
    private $format;
    /**
     * @var mixed $course The course object or identifier associated with the completion report.
     */
    private $course;
    /**
     * @var mixed $completion The completion status or data for the report.
     */
    public $completion;
    /**
     * @var mixed $modinfo Holds module information.
     */
    private $modinfo;
    /**
     * @var mixed $context The context in which the completion report is generated.
     */
    private $context;
    /**
     * @var string $dateformat The format in which dates will be displayed.
     */
    private $dateformat;
    /**
     * @var mixed $criteria The criteria used for completion tracking.
     */
    private $criteria;
    /**
     * @var array $hasagg Indicates whether the aggregation has been performed.
     */
    private $hasagg;

    /**
     * Constructor for the engine class.
     *
     * @param int $courseid The ID of the course.
     * @param string $format The format of the report.
     */
    public function __construct(int $courseid, string $format) {
        global $DB;
        $this->course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $this->format = $format;
        $this->completion = new completion_completion();
        $this->modinfo = get_fast_modinfo($this->course);
        $this->context = course::instance($courseid);
        $this->dateformat = ($format == 'csv') || ($format == 'excelcsv')
            ? get_string('strftimedatetimeshort', 'langconfig')
            : "%F %T";
        $this->completion = new completion_info($this->course);
        $this->criteria = array_merge(
            $this->completion->get_criteria(COMPLETION_CRITERIA_TYPE_COURSE),
            $this->completion->get_criteria(COMPLETION_CRITERIA_TYPE_ACTIVITY),
            array_filter(
                $this->completion->get_criteria(),
                fn($criterion) => !in_array($criterion->criteriatype, [
                    COMPLETION_CRITERIA_TYPE_COURSE, COMPLETION_CRITERIA_TYPE_ACTIVITY,
                ])
            )
        );
        $this->hasagg = [
            COMPLETION_CRITERIA_TYPE_COURSE,
            COMPLETION_CRITERIA_TYPE_ACTIVITY,
            COMPLETION_CRITERIA_TYPE_ROLE,
        ];
    }

    /**
     * Retrieves extra fields for a given course.
     *
     * @param object $course The course object for which extra fields are to be retrieved.
     * @return array An array of extra fields associated with the course.
     */
    public function get_extrafields($course) {
        return fields::get_identity_fields($this->context, true);
    }

    /**
     * Retrieves an array of fields.
     *
     * This method returns an array containing the fields used in the completion report.
     *
     * @return array An array of fields.
     */
    public function fieldsarray() {
        return array_map(function ($field) {
            return fields::get_display_name($field);
        }, fields::get_identity_fields($this->context, true));
    }
    /**
     * Determines the types of criteria based on the provided parameters.
     *
     * @return array The result based on the criteria, completion, and aggregation status.
     */
    public function criteria_types(): array {
        // Filter out repeated "type" values, keeping only the last occurrence.
        // Criteria types will have a list of arrays only with unique values.
        $criteriatypes = array_values(array_reduce($this->criteria, function(array $acc, $criterion){
                    $acc[$criterion->criteriatype] = [
                        'type' => $criterion->criteriatype,
                        'title' => $criterion->get_type_title(),
                    ];
                    return $acc;
        }, []));
        // Since we forced the former array to have unique values, we can now get the count of each "type" value.
        $typecounts = array_map(function($criterion): array {
            return [
                'type' => $criterion->criteriatype,
            ];
        }, $this->criteria);
        $typecountsarray = array_count_values(array_column($typecounts, 'type'));
        // Map the unique "type" values to include the colcount.
        // The colcount will be the number of times the "type" value appears in the $criteria array.
        // This will allow us to span a column for each "type" value along the number of times it appears.
        return array_map(function($item) use ($typecountsarray): array {
            return [
                'colcount' => $typecountsarray[$item['type']] == '' ? 1 : $typecountsarray[$item['type']],
                'currentgrouptypetitle' => $item['title'],
            ];
        }, $criteriatypes, array_keys($criteriatypes));
    }

    /**
     * Processes the criteria methods for completion.
     *
     * @return array The result of the criteria methods processing.
     */
    public function criteria_methods(): array {
        // Filter out repeated "method" values, keeping only the last occurrence.
        // Criteria types will have a list of arrays only with unique values.
        $completion = $this->completion;
        $hasagg = $this->hasagg;
        $criteriamethods = array_reduce($this->criteria, function(array $carry, $criterion) use ($completion, $hasagg) {
            // Try load a aggregation method.
            $carry[$criterion->criteriatype] = [
                'method' => (in_array($criterion->criteriatype, $hasagg)) ?
                        ($completion->get_aggregation_method($criterion->criteriatype) == 1 ?
                            get_string('all')
                            : get_string('any'))
                        : '-',
            ];
            return $carry;
        }, []);

        // Since we forced the former array to have unique values, we can now get the count of each "method" value.
        $methodcounts = array_map(function($criterion) use($completion, $hasagg): array {
            return  [ 'method' => (in_array($criterion->criteriatype, $hasagg)) ?
                        ($completion->get_aggregation_method($criterion->criteriatype) == 1 ?
                            get_string('all')
                            : get_string('any'))
                        : '-'];
        }, $this->criteria);
        $methodcountsarray = array_count_values(array_column($methodcounts, 'method'));

        // Map the unique "method" values to include the colcount.
        // The colcount will be the number of times the "method" value appears in the $criteriamethods array.
        // This will allow us to span a column for each "method" value along the number of times it appears.
        return array_map(function($item, $key) use ($methodcountsarray) {
            return [
                'colcount' => $methodcountsarray[$item['method']] == '' ? 1 : $methodcountsarray[$item['method']],
                'method' => $item['method'],
            ];
        }, $criteriamethods, array_keys($criteriamethods));
    }

    /**
     * Generates the section headers based on the given criteria and module information.
     *
     * @return array An array of section headers that match the given criteria.
     */
    public function section_headers(): array {
        // Filter out repeated section values, keeping only the last occurrence.
        $modinfo = $this->modinfo;
        $sectionsarray = array_values(array_reduce($this->criteria, function ($acc, $criterion) use($modinfo) {
            if ($criterion->criteriatype == COMPLETION_CRITERIA_TYPE_ACTIVITY) {
                $activity = $modinfo->cms[$criterion->moduleinstance];
                $sectionname = get_section_name($activity->course, $activity->sectionnum);
                $acc[$sectionname] = [
                    'section' => $activity->section,
                    'sectionname' => $sectionname,
                ];
            }
            return $acc;
        }, []));

        // Count the occurrences of each "section".
        // Since we forced the former array to have unique values, we can now get the count of each "method" value.
        $sectioncounts = array_map(function($criterion) use($modinfo) {
            if ($criterion->criteriatype == COMPLETION_CRITERIA_TYPE_ACTIVITY) {
                $activity = $modinfo->cms[$criterion->moduleinstance];
                return [
                    'type' => get_section_name($activity->course, $activity->sectionnum),
                ];
            }
        }, $this->criteria);

        $sectioncountsarray = array_count_values(array_column($sectioncounts, 'type'));
        // Map the unique "section" values to include the colcount.
        // The colcount will be the number of times the "section" value appears in the $criteriamethods array.
        // This will allow us to span a column for each "section" value along the number of times it appears.
        return array_map(function($section) use ($sectioncountsarray): array {
            return [
                'sectionname' => $section['sectionname'],
                'colcount' => $sectioncountsarray[$section['sectionname']] == '' ? 1 : $sectioncountsarray[$section['sectionname']],
            ];
        }, array_values($sectionsarray));
    }

    /**
     * Generates and returns the icons representing the completion criteria for a given module.
     *
     * @return string HTML string containing the icons representing the completion criteria.
     */
    public function criteria_icons(): array {
        global $DB, $CFG, $OUTPUT;
        $criteriaicons = [];
        foreach ($this->criteria as $criterion) {
            // Generate icon details.
            $iconlink = '';
            $iconalt = ''; // Required.
            $iconattributes = ['class' => 'icon'];
            switch ($criterion->criteriatype) {
                case COMPLETION_CRITERIA_TYPE_ACTIVITY:
                    // Display icon.
                    $iconlink = $CFG->wwwroot.'/mod/'.$criterion->module.'/view.php?id='.$criterion->moduleinstance;
                    $iconattributes['title'] = $this->modinfo->cms[$criterion->moduleinstance]->get_formatted_name();
                    $iconalt = get_string('modulename', $criterion->module);
                break;

                case COMPLETION_CRITERIA_TYPE_COURSE:
                    // Load course.
                    $crs = $DB->get_record('course', ['id' => $criterion->courseinstance]);

                    // Display icon.
                    $iconlink = $CFG->wwwroot.'/course/view.php?id='.$criterion->courseinstance;
                    $iconattributes['title'] = format_string($crs->fullname, true,
                                                                ['context' => course::instance($crs->id, MUST_EXIST)]);
                    $iconalt = format_string($crs->shortname, true, ['context' => course::instance($crs->id)]);
                break;

                case COMPLETION_CRITERIA_TYPE_ROLE:
                    // Load role.
                    $role = $DB->get_record('role', ['id' => $criterion->role]);

                    // Display icon.
                    $iconalt = $role->name;
                break;
            }

            // Create icon alt if not supplied.
            if (!$iconalt) {
                $iconalt = $criterion->get_title();
            }

            $criteriaicons[] = [
                'icon' => $OUTPUT->render($criterion->get_icon($iconalt, $iconattributes)),
                'url' => $iconlink,
                'title' => $iconattributes['title'],
            ];
        }
        return $criteriaicons;
    }

    /**
     * Retrieves the titles of the criteria.
     *
     * @return array An array of criteria titles.
     */
    public function criteria_titles() {
        return array_map(function($criterion) {
            return $criterion->get_title_detailed();
        }, $this->criteria);
    }

    /**
     * Retrieves the results of users based on their progress, criteria, and completion status.
     *
     * @param array $progress An array containing the progress information of users.
     *
     * @return mixed The results of users based on the provided parameters.
     */
    public function get_users($progress) {
        global $OUTPUT;
        $completion = $this->completion;
        $dateformat = $this->dateformat;
        $modinfo = $this->modinfo;
        $course = $this->course;
        $format = $this->format;
        $criteria = $this->criteria;
        return array_map(function($user) use($criteria,
                                            $completion, $modinfo, $dateformat, $course, $OUTPUT, $format) {
            // Load course completion.
            $coursecompletion = new completion_completion(['userid' => $user->id, 'course' => $course->id]);
            $coursecompletiontype = $coursecompletion->is_complete() ? 'y' : 'n';

            $coursedescribe = get_string('completion-'.$coursecompletiontype, 'completion');
            $coursea = new \stdClass;
            $coursea->state    = $coursedescribe;
            $coursea->user     = fullname($user);
            $coursea->activity = strip_tags(get_string('coursecomplete', 'completion'));
            $coursefulldescribe = get_string('progress-title', 'completion', $coursea);

            return [
                'fullname' => fullname($user, has_capability('moodle/site:viewfullnames', $this->context)),
                'fields' => array_map(fn($field) => s($user->{$field}), $this->get_extrafields($course)),
                'criteria' => array_map(function($criterion) use($user, $completion, $modinfo, $dateformat, $OUTPUT, $format)  {
                    $criteriacompletion = $completion->get_user_completion($user->id, $criterion);
                    $iscomplete = $criteriacompletion->is_complete();
                    // Load activity.
                    $activity = $modinfo->cms[$criterion->moduleinstance];
                    $state = COMPLETION_INCOMPLETE;
                    if (array_key_exists($activity->id, $user->progress)) {
                        $state = $user->progress[$activity->id]->completionstate;
                    } else if ($iscomplete) {
                        $state = COMPLETION_COMPLETE;
                    }
                    $date = $iscomplete
                                ? userdate($criteriacompletion->timecompleted, $dateformat)
                                : '';
                    $completiontype = match ((int) $state) {
                        COMPLETION_INCOMPLETE => 'n',
                        COMPLETION_COMPLETE => 'y',
                        COMPLETION_COMPLETE_PASS => 'pass',
                        COMPLETION_COMPLETE_FAIL => 'fail',
                        default => throw new \UnexpectedValueException('Unexpected state value'),
                    };

                    $auto = $activity->completion == COMPLETION_TRACKING_AUTOMATIC;
                    $completionicon = 'completion-'.($auto ? 'auto' : 'manual').'-'.$completiontype;
                    $describe = get_string('completion-'.$completiontype, 'completion');

                    $a = new \stdClass();
                    $a->state = $describe;
                    $a->date = $date;
                    $a->user = fullname($user);
                    $a->activity = $activity->get_formatted_name();
                    $fulldescribe = get_string('progress-title', 'completion', $a);

                    $returnarray = ['date' => $date];

                    $returnarray['describe'] = $format == "pdf" ?
                                                ($completiontype == 'n' ? '6' : '3')
                                                : $OUTPUT->pix_icon('i/' . $completionicon, $fulldescribe);

                    return $returnarray;
                }, $criteria),
                'coursecomplete' => [
                    'date' => $coursecompletion->is_complete() ?
                        userdate($coursecompletion->timecompleted, $dateformat) : '',
                    'description' => ($format == "pdf")
                                        ? ($coursecompletion->is_complete() ? '3' : '6')
                                        : $OUTPUT->pix_icon('i/completion-auto-' . $coursecompletiontype, $coursefulldescribe),
                ],
            ];
        }, $progress);
    }
    /**
     * Generates a paging bar for course completion reports.
     *
     * @param object $course The course object.
     * @param string $sort The sorting criteria.
     * @param string $sifirst The first initial of the user's surname.
     * @param string $silast The last initial of the user's surname.
     * @param int $total The total number of items.
     * @param moodle_url $url The URL for the paging bar links.
     * @param int $start The starting index for the paging bar.
     *
     * @return string The HTML for the paging bar.
     */
    public function pagingbar(object $course, string $sort, string $sifirst,
                                string $silast, int $total, moodle_url $url, int $start): string {
        global $CFG, $OUTPUT;
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
        $pagingbar .= $OUTPUT->initials_bar($sifirst, 'firstinitial', get_string('firstname'), $prefixfirst, $url);
        $pagingbar .= $OUTPUT->initials_bar($silast, 'lastinitial', get_string('lastname'), $prefixlast, $url);

        // Do we need a paging bar?
        if ($total > COMPLETION_REPORT_PAGE) {
            // Paging bar.
            $pagingbar .= '<div class="paging">';
            $pagingbar .= get_string('page').': ';

            $sistrings = [];
            if ($sifirst != 'all') {
                $sistrings[] = "sifirst={$sifirst}";
            }
            if ($silast != 'all') {
                $sistrings[] = "silast={$silast}";
            }
            $sistring = !empty($sistrings) ? '&amp;'.implode('&amp;', $sistrings) : '';

            // Display previous link.
            if ($start > 0) {
                $pstart = max($start - COMPLETION_REPORT_PAGE, 0);
                $pagingbar .= "(<a class=\"previous\" href=\"{$link}{$pstart}{$sistring}\">".get_string('previous').'</a>)&nbsp;';
            }

            // Create page links.
            $curstart = 0;
            $curpage = 0;
            while ($curstart < $total) {
                $curpage++;

                if ($curstart == $start) {
                    $pagingbar .= '&nbsp;'.$curpage.'&nbsp;';
                } else {
                    $pagingbar .= "&nbsp;<a href=\"{$link}{$curstart}{$sistring}\">$curpage</a>&nbsp;";
                }

                $curstart += COMPLETION_REPORT_PAGE;
            }

            // Display next link.
            $nstart = $start + COMPLETION_REPORT_PAGE;
            if ($nstart < $total) {
                $pagingbar .= "&nbsp;(<a class=\"next\" href=\"{$link}{$nstart}{$sistring}\">".get_string('next').'</a>)';
            }

            $pagingbar .= '</div>';
        }
        return $pagingbar;
    }
}
