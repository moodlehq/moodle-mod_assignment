<?php  // $Id$

require_once("$CFG->dirroot/files/mimetypes.php");

define("OFFLINE",      "0");
define("UPLOADSINGLE", "1");

$ASSIGNMENT_TYPE = array (OFFLINE       => get_string("typeoffline",      "assignment"),
                          UPLOADSINGLE  => get_string("typeuploadsingle", "assignment") );

if (!isset($CFG->assignment_maxbytes)) {
    set_config("assignment_maxbytes", 1024000);  // Default maximum size for all assignments
} 


function assignment_add_instance($assignment) {
// Given an object containing all the necessary data, 
// (defined by the form in mod.html) this function 
// will create a new instance and return the id number 
// of the new instance.

    $assignment->timemodified = time();
    
    $assignment->timedue = make_timestamp($assignment->dueyear, $assignment->duemonth, $assignment->dueday, 
                                          $assignment->duehour, $assignment->dueminute);

    if ($returnid = insert_record("assignment", $assignment)) {

        $event = NULL;
        $event->name        = $assignment->name;
        $event->description = $assignment->description;
        $event->courseid    = $assignment->course;
        $event->groupid     = 0;
        $event->userid      = 0;
        $event->modulename  = 'assignment';
        $event->instance    = $returnid;
        $event->eventtype   = 'due';
        $event->timestart   = $assignment->timedue;
        $event->timeduration = 0;

        add_event($event);
    }

    return $returnid;
}


function assignment_update_instance($assignment) {
// Given an object containing all the necessary data, 
// (defined by the form in mod.html) this function 
// will update an existing instance with new data.

    $assignment->timemodified = time();
    $assignment->timedue = make_timestamp($assignment->dueyear, $assignment->duemonth, $assignment->dueday, 
                                          $assignment->duehour, $assignment->dueminute);
    $assignment->id = $assignment->instance;


    if ($returnid = update_record("assignment", $assignment)) {

        $event = NULL;

        if ($event->id = get_field('event', 'id', 'modulename', 'assignment', 'instance', $assignment->id)) {

            $event->name        = $assignment->name;
            $event->description = $assignment->description;
            $event->timestart   = $assignment->timedue;

            update_event($event);
        }
    }

    return $returnid;
}


function assignment_delete_instance($id) {
// Given an ID of an instance of this module, 
// this function will permanently delete the instance 
// and any data that depends on it.  

    if (! $assignment = get_record("assignment", "id", "$id")) {
        return false;
    }

    $result = true;

    if (! delete_records("assignment_submissions", "assignment", "$assignment->id")) {
        $result = false;
    }

    if (! delete_records("assignment", "id", "$assignment->id")) {
        $result = false;
    }

    if (! delete_records('event', 'modulename', 'assignment', 'instance', $assignment->id)) {
        $result = false;
    }

    return $result;
}

function assignment_refresh_events($courseid = 0) {
// This standard function will check all instances of this module
// and make sure there are up-to-date events created for each of them.
// If courseid = 0, then every assignment event in the site is checked, else
// only assignment events belonging to the course specified are checked.
// This function is used, in its new format, by restore_refresh_events()

    if ($courseid == 0) {
        if (! $assignments = get_records("assignment")) {
            return true;
        }
    } else {
        if (! $assignments = get_records("assignment", "course", $courseid)) {
            return true;
        }
    }
    $moduleid = get_field('modules', 'id', 'name', 'assignment');

    foreach ($assignments as $assignment) {
        $event = NULL;
        $event->name        = addslashes($assignment->name);
        $event->description = addslashes($assignment->description);
        $event->timestart   = $assignment->timedue;

        if ($event->id = get_field('event', 'id', 'modulename', 'assignment', 'instance', $assignment->id)) {
            update_event($event);

        } else {
            $event->courseid    = $assignment->course;
            $event->groupid     = 0;
            $event->userid      = 0;
            $event->modulename  = 'assignment';
            $event->instance    = $assignment->id;
            $event->eventtype   = 'due';
            $event->timeduration = 0;
            $event->visible     = get_field('course_modules', 'visible', 'module', $moduleid, 'instance', $assignment->id);
            add_event($event);
        }

    }
    return true;
}


