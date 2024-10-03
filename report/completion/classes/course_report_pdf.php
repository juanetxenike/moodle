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

use DOMDocument;
use pdf;

/**
 * Class course_report_pdf
 *
 * @package    report_completion
 * @subpackage completion
 * @copyright  2024 onwards WIDE Services {@link https://www.wideservices.gr}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_report_pdf extends pdf {
    /**
     * Constructs a new instance of the CourseReportPdf class.
     *
     * @param string $orientation The orientation of the PDF (default: 'L').
     * @param string $unit The unit of measurement for the PDF (default: 'mm').
     * @param string $format The format of the PDF (default: 'A4').
     */
    public function __construct($orientation = 'L', $unit = 'mm', $format = 'A4') {
        parent::__construct($orientation, $unit, $format);
        // Remove default header/footer.
        $this->setPrintHeader(false);
        $this->setPrintFooter(false);
        // Set auto page breaks.
        $this->SetAutoPageBreak(true, PDF_MARGIN_BOTTOM);
        // Add a new page.
        $this->AddPage();
        // Set common settings.
        $this->SetFillColor(230, 230, 230);
        $this->SetDrawColor(128, 128, 128);
        $this->SetLineWidth(0.1);

        $this->setFontSize(8);
        $this->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
    }
}
