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

namespace report_progress\output;

use completion_info;
use core\context\course;
use core\output\renderable;
use core\output\renderer_base;
use core\output\templatable;
use core\url as moodle_url;
use core_user\fields;
use report_completion\engine as CompletionEngine;
use report_progress\engine as ProgressEngine;
use report_progress\local\helper;

/**
 * Class report
 *
 * @package    report_progress
 * @copyright  2024 onwards WIDE Services {@link https://www.wideservices.gr}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report implements renderable, templatable {

    /**
     * @var int $courseid The ID of the course associated with the progress report.
     */
    private $courseid;
    /**
     * @var object $course The course object associated with the progress report.
     */
    private $course;
    /**
     * @var string $format The format in which the report will be generated.
     */
    private $format;
    /**
     * @var mixed $activityinclude This variable is used to include specific activities in the report.
     */
    private $activityinclude;
    /**
     * @var string $activityorder An array that holds the order of activities for the progress report.
     */
    private $activityorder;
    /**
     * @var mixed $activitysection Stores the activity section information.
     */
    private $activitysection;
    /**
     * @var mixed $totalheader Holds the total header information for the report.
     */
    private $totalheader;

    /**
     * @var array $fieldsarray An array to store the fields for the report.
     */
    private $fieldsarray;
    /**
     * @var array $formattedactivities An array to store formatted activities for the progress report.
     */
    private $formattedactivities;

    /**
     * @var array $activitytypes An array to store different types of activities.
     */
    private $activitytypes;
    /**
     * @var array $activities List of activities to be included in the progress report.
     */
    private $activities;
    /**
     * @var mixed $completion Stores the completion status or data for the report.
     */
    private $completion;
    /**
     * @var mixed $progress The progress data for the report.
     */
    private $progress;
    /**
     * @var course $context The context in which the report is being generated.
     */
    private $context;
    /**
     * @var array $extrafields An array to store additional fields for the report.
     */
    private $extrafields;
    /**
     * Constructor for the report class.
     *
     * Initializes the report object with necessary dependencies and configurations.
     *
     * @param int $courseid Description of the first dependency.
     * @param string $format Description of the second dependency.
     * @param string $activityinclude Description of the third dependency.
     * @param string $activityorder
     * @param int $activitysection
     * @param int $totalheader
     * @param array $progress
     */
    public function __construct(
        int $courseid,
        string $format,
        string $activityinclude,
        string $activityorder,
        int $activitysection,
        int $totalheader,
        array $progress) {
        global $DB;
        $this->courseid = $courseid;
        $this->course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $this->totalheader = $totalheader;
        $this->format = $format;
        $this->fieldsarray = (new CompletionEngine($this->courseid, $this->format))->fieldsarray();
        $this->completion = new completion_info($this->course);
        $this->activityinclude = $activityinclude;
        $this->activityorder = $activityorder;
        $this->activitysection = $activitysection;
        list($this->activitytypes, $this->activities) = helper::get_activities_to_show(
            $this->completion,
            $this->activityinclude,
            $this->activityorder,
            $this->activitysection);
        $this->formattedactivities = (new ProgressEngine())->formatted_activities($this->activities);
        $this->progress = $progress;
        $this->context = course::instance($courseid);
        $this->extrafields = (new ProgressEngine())->extrafields($this->context);
    }
    /**
     * Display the completion report
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $progressengine = new ProgressEngine();
        return [
            'title' => get_string('pluginname', 'report_progress'),
            'totalparticipants' => get_string('allparticipants').": {$this->totalheader}",
            'fields' => $this->fieldsarray,
            'formattedactivities' => array_values($this->formattedactivities),
            'users' => array_values(
                $progressengine->users_progress(
                    $this->progress,
                    $this->context,
                    $this->extrafields,
                    $this->activities,
                    $this->format)
                ),
            'sectionheaders' => (array) $progressengine->section_headers($this->activities),
            'activityicons' => $progressengine->activity_icons($this->activities, $this->format),
            'ishtml' => ($this->format != 'csv' && $this->format != 'pdf' && $this->format != 'excelcsv') ? true : false,
            'csvurl' => (new moodle_url('/report/progress/index.php', ['course' => $this->courseid, 'format' => 'csv']))->out(),
            'excelurl' => new moodle_url('/report/progress/index.php', ['course' => $this->courseid, 'format' => 'excelcsv']),
            'pdfurl' => new moodle_url('/report/progress/index.php', ['course' => $this->courseid, 'format' => 'pdf']),
        ];
    }
}