function assignment_user_outline($course, $user, $mod, $assignment) {
    if ($submission = assignment_get_submission($assignment, $user)) {
        
        if ($submission->grade) {
            $result->info = get_string("grade").": $submission->grade";
        }
        $result->time = $submission->timemodified;
        return $result;
    }
    return NULL;
}

function assignment_user_complete($course, $user, $mod, $assignment) {
    if ($submission = assignment_get_submission($assignment, $user)) {
        if ($basedir = assignment_file_area($assignment, $user)) {
            if ($files = get_directory_list($basedir)) {
                $countfiles = count($files)." ".get_string("uploadedfiles", "assignment");
                foreach ($files as $file) {
                    $countfiles .= "; $file";
                }
            }
        }

        print_simple_box_start();
        echo "<p><font size=1>";
        echo get_string("lastmodified").": ";
        echo userdate($submission->timemodified);
        echo assignment_print_difference($assignment->timedue - $submission->timemodified);
        echo "</font></p>";

        assignment_print_user_files($assignment, $user);

        echo "<br />";

        if (empty($submission->timemarked)) {
            print_string("notgradedyet", "assignment");
        } else {
            assignment_print_feedback($course, $submission, $assignment);
        }

        print_simple_box_end();

    } else {
        print_string("notsubmittedyet", "assignment");
    }
}


function assignment_cron () {
// Function to be run periodically according to the moodle cron
// Finds all assignment notifications that have yet to be mailed out, and mails them

    global $CFG, $USER;

    /// Notices older than 1 day will not be mailed.  This is to avoid the problem where
    /// cron has not been running for a long time, and then suddenly people are flooded
    /// with mail from the past few weeks or months

    $timenow   = time();
    $endtime   = $timenow - $CFG->maxeditingtime;
    $starttime = $endtime - 24 * 3600;   /// One day earlier

    if ($submissions = assignment_get_unmailed_submissions($starttime, $endtime)) {

        foreach ($submissions as $key => $submission) {
            if (! set_field("assignment_submissions", "mailed", "1", "id", "$submission->id")) {
                echo "Could not update the mailed field for id $submission->id.  Not mailed.\n";
                unset($submissions[$key]);
            }
        }

        $timenow = time();

        foreach ($submissions as $submission) {

            echo "Processing assignment submission $submission->id\n";

            if (! $user = get_record("user", "id", "$submission->userid")) {
                echo "Could not find user $post->userid\n";
                continue;
            }

            $USER->lang = $user->lang;

            if (! $course = get_record("course", "id", "$submission->course")) {
                echo "Could not find course $submission->course\n";
                continue;
            }

            if (! isstudent($course->id, $user->id) and !isteacher($course->id, $user->id)) {
                echo fullname($user)." not an active participant in $course->shortname\n";
                continue;
            }

            if (! $teacher = get_record("user", "id", "$submission->teacher")) {
                echo "Could not find teacher $submission->teacher\n";
                continue;
            }

            if (! $mod = get_coursemodule_from_instance("assignment", $submission->assignment, $course->id)) {
                echo "Could not find course module for assignment id $submission->assignment\n";
                continue;
            }

            if (! $mod->visible) {    /// Hold mail notification for hidden assignments until later
                continue;
            }

            $strassignments = get_string("modulenameplural", "assignment");
            $strassignment  = get_string("modulename", "assignment");

            unset($assignmentinfo);
            $assignmentinfo->teacher = fullname($teacher);
            $assignmentinfo->assignment = "$submission->name";
            $assignmentinfo->url = "$CFG->wwwroot/mod/assignment/view.php?id=$mod->id";

            $postsubject = "$course->shortname: $strassignments: $submission->name";
            $posttext  = "$course->shortname -> $strassignments -> $submission->name\n";
            $posttext .= "---------------------------------------------------------------------\n";
            $posttext .= get_string("assignmentmail", "assignment", $assignmentinfo);
            $posttext .= "---------------------------------------------------------------------\n";

            if ($user->mailformat == 1) {  // HTML
                $posthtml = "<p><font face=\"sans-serif\">".
                "<a href=\"$CFG->wwwroot/course/view.php?id=$course->id\">$course->shortname</a> ->".
                "<a href=\"$CFG->wwwroot/mod/assignment/index.php?id=$course->id\">$strassignments</a> ->".
                "<a href=\"$CFG->wwwroot/mod/assignment/view.php?id=$mod->id\">$submission->name</a></font></p>";
                $posthtml .= "<hr /><font face=\"sans-serif\">";
                $posthtml .= "<p>".get_string("assignmentmailhtml", "assignment", $assignmentinfo)."</p>";
                $posthtml .= "</font><hr />";
            } else {
                $posthtml = "";
            }

            if (! email_to_user($user, $teacher, $postsubject, $posttext, $posthtml)) {
                echo "Error: assignment cron: Could not send out mail for id $submission->id to user $user->id ($user->email)\n";
            }
        }
    }

    return true;
}

