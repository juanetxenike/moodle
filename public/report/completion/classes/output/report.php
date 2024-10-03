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

namespace report_completion\output;

use completion_info;
use core\context\course;
use core\output\renderer_base;
use report_completion\engine;

/**
 * Class report
 *
 * @package    report_completion
 * @copyright  2024 onwards WIDE Services {@link https://www.wideservices.gr}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report implements \renderable, \templatable {

    /**
     * @var int $courseid The ID of the course for which the report is generated.
     */
    private $courseid;
    /**
     * @var string $format The format in which the report will be generated.
     */
    private $format;
    /**
     * @var context $context The context in which the report is being generated.
     */
    private $context;
    /**
     * @var int $totalparticipants The total number of participants in the report.
     */
    private $totalparticipants;
    /**
     * @var array $leftcols An array holding the left columns for the report.
     */
    private $leftcols;
    /**
     * @var mixed $criteria The criteria used for generating the completion report.
     */
    private $criteria;
    /**
     * @var array $criteriamethodheaders An array containing the headers for the criteria methods in the report.
     */
    private $criteriamethodheaders;
    /**
     * @var mixed $completion Stores the completion data for the report.
     */
    private $completion;
    /**
     * @var array $hasagg Indicates whether the report has aggregate data.
     */
    private $hasagg;
    /**
     * @var object $modinfo Contains information about the modules in the course.
     */
    private $modinfo;
    /**
     * @var mixed $engine The engine instance used for generating the report.
     */
    private $engine;
    /**
     * @var mixed $progress The progress of the report.
     */
    private $progress;

    /**
     * @var object $course The course object associated with the report.
     */
    private $course;
    /**
     * Constructor
     *
     * @param int $courseid
     * @param string $format
     * @param int $totalparticipants
     * @param int $leftcols
     * @param mixed $completion
     * @param mixed $progress
     */
    public function __construct(
            int $courseid,
            string $format,
            int $totalparticipants,
            int $leftcols,
            $completion,
            $progress) {
        $this->courseid = $courseid;
        $this->format = $format;
        $this->context = course::instance($courseid);
        $this->totalparticipants = $totalparticipants;
        $this->leftcols = $leftcols;
        $this->completion = $completion;
        $this->engine = new engine($courseid, $this->format);
        $this->progress = $progress;
        global $DB;
        $this->course = $DB->get_record('course', ['id' => $this->courseid], '*', MUST_EXIST);
        $this->completion = new completion_info($this->course);
        $this->criteria = array_merge(
            $this->completion->get_criteria(COMPLETION_CRITERIA_TYPE_COURSE),
            $completion->get_criteria(COMPLETION_CRITERIA_TYPE_ACTIVITY),
            array_filter(
                $this->completion->get_criteria(),
                fn($criterion) => !in_array($criterion->criteriatype, [
                    COMPLETION_CRITERIA_TYPE_COURSE, COMPLETION_CRITERIA_TYPE_ACTIVITY,
                ])
            )
        );
        $this->modinfo = get_fast_modinfo($this->course);
    }

    /**
     * Display the completion report
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {
        global $OUTPUT;
        return [
            'title' => get_string('coursecompletion'),
            'totalparticipants' => get_string('allparticipants'). ": {$this->totalparticipants}",
            'leftcols' => $this->leftcols == '' ? 1 : $this->leftcols,
            'criteriaheaders' => $this->engine->criteria_types(),
            'criteriamethodheaders' => $this->engine->criteria_methods(),
            'sectionheaders' => $this->engine->section_headers(),
            'criteriaicons' => $this->engine->criteria_icons($this->criteria),
            'courseaggregationheader' => $this->completion->get_aggregation_method() == 1 ?
                                            get_string('all') : get_string('any'),
            'fields' => $this->engine->fieldsarray(),
            'criteria' => $this->engine->criteria_titles(),
            'users' => array_values($this->engine->get_users($this->progress)),
            'ishtml' => ($this->format != 'csv' && $this->format != 'pdf' && $this->format != 'excelcsv') ? true : false,
            'csvurl' => (new \moodle_url('/report/completion/index.php', ['course' => $this->courseid, 'format' => 'csv']))->out(),
            'excelurl' => new \moodle_url('/report/completion/index.php', ['course' => $this->courseid, 'format' => 'excelcsv']),
            'pdfurl' => new \moodle_url('/report/completion/index.php', ['course' => $this->courseid, 'format' => 'pdf']),
            'coursecompleteicon' => ($this->format != 'csv' && $this->format != 'pdf' && $this->format != 'excelcsv')
                                        ? $OUTPUT->pix_icon('i/course', get_string('coursecomplete', 'completion'))
                                        : get_string('coursecomplete', 'completion'),
        ];
    }
}
