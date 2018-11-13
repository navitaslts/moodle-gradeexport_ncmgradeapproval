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
 * Grader Report PDF for Approval.
 *
 * @package    gradeexport_ncmgradeapproval
 * @author     Nicolas Jourdain <nicolas.jourdain@navitas.com>
 * @copyright  2018 Nicolas Jourdain <nicolas.jourdain@navitas.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// namespace gradeexport_ncmgradeapproval;

defined('MOODLE_INTERNAL') || die;

require_once($CFG->dirroot.'/grade/export/lib.php');

class grade_export_pdf extends grade_export {

    public $plugin = 'ncmgradeapproval';
    public $updatedgradesonly = false; // Default to export ALL grades.

    private $finalgrades = array();
    private $finalgradescounter = 0;
    private $passrates = array();
    private $uniidfieldname = 'ncmuniid';

    /**
     * Constructor should set up all the private variables ready to be pulled
     * @param object $course
     * @param int $groupid id of selected group, 0 means all
     * @param stdClass $formdata The validated data from the grade export form.
     */
    public function __construct($course, $groupid, $formdata) {
        parent::__construct($course, $groupid, $formdata);
        $this->separator = $formdata->separator;

        // Overrides.
        $this->usercustomfields = true;
    }

    public function get_export_params() {
        $params = parent::get_export_params();
        $params['separator'] = $this->separator;
        return $params;
    }