function assignment_print_recent_activity($course, $isteacher, $timestart) {
    global $CFG;

    $content = false;
    $assignments = NULL;

    if (!$logs = get_records_select("log", "time > '$timestart' AND ".
                                           "course = '$course->id' AND ".
                                           "module = 'assignment' AND ".
                                           "action = 'upload' ", "time ASC")) {
        return false;
    }

    foreach ($logs as $log) {
        //Create a temp valid module structure (course,id)
        $tempmod->course = $log->course;
        $tempmod->id = $log->info;
        //Obtain the visible property from the instance
        $modvisible = instance_is_visible($log->module,$tempmod);
   
        //Only if the mod is visible
        if ($modvisible) {
            $assignments[$log->info] = assignment_log_info($log);
            $assignments[$log->info]->time = $log->time;
            $assignments[$log->info]->url  = str_replace('&', '&amp;', $log->url);
        }
    }

    if ($assignments) {
        $strftimerecent = get_string("strftimerecent");
        $content = true;
        print_headline(get_string("newsubmissions", "assignment").":");
        foreach ($assignments as $assignment) {
            $date = userdate($assignment->time, $strftimerecent);
            echo "<p><font size=1>$date - ".fullname($assignment)."<br />";
            echo "\"<a href=\"$CFG->wwwroot/mod/assignment/$assignment->url\">";
            echo "$assignment->name";
            echo "</a>\"</font></p>";
        }
    }
 
    return $content;
}

function assignment_grades($assignmentid) {
/// Must return an array of grades, indexed by user, and a max grade.


    if (!$assignment = get_record("assignment", "id", $assignmentid)) {
        return NULL;
    }

    $grades = get_records_menu("assignment_submissions", "assignment", 
                               $assignment->id, "", "userid,grade");

    if ($assignment->grade >= 0) {
        $return->grades = $grades;
        $return->maxgrade = $assignment->grade;

    } else {
        $scaleid = - ($assignment->grade);
        if ($scale = get_record("scale", "id", $scaleid)) {
            $scalegrades = make_menu_from_list($scale->scale);
            if ($grades) {
                foreach ($grades as $key => $grade) {
                    $grades[$key] = $scalegrades[$grade];
                }
            }
        }
        $return->grades = $grades;
        $return->maxgrade = "";
    }

    return $return;
}

