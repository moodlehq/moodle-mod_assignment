<?php  // $Id$

    require_once("../../config.php");
    require_once("lib.php");

    $id   = optional_param('id', 0, PARAM_INT);          // Course module ID
    $a    = optional_param('a', 0, PARAM_INT);           // Assignment ID
    $mode = optional_param('mode', 'all', PARAM_ALPHA);  // What mode are we in?

    if ($id) {
        if (! $cm = get_coursemodule_from_id('assignment', $id)) {
            print_error('invalidcoursemodule');
        }

        if (! $assignment = get_record("assignment", "id", $cm->instance)) {
            print_error('invalidid', 'assignment');
        }

        if (! $course = get_record("course", "id", $assignment->course)) {
            print_error('coursemisconf', 'assignment');
        }
    } else {
        if (!$assignment = get_record("assignment", "id", $a)) {
            print_error('invalidcoursemodule');
        }
        if (! $course = get_record("course", "id", $assignment->course)) {
            print_error('coursemisconf', 'assignment');
        }
        if (! $cm = get_coursemodule_from_instance("assignment", $assignment->id, $course->id)) {
            print_error('invalidcoursemodule');
        }
    }

    require_login($course->id, false, $cm);

    require_capability('mod/assignment:grade', get_context_instance(CONTEXT_MODULE, $cm->id));

/// Load up the required assignment code
    require($CFG->dirroot.'/mod/assignment/type/'.$assignment->assignmenttype.'/assignment.class.php');
    $assignmentclass = 'assignment_'.$assignment->assignmenttype;
    $assignmentinstance = new $assignmentclass($cm->id, $assignment, $cm, $course);

    $assignmentinstance->submissions($mode);   // Display or process the submissions

?>
