<?PHP  // $Id$

    require("../../config.php");
    require("lib.php");

    require_variable($id);          // Assignment ID

    $newfile = $HTTP_POST_FILES["newfile"];

    if (! $assignment = get_record("assignment", "id", $id)) {
        error("Not a valid assignment ID");
    }

    if (! $course = get_record("course", "id", $assignment->course)) {
        error("Course is misconfigured");
    }

    require_login($course->id);

    add_to_log($course->id, "assignment", "upload", "view.php?a=$assignment->id", "$assignment->id");

    if ($course->category) {
        $navigation = "<A HREF=\"../../course/view.php?id=$course->id\">$course->shortname</A> ->";
    }
    $strassignments = get_string("modulenameplural", "assignment");
    $strassignment  = get_string("modulename", "assignment");
    $strupload      = get_string("upload");

    print_header("$course->shortname: $assignment->name : $strupload", "$course->fullname",
                 "$navigation <A HREF=index.php?id=$course->id>$strassignments</A> -> 
                  <A HREF=\"view.php?a=$assignment->id\">$assignment->name</A> -> $strupload", 
                  "", "", true);

    if ($submission = assignment_get_submission($assignment, $USER)) {
        if ($submission->grade and !$assignment->resubmit) {
            error("You've already been graded - there's no point in uploading anything");
        }
    }

    if (! $dir = assignment_file_area($assignment, $USER)) {
        error("Sorry, an error in the system prevents you from uploading files: contact your teacher or system administrator");
    }

    if (is_uploaded_file($newfile['tmp_name']) and $newfile['size'] > 0) {
        if ($newfile['size'] > $assignment->maxbytes) {
            notify(get_string("uploadfiletoobig", "assignment", $assignment->maxbytes));
        } else {
            $newfile_name = clean_filename($newfile['name']);
            if ($newfile_name) {
                if (move_uploaded_file($newfile['tmp_name'], "$dir/$newfile_name")) {
                    assignment_delete_user_files($assignment, $USER, $newfile_name);
                    if ($submission) {
                        $submission->timemodified = time();
                        $submission->numfiles     = 1;
                        if (update_record("assignment_submissions", $submission)) {
                            print_heading(get_string("uploadsuccess", "assignment", $newfile_name) );
                        } else {
                            notify(get_string("uploadfailnoupdate", "assignment"));
                        }
                    } else {
                        $newsubmission->assignment   = $assignment->id;
                        $newsubmission->user         = $USER->id;
                        $newsubmission->timecreated  = time();
                        $newsubmission->timemodified = time();
                        $newsubmission->numfiles     = 1;
                        if (insert_record("assignment_submissions", $newsubmission)) {
                            print_heading(get_string("uploadsuccess", "assignment", $newfile_name) );
                        } else {
                            notify(get_string("uploadnotregistered", "assignment", $newfile_name) );
                        }
                    }
                } else {
                    notify(get_string("uploaderror", "assignment") );
                }
            } else {
                notify(get_string("uploadbadname", "assignment") );
            }
        }
    } else {
        notify(get_string("uploadnofilefound", "assignment") );
    }
    
    print_continue("view.php?a=$assignment->id");

    print_footer($course);

?>