function assignment_get_participants($assignmentid) {
//Returns the users with data in one assignment
//(users with records in assignment_submissions, students and teachers)

    global $CFG;

    //Get students
    $students = get_records_sql("SELECT DISTINCT u.*
                                 FROM {$CFG->prefix}user u,
                                      {$CFG->prefix}assignment_submissions a
                                 WHERE a.assignment = '$assignmentid' and
                                       u.id = a.userid");
    //Get teachers
    $teachers = get_records_sql("SELECT DISTINCT u.*
                                 FROM {$CFG->prefix}user u,
                                      {$CFG->prefix}assignment_submissions a
                                 WHERE a.assignment = '$assignmentid' and
                                       u.id = a.teacher");

    //Add teachers to students
    if ($teachers) {
        foreach ($teachers as $teacher) {
            $students[$teacher->id] = $teacher;
        }
    }
    //Return students array (it contains an array of unique users)
    return ($students);
}

function assignment_scale_used ($assignmentid,$scaleid) {
//This function returns if a scale is being used by one assignment

    $return = false;

    $rec = get_record("assignment","id","$assignmentid","grade","-$scaleid");

    if (!empty($rec) && !empty($scaleid)) {
        $return = true;
    }

    return $return;
}

/// SQL STATEMENTS //////////////////////////////////////////////////////////////////

function assignment_log_info($log) {
    global $CFG;
    return get_record_sql("SELECT a.name, u.firstname, u.lastname
                             FROM {$CFG->prefix}assignment a, 
                                  {$CFG->prefix}user u
                            WHERE a.id = '$log->info' 
                              AND u.id = '$log->userid'");
}

function assignment_count_real_submissions($assignment, $groupid=0) {
/// Return all real assignment submissions by ENROLLED students (not empty ones)
    global $CFG;

    if ($groupid) {     /// How many in a particular group?
        return count_records_sql("SELECT COUNT(DISTINCT g.userid, g.groupid)
                                     FROM {$CFG->prefix}assignment_submissions a,
                                          {$CFG->prefix}groups_members g
                                    WHERE a.assignment = $assignment->id 
                                      AND a.timemodified > 0
                                      AND g.groupid = '$groupid' 
                                      AND a.userid = g.userid ");
    } else {
        $select = "s.course = '$assignment->course' AND";
        if ($assignment->course == SITEID) {
            $select = '';
        }     
        return count_records_sql("SELECT COUNT(*)
                                  FROM {$CFG->prefix}assignment_submissions a, 
                                       {$CFG->prefix}user_students s
                                 WHERE a.assignment = '$assignment->id' 
                                   AND a.timemodified > 0
                                   AND $select a.userid = s.userid ");
    }
}

function assignment_get_all_submissions($assignment, $sort="", $dir="DESC") {
/// Return all assignment submissions by ENROLLED students (even empty)
    global $CFG;

    if ($sort == "lastname" or $sort == "firstname") {
        $sort = "u.$sort $dir";
    } else if (empty($sort)) {
        $sort = "a.timemodified DESC";
    } else {
        $sort = "a.$sort $dir";
    }
    
    $select = "s.course = '$assignment->course' AND";
    if ($assignment->course == SITEID) {
        $select = '';
    }
    return get_records_sql("SELECT a.* 
                              FROM {$CFG->prefix}assignment_submissions a, 
                                   {$CFG->prefix}user_students s,
                                   {$CFG->prefix}user u
                             WHERE a.userid = s.userid
                               AND u.id = a.userid
                               AND $select a.assignment = '$assignment->id' 
                          ORDER BY $sort");
}

function assignment_get_users_done($assignment) {
/// Return list of users who have done an assignment
    global $CFG;
    
    $select = "s.course = '$assignment->course' AND";
    if ($assignment->course == SITEID) {
        $select = '';
    }
    return get_records_sql("SELECT u.* 
                              FROM {$CFG->prefix}user u, 
                                   {$CFG->prefix}user_students s, 
                                   {$CFG->prefix}assignment_submissions a
                             WHERE $select s.userid = u.id
                               AND u.id = a.userid 
                               AND a.assignment = '$assignment->id'
                          ORDER BY a.timemodified DESC");
}

function assignment_get_unmailed_submissions($starttime, $endtime) {
/// Return list of marked submissions that have not been mailed out for currently enrolled students
    global $CFG;
    return get_records_sql("SELECT s.*, a.course, a.name
                              FROM {$CFG->prefix}assignment_submissions s, 
                                   {$CFG->prefix}assignment a,
                                   {$CFG->prefix}user_students us
                             WHERE s.mailed = 0 
                               AND s.timemarked <= $endtime 
                               AND s.timemarked >= $starttime
                               AND s.assignment = a.id
                               AND s.userid = us.userid
                               AND a.course = us.course");
}


//////////////////////////////////////////////////////////////////////////////////////

function assignment_file_area_name($assignment, $user) {
//  Creates a directory file name, suitable for make_upload_directory()
    global $CFG;

    return "$assignment->course/$CFG->moddata/assignment/$assignment->id/$user->id";
}

function assignment_file_area($assignment, $user) {
    return make_upload_directory( assignment_file_area_name($assignment, $user) );
}

function assignment_get_submission($assignment, $user) {
    $submission = get_record("assignment_submissions", "assignment", $assignment->id, "userid", $user->id);
    if (!empty($submission->timemodified)) {
        return $submission;
    }
    return NULL;
}

function assignment_print_difference($time) {
    if ($time < 0) {
        $timetext = get_string("late", "assignment", format_time($time));
        return " (<font COLOR=RED>$timetext</font>)";
    } else {
        $timetext = get_string("early", "assignment", format_time($time));
        return " ($timetext)";
    }
}

function assignment_print_submission($assignment, $user, $submission, $teachers, $grades) {
    global $THEME, $USER;

    echo "\n<table border=\"1\" cellspacing=\"0\" cellpadding=\"10\" align=\"center\">";

    echo "\n<tr>";
    if ($assignment->type == OFFLINE) {
        echo "\n<td bgcolor=\"$THEME->body\" width=\"35\" valign=\"top\">";
    } else {
        echo "\n<td rowspan=\"2\" bgcolor=\"$THEME->body\" width=\"35\" valign\"top\">";
    }
    print_user_picture($user->id, $assignment->course, $user->picture);
    echo "</td>";
    echo "<td nowrap=\"nowrap\" bgcolor=\"$THEME->cellheading\">".fullname($user, true);
    if ($assignment->type != OFFLINE and $submission->timemodified) {
        echo "&nbsp;&nbsp;<font SIZE=1>".get_string("lastmodified").": ";
        echo userdate($submission->timemodified);
        echo assignment_print_difference($assignment->timedue - $submission->timemodified);
        echo "</font>";
    }
    echo "</td>\n";
    echo "</tr>";

    if ($assignment->type != OFFLINE) {
        echo "\n<tr><td bgcolor=\"$THEME->cellcontent\">";
        if ($submission->timemodified) {
            assignment_print_user_files($assignment, $user);
        } else {
            print_string("notsubmittedyet", "assignment");
        }
        echo "</td></tr>";
    }

    echo "\n<tr>";
    echo "<td width=\"35\" valign=\"top\">";
    if (!$submission->teacher) {
        $submission->teacher = $USER->id;
    }
    print_user_picture($submission->teacher, $assignment->course, $teachers[$submission->teacher]->picture);
    echo "</td>\n";
    if ($submission->timemodified > $submission->timemarked) {
        echo "<td bgcolor=\"$THEME->cellheading2\">";
    } else {
        echo "<td bgcolor=\"$THEME->cellheading\">";
    }
    if (!$submission->grade and !$submission->timemarked) {
        $submission->grade = -1;   /// Hack to stop zero being selected on the menu below (so it shows 'no grade')
    }
    echo get_string("feedback", "assignment").":";
    choose_from_menu($grades, "g$submission->id", $submission->grade, get_string("nograde"));
    if ($submission->timemarked) {
        echo "&nbsp;&nbsp;<font size=1>".userdate($submission->timemarked)."</font>";
    }
    echo "<br /><textarea name=\"c$submission->id\" rows=\"6\" cols=\"60\">";
    p($submission->comment);
    echo "</textarea><br />";
    echo "</td></tr>";
   
    echo "</table><br clear=\"all\" />\n";
}

function assignment_print_feedback($course, $submission, $assignment) {
    global $CFG, $THEME, $RATING;

    if (! $teacher = get_record("user", "id", $submission->teacher)) {
        error("Weird assignment error");
    }

    echo "\n<table border=\"0\" cellpadding=\"1\" cellspacing=\"1\" align=\"center\"><tr><td bgcolor=\"#888888\">";
    echo "\n<table border=\"0\" cellpadding=\"3\" cellspacing=\"0\" valign=\"top\">";

    echo "\n<tr>";
    echo "\n<td rowspan=\"3\" bgcolor=\"$THEME->body\" width=\"35\" valign=\"top\">";
    print_user_picture($teacher->id, $course->id, $teacher->picture);
    echo "</td>";
    echo "<td nowrap=\"nowrap\" width=\"100%\" bgcolor=\"$THEME->cellheading\">".fullname($teacher);
    echo "&nbsp;&nbsp;<font size=\"2\"><i>".userdate($submission->timemarked)."</i>";
    echo "</tr>";

    echo "\n<tr><td width=\"100%\" bgcolor=\"$THEME->cellcontent\">";

    echo "<p align=\"right\"><font size=\"-1\"><i>";
    if ($assignment->grade) {
        if ($submission->grade or $submission->timemarked) {
            echo get_string("grade").": $submission->grade";
        } else {
            echo get_string("nograde");
        }
    }
    echo "</i></font></p>";

    echo text_to_html($submission->comment);
    echo "</td></tr></table>";
    echo "</td></tr></table>";
}


function assignment_print_user_files($assignment, $user) {
// Arguments are objects

    global $CFG;

    $filearea = assignment_file_area_name($assignment, $user);

    if ($basedir = assignment_file_area($assignment, $user)) {
        if ($files = get_directory_list($basedir)) {
            foreach ($files as $file) {
                $icon = mimeinfo("icon", $file);
                if ($CFG->slasharguments) {
                    $ffurl = "file.php/$filearea/$file";
                } else {
                    $ffurl = "file.php?file=/$filearea/$file";
                }

                echo "<img src=\"$CFG->pixpath/f/$icon\" height=16 width=16 border=0 alt=\"file\">";
                echo "&nbsp;<a target=\"uploadedfile\" href=\"$CFG->wwwroot/$ffurl\">$file</a>";
                echo "<br />";
            }
        }
    }
}

// this function should be defunct now that we're using uploadlib.php
function assignment_delete_user_files($assignment, $user, $exception) {
// Deletes all the user files in the assignment area for a user
// EXCEPT for any file named $exception

    if ($basedir = assignment_file_area($assignment, $user)) {
        if ($files = get_directory_list($basedir)) {
            foreach ($files as $file) {
                if ($file != $exception) {
                    unlink("$basedir/$file");
                    notify(get_string("existingfiledeleted", "assignment", $file));
                }
            }
        }
    }
}

function assignment_print_upload_form($assignment) {
// Arguments are objects

    global $CFG;

    echo "<div align=CENTER>";
    echo "<form enctype=\"multipart/form-data\" method=\"POST\" action=\"upload.php?id=$assignment->id\">";
    echo " <input type=hidden name=id value=\"$assignment->id\" />";
    require_once($CFG->dirroot.'/lib/uploadlib.php');
    upload_print_form_fragment(1,array('newfile'),false,null,0,$assignment->maxbytes,false);
    echo " <input type=submit name=save value=\"".get_string("uploadthisfile")."\" />";
    echo "</form>";
    echo "</div>";
}

function assignment_get_recent_mod_activity(&$activities, &$index, $sincetime, $courseid, $assignment="0", $user="", $groupid="")  {
// Returns all assignments since a given time.  If assignment is specified then
// this restricts the results
    
    global $CFG;

    if ($assignment) {
        $assignmentselect = " AND cm.id = '$assignment'";
    } else {
        $assignmentselect = "";
    }
    if ($user) {
        $userselect = " AND u.id = '$user'";
    } else { 
        $userselect = "";
    }

    $assignments = get_records_sql("SELECT asub.*, u.firstname, u.lastname, u.picture, u.id as userid,
                                           a.grade as maxgrade, name, cm.instance, cm.section, a.type
                                  FROM {$CFG->prefix}assignment_submissions asub,
                                       {$CFG->prefix}user u,
                                       {$CFG->prefix}assignment a,
                                       {$CFG->prefix}course_modules cm
                                 WHERE asub.timemodified > '$sincetime'
                                   AND asub.userid = u.id $userselect
                                   AND a.id = asub.assignment $assignmentselect
                                   AND cm.course = '$courseid'
                                   AND cm.instance = a.id
                                 ORDER BY asub.timemodified ASC");

    if (empty($assignments))
      return;

    foreach ($assignments as $assignment) {
        if (empty($groupid) || ismember($groupid, $assignment->userid)) {
    
          $tmpactivity->type = "assignment";
          $tmpactivity->defaultindex = $index;
          $tmpactivity->instance = $assignment->instance;
          $tmpactivity->name = $assignment->name;
          $tmpactivity->section = $assignment->section;

          $tmpactivity->content->grade = $assignment->grade;
          $tmpactivity->content->maxgrade = $assignment->maxgrade;
          $tmpactivity->content->type = $assignment->type;

          $tmpactivity->user->userid = $assignment->userid;
          $tmpactivity->user->fullname = fullname($assignment);
          $tmpactivity->user->picture = $assignment->picture;

          $tmpactivity->timestamp = $assignment->timemodified;

          $activities[] = $tmpactivity;

          $index++;
        }
    }

    return;
}

function assignment_print_recent_mod_activity($activity, $course, $detail=false)  {
    global $CFG, $THEME;

    echo '<table border="0" cellpadding="3" cellspacing="0">';

    echo "<tr><td bgcolor=\"$THEME->cellcontent2\" class=\"forumpostpicture\" width=\"35\" valign=\"top\">";
    print_user_picture($activity->user->userid, $course, $activity->user->picture);
    echo "</td><td width=\"100%\"><font size=2>";


    if ($detail) {
        echo "<img src=\"$CFG->modpixpath/$activity->type/icon.gif\" ".
             "height=16 width=16 alt=\"$activity->type\">  ";
        echo "<a href=\"$CFG->wwwroot/mod/assignment/view.php?id=" . $activity->instance . "\">"
             . $activity->name . "</a> - ";

    }

    if (isteacher($course)) {
        $grades = "(" .  $activity->content->grade . " / " . $activity->content->maxgrade . ") ";

        $assignment->id = $activity->instance;
        $assignment->course = $course;
        $user->id = $activity->user->userid;

        echo $grades;
        if ($activity->content->type == UPLOADSINGLE) {
            $file = assignment_get_user_file($assignment, $user);
            echo "<img src=\"$CFG->pixpath/f/$file->icon\" height=16 width=16 border=0 alt=\"file\">";
            echo "&nbsp;<a target=\"uploadedfile\" href=\"$CFG->wwwroot/$file->url\">$file->name</a>";
        }
        echo "<br />";
    }
    echo "<a href=\"$CFG->wwwroot/user/view.php?id="
         . $activity->user->userid . "&amp;course=$course\">"
         . $activity->user->fullname . "</a> ";

    echo " - " . userdate($activity->timestamp);

    echo "</font></td></tr>";
    echo "</table>";

    return;
}

function assignment_get_user_file($assignment, $user) {
    global $CFG;

    $tmpfile = "";

    $filearea = assignment_file_area_name($assignment, $user);

    if ($basedir = assignment_file_area($assignment, $user)) {
        if ($files = get_directory_list($basedir)) {
            foreach ($files as $file) {
                $icon = mimeinfo("icon", $file);
                if ($CFG->slasharguments) {
                    $ffurl = "file.php/$filearea/$file";
                } else {
                    $ffurl = "file.php?file=/$filearea/$file";
                }
                $tmpfile->url  = $ffurl;
                $tmpfile->name = $file;
                $tmpfile->icon = $icon;
            }
        }
    }
    return $tmpfile;
}

if (!function_exists('get_group_teachers')) {        // Will be in datalib.php later
    function get_group_teachers($courseid, $groupid) {
    /// Returns a list of all the teachers who can access a group
        if ($teachers = get_course_teachers($courseid)) {
            foreach ($teachers as $key => $teacher) { 
                if ($teacher->editall) {             // These can access anything
                    continue;
                }
                if (($teacher->authority > 0) and ismember($groupid, $teacher->id)) {  // Specific group teachers
                    continue;
                }
                unset($teacher[$key]);
            }
        }
        return $teachers;
    }
}

function assignment_email_teachers($course, $cm, $assignment, $submission) {
/// Alerts teachers by email of new or changed assignments that need grading

    global $CFG;

    if (empty($assignment->emailteachers)) {          // No need to do anything
        return;
    }

    $user = get_record('user', 'id', $submission->userid);

    if (groupmode($course, $cm) == SEPARATEGROUPS) {   // Separate groups are being used
        if (!$group = user_group($course->id, $user->id)) {             // Try to find a group
            $group->id = 0;                                             // Not in a group, never mind
        }
        $teachers = get_group_teachers($course->id, $group->id);        // Works even if not in group
    } else {
        $teachers = get_course_teachers($course->id);
    }

    if ($teachers) {
       
        $strassignments = get_string('modulenameplural', 'assignment');
        $strassignment  = get_string('modulename', 'assignment');
        $strsubmitted  = get_string('submitted', 'assignment');

        foreach ($teachers as $teacher) {
            unset($info);
            $info->username = fullname($user);
            $info->assignment = $assignment->name;
            $info->url = "$CFG->wwwroot/mod/assignment/submissions.php?id=$assignment->id";

            $postsubject = "$strsubmitted: $info->username -> $assignment->name";
            $posttext  = "$course->shortname -> $strassignments -> $assignment->name\n";
            $posttext .= "---------------------------------------------------------------------\n";
            $posttext .= get_string("emailteachermail", "assignment", $info);
            $posttext .= "\n---------------------------------------------------------------------\n";

            if ($user->mailformat == 1) {  // HTML
                $posthtml = "<p><font face=\"sans-serif\">".
                "<a href=\"$CFG->wwwroot/course/view.php?id=$course->id\">$course->shortname</a> ->".
                "<a href=\"$CFG->wwwroot/mod/assignment/index.php?id=$course->id\">$strassignments</a> ->".
                "<a href=\"$CFG->wwwroot/mod/assignment/view.php?id=$cm->id\">$assignment->name</a></font></p>";
                $posthtml .= "<hr /><font face=\"sans-serif\">";
                $posthtml .= "<p>".get_string("emailteachermailhtml", "assignment", $info)."</p>";
                $posthtml .= "</font><hr />";
            } else {
                $posthtml = "";
            }

            @email_to_user($teacher, $user, $postsubject, $posttext, $posthtml);  // If it fails, oh well, too bad.
        }
    }
}


?>
