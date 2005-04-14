<?php // $Id$
      // This script prints the setup screen for any assignment
      // It does this by calling the setup method in the appropriate class

	require_once("../../config.php");
	require_once("lib.php");

	if (!$form = data_submitted()) {
        error("This script was called wrongly");
    }

    if (!$course = get_record('course', 'id', $form->course)) {
        error("Non-existent course!");
    }

    require_login($course->id);

    if (!isteacheredit($course->id)) {
        redirect($CFG->wwwroot.'/course/view.php?id='.$course->id);
    }

/// Set up things for a HTML editor if it's needed

    if ($usehtmleditor = can_use_html_editor()) {
        $defaultformat = FORMAT_HTML;
        $editorfields = '';
    } else {
        $defaultformat = FORMAT_MOODLE;
    }

	require_once("$CFG->dirroot/mod/assignment/type/$form->assignmenttype/assignment.class.php");

	$assignmentclass = "assignment_$form->assignmenttype";

	$assignmentinstance = new $assignmentclass();

	echo $assignmentinstance->setup($form);     /// The actual form is all printed here


?>