    /**
     * To be implemented by child classes
     * @param boolean $feedback
     * @param boolean $publish Whether to output directly, or send as a file
     * @return string
     */
    public function print_grades($feedback = false) {
        global $CFG, $SITE, $DB;
        require_once($CFG->libdir.'/filelib.php');

        // Import the Moodle PDF lib.
        require_once($CFG->libdir.'/pdflib.php');

        $exporttracking = $this->track_exports();

        $strgrades = get_string('grades');
        $profilefields = grade_helper::get_user_profile_fields($this->course->id, $this->usercustomfields);

        // Calculate file name.
        $shortname = format_string($this->course->shortname, true, array('context' => context_course::instance($this->course->id)));
        $downloadfilename = clean_filename("$shortname $strgrades.pdf");

        make_temp_directory('gradeexport');
        $tempfilename = $CFG->tempdir .'/gradeexport/'. md5(sesskey().microtime().$downloadfilename);
        if (!$handle = fopen($tempfilename, 'w+b')) {
            print_error('cannotcreatetempdir');
        }

        $mypdf = new pdf();

        $mypdf->SetFont('helvetica', '', 10);
        $mypdf->SetFontSize('10');

        $createdat = date("d-m-Y H:i");

        $mypdf->SetHeaderData(
            PDF_HEADER_LOGO,
            PDF_HEADER_LOGO_WIDTH,
            'Grade Approval Report',
            'Created at '.$createdat,
            array(0, 64, 255),
            array(0, 64, 128));
        $mypdf->setFooterData(array(0, 64, 0), array(0, 64, 128));

        $mypdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
        $mypdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));

        // Set margins.
        $mypdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
        $mypdf->SetHeaderMargin(PDF_MARGIN_HEADER);
        $mypdf->SetFooterMargin(PDF_MARGIN_FOOTER);

        $mypdf->SetFillColor(0, 255, 0);
        $mypdf->SetAlpha(0.5);

        $mypdf->AddPage('L', 'A4');

        $mypdf->writeHTML($this->get_html_header(), true, false, true, false, '');
        $mypdf->writeHTML($this->get_html_title($SITE->shortname, $this->course), true, false, true, false, '');

        // Grade Table.
        $gradetable = array(
            // 'thead' => array('College ID', 'UNI ID', 'Student Name', 'Class'),
            'thead' => array('Username', 'Student Name', 'Class'),
            'tbody' => [],
        );

        // Set default header data.

        // Set header and footer fonts.

        // Build Table Header.
        $theader = array(
            'studentid'
        );
        // Get the number of assignement => determine the number of columns in the table.
        // List of Assignements.
        $listgrades = array();

        // List of Groups in the course.
        $coursegroups = array();

        $geub = new grade_export_update_buffer();
        $gui = new graded_users_iterator($this->course, $this->columns, $this->groupid);
        $gui->require_active_enrolment($this->onlyactive);
        $gui->allow_user_custom_fields($this->usercustomfields);
        $gui->init();

        $this->displaytype = array(GRADE_DISPLAY_TYPE_LETTER, GRADE_DISPLAY_TYPE_REAL, GRADE_DISPLAY_TYPE_PERCENTAGE);

        $studentcount = 0;

        while ($userdata = $gui->next_user()) {

            $studentcount++;
            $row = array();

            $myhtml = "";
            $user = $userdata->user;

            profile_load_custom_fields($user);

            $usergroupsres = groups_get_user_groups($this->course->id, $user->id);
            $usergroups = $usergroupsres['0'];

            $groupname = "";
            foreach ($usergroups as $usergroup) {

                if (!array_key_exists($usergroup, $coursegroups)) {
                    // Get the group name.
                    $groupname = groups_get_group_name($usergroup);
                    $coursegroups[$usergroup] = $groupname;
                } else {
                    $groupname = $coursegroups[$usergroup];
                }
            }

            // echo "<pre>";
            // var_dump($user);
            // echo "</pre>";
            $myuser = array(
                'studentid' => $user->id,
                'username' => $user->username,
                'studentname' => $user->firstname . ' ' . $user->lastname,
                'class' => $groupname,
                'uniid' => isset($userobj->profile[$this->uniidfieldname]) ? $userobj->profile[$this->uniidfieldname] : '' ,
            );

            $mygrades = array();
            foreach ($userdata->grades as $itemid => $grade) {

                $mygrade = array();
                $mygrade['itemid'] = $itemid;

                $gradeitem = $this->grade_items[$itemid];
                $grade->gradeitem =& $gradeitem;

                $listgrades[$itemid] = array(
                    'itemid' => $itemid,
                    'itemname' => ($gradeitem->itemtype == 'course') ? 'Total' : $gradeitem->itemname,
                    'itemtype' => $gradeitem->itemtype,
                    'grademax' => $gradeitem->grademax,
                    'gradepass' => $gradeitem->gradepass,
                    'multfactor' => $gradeitem->multfactor,
                    'weight' => $gradeitem->grademax * $gradeitem->multfactor,
                    'order' => ($gradeitem->itemtype == 'course') ? 9 : 1,
                );

                // MDL-11669, skip exported grades or bad grades (if setting says so).
                if ($exporttracking) {
                    $status = $geub->track($grade);
                    if ($this->updatedgradesonly && ($status == 'nochange' || $status == 'unknown')) {
                        continue;
                    }
                }

                if ($exporttracking) {
                    $myhtml .= "<p>State: {$status}</p>";
                }

                // This column should be customizable to use either student id, idnumber, username or email.
                $myhtml .= "<p>Student: {$user->idnumber}</p>";
                // Format and display the grade in the selected display type (real, letter, percentage).

                if (is_array($this->displaytype)) {
                    // Grades display type came from the return of export_bulk_export_data() on grade publishing.
                    foreach ($this->displaytype as $gradedisplayconst) {
                        $gradestr = $this->format_grade($grade, $gradedisplayconst);
                        if ($gradedisplayconst == GRADE_DISPLAY_TYPE_LETTER) {
                            $mygrade['score'][GRADE_DISPLAY_TYPE_LETTER] = $gradestr;
                        }
                        if ($gradedisplayconst == GRADE_DISPLAY_TYPE_REAL) {
                            $mygrade['score'][GRADE_DISPLAY_TYPE_REAL] = $gradestr;
                        }
                        if ($gradedisplayconst == GRADE_DISPLAY_TYPE_PERCENTAGE) {
                            $mygrade['score'][GRADE_DISPLAY_TYPE_PERCENTAGE] = $gradestr;
                        }
                    }
                } else {
                    // Grade display type submitted directly from the grade export form.
                    $gradestr = $this->format_grade($grade, $this->displaytype);
                    $mygrade['score'][GRADE_DISPLAY_TYPE_REAL] = $gradestr;

                    $gradeletterstr = $this->format_grade($grade, GRADE_DISPLAY_TYPE_LETTER);
                    $mygrade['score'][GRADE_DISPLAY_TYPE_LETTER] = $gradeletterstr;

                    $gradeletterstr = $this->format_grade($grade, GRADE_DISPLAY_TYPE_PERCENTAGE);
                    $mygrade['score'][GRADE_DISPLAY_TYPE_PERCENTAGE] = $gradestr;

                }
                // Feedback is not required.
                if ($this->export_feedback && 1 == 2) {
                    $feedbackstr = $this->format_feedback($userdata->feedbacks[$itemid]);
                }
                $myhtml .= "</div><hr>";
                $mygrade['finalgrade'] = $grade->finalgrade;
                $mygrade['rawgrademax'] = $grade->rawgrademax;
                $mygrades[$itemid] = $mygrade;
            }

            $gradetable['tbody'][] = array(
                'user' => $myuser,
                'grades' => $mygrades
            );
        }
        // Reorder list of grades.
        // The Grade for the course must be in last position.
        // Itemtype / Itemid.
        usort ($listgrades, function ($a, $b) {
            if ($a['order'] == $b['order'] && $a['itemid'] == $b['itemid']) {
                return 0;
            }
            if ($a['order'] == $b['order']) {
                if ($a['itemid'] > $b['itemid']) {
                    return 1;
                } else {
                    return -1;
                }
            }
            if ($a['order'] > $b['order']) {
                return 1;
            } else {
                return 0;
            }
        });

        $gradetable['listgrades'] = $listgrades;
        $gradetablehtml = $this->get_html_grade_table($gradetable);
        $mypdf->SetFillColor(255, 255, 255);
        // Write Grade Tables.
        $mypdf->writeHTML($gradetablehtml, true, false, true, false, '');

        // Number of Students.
        $myhtml = "<p>Number of Students: {$studentcount}</p>";
        $mypdf->writeHTML($myhtml, true, false, true, false, '');

        // Passrate.
        $passrate = $this->get_html_passrate();
        $mypdf->writeHTML($passrate, true, false, true, false, '');

        // Grade distribution.
        $gradedistribution = $this->get_html_grade_summary();
        $mypdf->writeHTML($gradedistribution, true, false, true, false, '');

        // Signature.
        $signature = $this->get_html_signature();
        $mypdf->writeHTML($signature, true, false, true, false, '');

        $gui->close();
        $geub->close();

        $mypdf->Output($downloadfilename, 'D');

        if (defined('BEHAT_SITE_RUNNING')) {
            // If behat is running, we cannot test the output if we force a file download.
            include($tempfilename);
        } else {
            @header("Content-type: text/xml; charset=UTF-8");
            send_temp_file($tempfilename, $downloadfilename, false);
        }
    }

    private function get_html_header() {
        $html = "<div style=\"text-align:center\"><h1>Grade Approval Report</h1></div>";
        return $html;
    }

    private function get_html_title($college, $course) {
        $html = "<div style=\"text-align:center\"><h2>{$college}<br/>{$course->shortname}: {$course->fullname}</h2></div>";
        return $html;
    }

    private function get_html_grade_table_start() {
        $html = "<table id=\"grade_report\" width=\"100%\" cellpadding=\"4\" cellspacing=\"0\">";
        return $html;
    }

    private function get_html_grade_table_end() {
        $html = "</table>";
        return $html;
    }

    private function get_html_grade_table($gradetable) {
        // T-head.
        $thead = $this->get_html_grade_table_header($gradetable['thead'], $gradetable['listgrades']);

        // T-body.
        $tbody = $this->get_html_grade_table_body($gradetable['tbody'], $gradetable['listgrades']);

        $table = $this->get_html_grade_table_start()
            . $thead
            . $tbody
            . $this->get_html_grade_table_end();
        return $table;
    }

    private function get_html_grade_table_header($theader, $listgrades) {
        $html = "<thead><tr bgcolor=\"#ddeaff\">";
        $i = 0;

        // echo "<pre>";
        // var_dump($theader);
        // echo "</pre>";
        // Add Student ID, UNI ID, Student Name.
        foreach ($theader as $column) {
            $html .= "<th><b>{$column}</b></th>";
            $i++;
        }
        // Add columns, 1 column per grade item.
        foreach ($listgrades as $listgrade) {

            $text = $listgrade['itemname'];
            if ($listgrade['itemtype'] != 'course') {
                $text = $listgrade['itemname']
                    . "<div style=\"font-size: small;\">Max:".floatval($listgrade['grademax'])."<br/>"
                    . "Factor:".floatval($listgrade['multfactor'])."</div>";
            }
            $html .= "<th><b>{$text}</b></th>";
            $i++;
        }
        // Grade letter column.
        $html .= "<th><b>Grd</b></th>";
        // Percentage Grade column.
        $html .= "<th><b>%</b></th>";
        // Close table.
        $html .= "</tr></thead>";
        return $html;
    }

    private function get_html_grade_table_body($tbody, $listgrades) {
        $html = "<tbody>";

        $i = 0;
        foreach ($tbody as $data) {
            // User data.
            $i++;
            $color1 = "#F0F0F0";
            $color2 = "#FFFFFF";
            ($i % 2 == 0) ? $color = $color2 : $color = $color1;

            $html .= "<tr bgcolor=\"$color\">"
                // ."<td>{$data['user']['studentid']}</td>"
                ."<td>{$data['user']['username']}</td>"
                // ."<td>{$data['user']['uniid']}</td>"
                ."<td>{$data['user']['studentname']}</td>"
                ."<td>{$data['user']['class']}</td>";
            // Grade data.
            foreach ($listgrades as $listgrade) {
                $itemid = $listgrade['itemid'];
                $html .= "<td>{$data['grades'][$itemid]['score'][GRADE_DISPLAY_TYPE_REAL]}</td>";

                if ($listgrade['itemtype'] == 'course') {
                    $html .= "<td>{$data['grades'][$itemid]['score'][GRADE_DISPLAY_TYPE_LETTER]}</td>";
                    $html .= "<td>{$data['grades'][$itemid]['score'][GRADE_DISPLAY_TYPE_PERCENTAGE]}</td>";
                    // Populate for Grade Distribution.
                    $this->add_final_grade($data['grades'][$itemid]['score'][GRADE_DISPLAY_TYPE_LETTER]);
                    // Populate for Passrate.
                    $this->add_passrate($data['user']['studentid'], $data['grades'][$itemid], $listgrade);
                }
            }
            $html .= "</tr>";
        }
        $html .= "</tbody>";
        return $html;
    }

    private function get_html_passrate() {
        $counterpass = 0;
        $countertotal = 0;

        foreach ($this->passrates as $passrate) {
            if ($passrate) {
                $counterpass++;
            }
            $countertotal++;
        }

        $pourcentage = $counterpass * 100 / $countertotal;

        $html = "<div style=\"text-align:right\" width='100%'>";
        $html .= "Passrate = ".number_format($pourcentage, 1)."%";
        $html .= "</div>";
        return $html;
    }

    private function get_html_grade_summary () {

        $grades = $this->finalgrades;

        uksort($grades, function($a, $b) {
            $splita = str_split($a);
            $splitb = str_split($b);

            $lettera = $splita[0];
            $letterb = $splitb[0];

            if ($lettera !== $letterb) {
                return strcmp($lettera, $lettera) * -1;
            } else {
                $signa = (isset($splita[1])) ? $splita[1] : "";
                $signb = (isset($splitb[1])) ? $splitb[1] : "";
                return strcmp($signa, $signb);
            }
        });

        $html = "<div style=\"text-align:right\" width='100%'>";
        $html .= "<span><b>Grade Distribution</b></span>";
        foreach ($grades as $grade => $count) {
            $pourcentage = $count * 100 / $this->finalgradescounter;
            $html .= "<br/><span><b>{$grade}:</b>&nbsp;{$count} (".number_format($pourcentage, 1)."%)</span>";
        }
        $html .= "</div>";
        return $html;
    }

    private function get_html_signature() {

        global $USER;

        $printbyname = rtrim($USER->firstname . " " . $USER->lastname);

        $html = "<table width='100%' border='1px'>";
        $html .= "<tr>";
        $html .= "<td width='35%'>Printed by: ".$printbyname."</td>";
        $html .= "<td width='35%'>Signature: _______________</td>";
        $html .= "<td width='30%'>Date: ".date('d M  Y')."</td>";
        $html .= "</tr>";
        $html .= "</table>";
        return $html;
    }

    private function add_final_grade($grade) {
        if (array_key_exists($grade, $this->finalgrades)) {
            $this->finalgrades[$grade] = $this->finalgrades[$grade] + 1;
        } else {
            $this->finalgrades[$grade] = 1;
        }
        $this->finalgradescounter = $this->finalgradescounter + 1;
    }

    private function add_passrate($studentid, $grade, $gradeitem) {
        // Calculation to determine if the student pass or not.
        $grademax = $gradeitem['grademax'];
        $gradepass = $gradeitem['gradepass'];

        $studentrawgrademax = $grade['rawgrademax'];
        $studentfinalgrade = $grade['finalgrade'];

        $newfinalgrade = $studentfinalgrade * $grademax / $studentrawgrademax;

        $pass = ($newfinalgrade >= $gradepass) ? true : false;
        $this->passrates[$studentid] = $pass;
    }
}


