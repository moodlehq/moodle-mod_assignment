<?PHP // $Id$

    require_once("../../config.php");
    require_once("lib.php");

    require_variable($id);   // course

    if (! $course = get_record("course", "id", $id)) {
        error("Course ID is incorrect");
    }

    require_login($course->id);
    add_to_log($course->id, "assignment", "view all", "index.php?id=$course->id", "");

    if ($course->category) {
        $navigation = "<A HREF=\"../../course/view.php?id=$course->id\">$course->shortname</A> ->";
    }

    $strassignments = get_string("modulenameplural", "assignment");
    $strassignment = get_string("modulename", "assignment");
    $strweek = get_string("week");
    $strtopic = get_string("topic");
    $strname = get_string("name");
    $strduedate = get_string("duedate", "assignment");
    $strsubmitted = get_string("submitted", "assignment");


    print_header("$course->shortname: $strassignments", "$course->fullname", "$navigation $strassignments", "", "", true, "", navmenu($course));

    if (! $assignments = get_all_instances_in_course("assignment", $course->id, "cw.section ASC")) {
        notice("There are no assignments", "../../course/view.php?id=$course->id");
        die;
    }

    $timenow = time();

    if ($course->format == "weeks") {
        $table->head  = array ($strweek, $strname, $strduedate, $strsubmitted);
        $table->align = array ("CENTER", "LEFT", "LEFT", "LEFT");
    } else if ($course->format == "topics") {
        $table->head  = array ($strtopic, $strname, $strduedate, $strsubmitted);
        $table->align = array ("CENTER", "LEFT", "LEFT", "LEFT");
    } else {
        $table->head  = array ($strname, $strduedate, $strsubmitted);
        $table->align = array ("LEFT", "LEFT", "LEFT");
    }

    foreach ($assignments as $assignment) {
        if ($submission = assignment_get_submission($assignment, $USER)) {
            if ($submission->timemodified <= $assignment->timedue) {
                $submitted = userdate($submission->timemodified);
            } else {
                $submitted = "<FONT COLOR=red>".userdate($submission->timemodified)."</FONT>";
            }
        } else {
            $submitted = get_string("no");
        }
        $due = userdate($assignment->timedue);
        $link = "<A HREF=\"view.php?id=$assignment->coursemodule\">$assignment->name</A>";
        if ($assignment->section) {
            $section = "$assignment->section";
        } else {
            $section = "";
        }

        if ($course->format == "weeks" or $course->format == "topics") {
            $table->data[] = array ($section, $link, $due, $submitted);
        } else {
            $table->data[] = array ($link, $due, $submitted);
        }
    }

    echo "<BR>";

    print_table($table);

    print_footer($course);
?>
