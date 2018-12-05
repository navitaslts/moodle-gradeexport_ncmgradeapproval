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

require_once('../../../config.php');
require_once($CFG->dirroot.'/grade/export/lib.php');
require_once('grade_export_pdf.php');

$id = required_param('id', PARAM_INT); // Course id.

$PAGE->set_url('/grade/export/ncmgradeapproval/index.php', array('id' => $id));

if (!$course = $DB->get_record('course', array('id' => $id))) {
    print_error('invalidcourseid');
}

require_login($course);
$context = context_course::instance($id);

require_capability('moodle/grade:export', $context);
require_capability('gradeexport/ncmgradeapproval:view', $context);

print_grade_page_head(
    $COURSE->id,
    'export',
    'ncmgradeapproval',
    get_string('exportto', 'grades') . ' ' . get_string('pluginname', 'gradeexport_ncmgradeapproval'));
export_verify_grades($COURSE->id);

if (!empty($CFG->gradepublishing)) {
    $CFG->gradepublishing = has_capability('gradeexport/ncmgradeapproval:publish', $context);
}

$actionurl = new moodle_url('/grade/export/ncmgradeapproval/export.php');
$formoptions = array(
    'includeseparator' => true,
    'publishing' => true,
    'simpleui' => true,
    'multipledisplaytypes' => true
);

$mform = new grade_export_form($actionurl, $formoptions);

$groupmode    = groups_get_course_groupmode($course);   // Groups are being used.
$currentgroup = groups_get_course_group($course, true);
if (($groupmode == SEPARATEGROUPS) &&
        (!$currentgroup) &&
        (!has_capability('moodle/site:accessallgroups', $context))) {

    echo $OUTPUT->heading(get_string("notingroup"));
    echo $OUTPUT->footer();
    die;
}

// Cannot export if the gradebook is not locked.
$gradeitem = $DB->get_record('grade_items', array('courseid' => $course->id, 'itemtype' => 'course'));
// Test disabled.
if ( 1 == 2 && $gradeitem->locked == 0 || $gradeitem->locked == null || $gradeitem->locked > time() ) {
    echo $OUTPUT->notification(get_string('gradebooknoexport', 'gradeexport_ncmgradeapproval'), 'notifyproblem');
    echo $OUTPUT->footer();
    die;
}

groups_print_course_menu($course, 'index.php?id='.$id);
echo '<div class="clearer"></div>';

$mform->display();

echo $OUTPUT->footer();

