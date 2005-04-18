<?PHP  // $Id$
/**
 * assignment_base is the base class for assignment types
 *
 * This class provides all the functionality for an assignment
 */


if (!isset($CFG->assignment_maxbytes)) {
    set_config("assignment_maxbytes", 1024000);  // Default maximum size for all assignments
}


/*
 * Standard base class for all assignment submodules (assignment types).
 *
 *
 */
class assignment_base {

    var $cm;
    var $course;
    var $assignment;

    /**
     * Constructor for the base assignment class
     *
     * Constructor for the base assignment class.
     * If cmid is set create the cm, course, assignment objects.
     *
     * @param cmid   integer, the current course module id - not set for new assignments
     * @param assignment   object, usually null, but if we have it we pass it to save db access
     */
    function assignment_base($cmid=0, $assignment=NULL, $cm=NULL, $course=NULL) {

        global $CFG;

        if ($cmid) {
            if ($cm) {
                $this->cm = $cm;
            } else if (! $this->cm = get_record('course_modules', 'id', $cmid)) {
                error('Course Module ID was incorrect');
            }

            if ($course) {
                $this->course = $course;
            } else if (! $this->course = get_record('course', 'id', $this->cm->course)) {
                error('Course is misconfigured');
            }

            if ($assignment) {
                $this->assignment = $assignment;
            } else if (! $this->assignment = get_record('assignment', 'id', $this->cm->instance)) {
                error('assignment ID was incorrect');
            }

            $this->strassignment = get_string('modulename', 'assignment');
            $this->strassignments = get_string('modulenameplural', 'assignment');
            $this->strsubmissions = get_string('submissions', 'assignment');
            $this->strlastmodified = get_string('lastmodified');

            if ($this->course->category) {
                $this->navigation = "<a target=\"{$CFG->framename}\" href=\"$CFG->wwwroot/course/view.php?id={$this->course->id}\">{$this->course->shortname}</a> -> ".
                                    "<a target=\"{$CFG->framename}\" href=\"index.php?id={$this->course->id}\">$this->strassignments</a> ->";
            } else {
                $this->navigation = "<a target=\"{$CFG->framename}\" href=\"index.php?id={$this->course->id}\">$this->strassignments</a> ->";
            }

            $this->pagetitle = strip_tags($this->course->shortname.': '.$this->strassignment.': '.format_string($this->assignment->name,true));

            if (!$this->cm->visible and !isteacher($this->course->id)) {
                $pagetitle = strip_tags($this->course->shortname.': '.$this->strassignment);
                print_header($pagetitle, $this->course->fullname, "$this->navigation $this->strassignment", 
                             "", "", true, '', navmenu($this->course, $this->cm));
                notice(get_string("activityiscurrentlyhidden"), "$CFG->wwwroot/course/view.php?id={$this->course->id}");
            }

            $this->currentgroup = get_current_group($this->course->id);
        }

    /// Set up things for a HTML editor if it's needed
        if ($this->usehtmleditor = can_use_html_editor()) {
            $this->defaultformat = FORMAT_HTML;
        } else {
            $this->defaultformat = FORMAT_MOODLE;
        }

    }

    /*
     * Display the assignment to students (sub-modules will most likely override this)
     */

    function view() {

        add_to_log($this->course->id, "assignment", "view", "view.php?id={$this->cm->id}", 
                   $this->assignment->id, $this->cm->id);

        $this->view_header();

        $this->view_intro();

        $this->view_dates();

        $this->view_feedback();

        $this->view_footer();
    }

    /*
     * Display the top of the view.php page, this doesn't change much for submodules
     */
    function view_header($subpage='') {

        global $CFG;

        if ($subpage) {
            $extranav = '<a target="'.$CFG->framename.'" href="view.php?id='.$this->cm->id.'">'.
                          format_string($this->assignment->name,true).'</a> -> '.$subpage;
        } else {
            $extranav = ' '.format_string($this->assignment->name,true);
        }

        print_header($this->pagetitle, $this->course->fullname, $this->navigation.$extranav, '', '', 
                     true, update_module_button($this->cm->id, $this->course->id, $this->strassignment), 
                     navmenu($this->course, $this->cm));

        echo '<div class="reportlink">'.$this->submittedlink().'</div>';
    }


    /*
     * Display the assignment intro
     */
    function view_intro() {
        print_simple_box_start('center', '', '', '', 'generalbox', 'intro');
        echo format_text($this->assignment->description, $this->assignment->format);
        print_simple_box_end();
    }

    /*
     * Display the assignment dates
     */
    function view_dates() {
        if (!$this->assignment->timeavailable && !$this->assignment->timedue) {
            return;
        }

        print_simple_box_start('center', '', '', '', 'generalbox', 'dates');
        echo '<table>';
        if ($this->assignment->timeavailable) {
            echo '<tr><td class="c0">'.get_string('availabledate','assignment').':</td>';
            echo '    <td class="c1">'.userdate($this->assignment->timeavailable).'</td></tr>';
        }
        if ($this->assignment->timedue) {
            echo '<tr><td class="c0">'.get_string('duedate','assignment').':</td>';
            echo '    <td class="c1">'.userdate($this->assignment->timedue).'</td></tr>';
        }
        echo '</table>';
        print_simple_box_end();
    }


    /*
     * Display the bottom of the view.php page, this doesn't change much for submodules
     */
    function view_footer() {
        print_footer($this->course);
    }


    function view_feedback($submission=NULL) {
        global $USER;

        if (!$submission) { /// Get submission for this assignment
            $submission = $this->get_submission($USER->id);
        }

        if (empty($submission->timemarked)) {   /// Nothing to show, so print nothing
            return;
        }

    /// We need the teacher info
        if (! $teacher = get_record('user', 'id', $submission->teacher)) {
            print_object($submission);
            error('Could not find the teacher');
        }

    /// Print the feedback
        print_heading(get_string('feedbackfromteacher', 'assignment', $this->course->teacher));

        echo '<table cellspacing="0" class="feedback">';

        echo '<tr>';
        echo '<td class="left picture">';
        print_user_picture($teacher->id, $this->course->id, $teacher->picture);
        echo '</td>';
        echo '<td class="topic">';
        echo '<div class="from">';
        echo '<div class="fullname">'.fullname($teacher).'</div>';
        echo '<div class="time">'.userdate($submission->timemarked).'</div>';
        echo '</div>';
        $this->print_user_files($submission->userid);
        echo '</td>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<td class="left side">&nbsp;</td>';
        echo '<td class="content">';
        if ($this->assignment->grade) {
            echo '<div class="grade">';
            echo get_string("grade").': '.$this->display_grade($submission->grade);
            echo '</div>';
            echo '<div class="clearer"></div>';
        }

        echo '<div class="comment">';
        echo format_text($submission->comment, $submission->format);
        echo '</div>';
        echo '</tr>';

        echo '</table>';
    }



    /*
     * Print the start of the setup form for the current assignment type
     */
    function setup(&$form, $action='') {
        global $CFG, $THEME;

        if (empty($this->course)) {
            if (! $this->course = get_record("course", "id", $form->course)) {
                error("Course is misconfigured");
            }
        }
        if (empty($action)) {   // Default destination for this form
            $action = $CFG->wwwroot.'/course/mod.php';
        }

        if (empty($form->name)) {
            $form->name = "";
        }
        if (empty($form->assignmenttype)) {
            $form->assignmenttype = "";
        }
        if (empty($form->description)) {
            $form->description = "";
        }

        $strname    = get_string('name');
        $strassignments = get_string('modulenameplural', 'assignment');
        $strheading = empty($form->name) ? get_string("type$form->assignmenttype",'assignment') : s(format_string(stripslashes($form->name),true));

        print_header($this->course->shortname.': '.$strheading, "$strheading",
                "<a href=\"$CFG->wwwroot/course/view.php?id={$this->course->id}\">{$this->course->shortname} </a> -> ".
                "<a href=\"$CFG->wwwroot/mod/assignment/index.php?id={$this->course->id}\">$strassignments</a> -> $strheading");

        print_simple_box_start('center', '70%');
        print_heading(get_string('type'.$form->assignmenttype,'assignment'));
        print_simple_box(get_string('help'.$form->assignmenttype, 'assignment'), 'center');
        include("$CFG->dirroot/mod/assignment/type/common.html");

        include("$CFG->dirroot/mod/assignment/type/".$form->assignmenttype."/mod.html");
        $this->setup_end(); 
    }

    /*
     * Print the end of the setup form for the current assignment type
     */
    function setup_end() {
        global $CFG;

        include($CFG->dirroot.'/mod/assignment/type/common_end.html');

        print_simple_box_end();

        if ($this->usehtmleditor) {
            use_html_editor();
        }

        print_footer($this->course);
    }


    function add_instance($assignment) {
        // Given an object containing all the necessary data,
        // (defined by the form in mod.html) this function
        // will create a new instance and return the id number
        // of the new instance.

        $assignment->timemodified = time();
        $assignment->timedue = make_timestamp($assignment->dueyear, $assignment->duemonth, 
                                              $assignment->dueday, $assignment->duehour, 
                                              $assignment->dueminute);
        $assignment->timeavailable = make_timestamp($assignment->availableyear, $assignment->availablemonth, 
                                                    $assignment->availableday, $assignment->availablehour, 
                                                    $assignment->availableminute);

        return insert_record("assignment", $assignment);
    }

    function delete_instance($assignment) {
        if (! delete_records("assignment", "id", "$assignment->id")) {
            $result = false;
        }
        return $result;
    }

    function update_instance($assignment) {
        // Given an object containing all the necessary data,
        // (defined by the form in mod.html) this function
        // will create a new instance and return the id number
        // of the new instance.

        $assignment->timemodified = time();
        $assignment->timedue = make_timestamp($assignment->dueyear, $assignment->duemonth, 
                                              $assignment->dueday, $assignment->duehour, 
                                              $assignment->dueminute);
        $assignment->timeavailable = make_timestamp($assignment->availableyear, $assignment->availablemonth, 
                                                    $assignment->availableday, $assignment->availablehour, 
                                                    $assignment->availableminute);
        $assignment->id = $assignment->instance;
        return update_record("assignment", $assignment);
    }



    /*
     * Top-level function for handling of submissions called by submissions.php
     *  
     */
    function submissions($mode) {
        switch ($mode) {
            case 'grade':                         // We are in a popup window grading
                if ($submission = $this->process_feedback()) {
                    print_heading(get_string('changessaved'));
                    $this->update_main_listing($submission);
                }
                close_window();
                break;

            case 'single':                        // We are in a popup window displaying submission
                $this->display_submission();
                break;

            case 'all':                           // Main window, display everything
                $this->display_submissions();
                break;
        }
    }

    function update_main_listing($submission) {
        global $SESSION;

        /// Run some Javascript to try and update the parent page
        echo '<script type="text/javascript">'."\n<!--\n";
        if (empty($SESSION->flextable['mod-assignment-submissions']->collapse['grade'])) {
            echo 'opener.document.getElementById("g'.$submission->userid.
                 '").innerHTML="'.$this->display_grade($submission->grade)."\";\n";
        }
        if (empty($SESSION->flextable['mod-assignment-submissions']->collapse['comment'])) {
            echo 'opener.document.getElementById("com'.$submission->userid.
                 '").innerHTML="'.shorten_text($submission->comment, 15)."\";\n";
        }
        if (empty($SESSION->flextable['mod-assignment-submissions']->collapse['timemodified']) &&
            $submission->timemodified) {
            echo 'opener.document.getElementById("ts'.$submission->userid.
                 '").innerHTML="'.userdate($submission->timemodified)."\";\n";
        }
        if (empty($SESSION->flextable['mod-assignment-submissions']->collapse['timemarked']) &&
            $submission->timemarked) {
            echo 'opener.document.getElementById("tt'.$submission->userid.
                 '").innerHTML="'.userdate($submission->timemarked)."\";\n";
        }
        if (empty($SESSION->flextable['mod-assignment-submissions']->collapse['status'])) {
            echo 'opener.document.getElementById("up'.$submission->userid.'").className="s1";';
            echo 'opener.document.getElementById("button'.$submission->userid.'").value="'.get_string('update').' ...";';
        }
        echo "\n-->\n</script>";
        fflush();
    }

    /*
     *  Display a grade in user-friendly form, whether it's a scale or not
     *  
     */
    function display_grade($grade) {

        static $scalegrades;   // Cached because we only have one per assignment

        if ($this->assignment->grade >= 0) {    // Normal number
            return $grade.' / '.$this->assignment->grade;

        } else {                                // Scale
            if (empty($scalegrades)) {
                if ($scale = get_record('scale', 'id', -($this->assignment->grade))) {
                    $scalegrades = make_menu_from_list($scale->scale);
                } else {
                    return '-';
                }
            }
            if (isset($scalegrades[$grade])) {
                return $scalegrades[$grade];
            }
            return '';
        }
    }

    /*
     *  Display a single submission, ready for grading on a popup window
     *  
     */
    function display_submission() {
        
        $userid = required_param('userid');

        if (!$user = get_record('user', 'id', $userid)) {
            error('No such user!');
        }

        if (!$submission = $this->get_submission($user->id, true)) {  // Get one or make one
            error('Could not find submission!');
        }

        if ($submission->timemodified > $submission->timemarked) {
            $subtype = 'assignmentnew';
        } else {
            $subtype = 'assignmentold';
        }

        print_header(get_string('feedback', 'assignment').':'.fullname($user, true).':'.format_string($this->assignment->name));


        echo '<table cellspacing="0" class="feedback '.$subtype.'" >';

        echo '<tr>';
        echo '<td width="35" valign="top" class="picture user">';
        print_user_picture($user->id, $this->course->id, $user->picture);
        echo '</td>';
        echo '<td class="topic">';
        echo '<div class="from">';
        echo '<div class="fullname">'.fullname($user, true).'</div>';
        if ($submission->timemodified) {
            echo '<div class="time">'.userdate($submission->timemodified).
                                     $this->display_lateness($submission->timemodified).'</div>';
        }
        echo '</div>';
        $this->print_user_files($user->id);
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<td width="35" valign="top" class="picture teacher">';
        if ($submission->teacher) {
            $teacher = get_record('user', 'id', $submission->teacher);
        } else {
            global $USER;
            $teacher = $USER;
        }
        print_user_picture($teacher->id, $this->course->id, $teacher->picture);
        echo '</td>';
        echo '<td class="content">';

        echo '<form action="submissions.php" method="post">';
        echo '<input type="hidden" name="userid" value="'.$userid.'">';
        echo '<input type="hidden" name="id" value="'.$this->cm->id.'">';
        echo '<input type="hidden" name="mode" value="grade">';
        if (!$submission->grade and !$submission->timemarked) {
            $submission->grade = -1;   /// Hack to stop zero being selected on the menu below (so it shows 'no grade')
        }
        if ($submission->timemarked) {
            echo '<div class="from">';
            echo '<div class="fullname">'.fullname($teacher, true).'</div>';
            echo '<div class="time">'.userdate($submission->timemarked).'</div>';
            echo '</div>';
        }
        echo '<div class="grade">'.get_string('grade').':';
        choose_from_menu(make_grades_menu($this->assignment->grade), 'grade', 
                         $submission->grade, get_string('nograde'));
        echo '</div>';
        echo '<div class="clearer"></div>';

        echo '<br />';
        print_textarea($this->usehtmleditor, 12, 58, 0, 0, 'comment', $submission->comment, $this->course->id);

        if ($this->usehtmleditor) { 
            echo '<input type="hidden" name="format" value="'.FORMAT_HTML.'" />';
        } else {
            echo '<div align="right" class="format">';
            choose_from_menu(format_text_menu(), "format", $submission->format, "");
            helpbutton("textformat", get_string("helpformatting"));
            echo '</div>';
        }

        echo '<div class="buttons" align="center">';
        echo '<input type="submit" name="submit" value="'.get_string('savechanges').'" />';
        echo '<input type="submit" name="cancel" value="'.get_string('cancel').'" />';
        echo '</div>';

        echo '</form>';

        echo '</td></tr>';
        echo '</table>';


        if ($this->usehtmleditor) {
            use_html_editor();
        }

        print_footer('none');
    }


    /*
     *  Display all the submissions ready for grading
     */
    function display_submissions() {

        global $CFG, $db;

        $teacherattempts = true; /// Temporary measure

        $page    = optional_param('page', 0);
        $perpage = optional_param('perpage', 10);

        $strsaveallfeedback = get_string('saveallfeedback', 'assignment');

    /// Some shortcuts to make the code read better
        
        $course     = $this->course;
        $assignment = $this->assignment;
        $cm         = $this->cm;


        add_to_log($course->id, 'assignment', 'view submission', 'submissions.php?id='.$this->assignment->id, $this->assignment->id, $this->cm->id);
        
        print_header_simple(format_string($this->assignment->name,true), "", '<a href="index.php?id='.$course->id.'">'.$this->strassignments.'</a> -> <a href="view.php?a='.$this->assignment->id.'">'.format_string($this->assignment->name,true).'</a> -> '. $this->strsubmissions, '', '', true, update_module_button($cm->id, $course->id, $this->strassignment), navmenu($course, $cm));


        $tablecolumns = array('picture', 'fullname', 'grade', 'comment', 'timemodified', 'timemarked', 'status');
        $tableheaders = array('', get_string('fullname'), get_string('grade'), get_string('comment', 'assignment'), get_string('lastmodified').' ('.$course->student.')', get_string('lastmodified').' ('.$course->teacher.')', get_string('status'));


        require_once($CFG->libdir.'/tablelib.php');
        $table = new flexible_table('mod-assignment-submissions');
                        
        $table->define_columns($tablecolumns);
        $table->define_headers($tableheaders);
        $table->define_baseurl($CFG->wwwroot.'/mod/assignment/submissions.php?id='.$this->cm->id);
                
        $table->sortable(true);
        $table->collapsible(true);
        $table->initialbars(true);
        
        $table->column_suppress('picture');
        $table->column_suppress('fullname');
        
        $table->column_class('picture', 'picture');
        $table->column_class('fullname', 'fullname');
        $table->column_class('grade', 'grade');
        $table->column_class('comment', 'comment');
        $table->column_class('timemodified', 'timemodified');
        $table->column_class('timemarked', 'timemarked');
        $table->column_class('status', 'status');
        
        $table->set_attribute('cellspacing', '0');
        $table->set_attribute('id', 'attempts');
        $table->set_attribute('class', 'submissions');
        $table->set_attribute('width', '90%');
        $table->set_attribute('align', 'center');
            
        // Start working -- this is necessary as soon as the niceties are over
        $table->setup();



    /// Check to see if groups are being used in this assignment
        if ($groupmode = groupmode($course, $cm)) {   // Groups are being used
            $currentgroup = setup_and_print_groups($course, $groupmode, 'submissions.php?id='.$this->cm->id);
        } else {
            $currentgroup = false;
        }


    /// Get all teachers and students
        if ($currentgroup) {
            $users = get_group_users($currentgroup);
        } else {
            $users = get_course_users($course->id);
        }
            
        if (!$teacherattempts) {
            $teachers = get_course_teachers($course->id);
            if (!empty($teachers)) {
                $keys = array_keys($teachers);
            }
            foreach ($keys as $key) {
                unset($users[$key]);
            }
        }
        
        if (empty($users)) {
            print_heading($strnoattempts);
            return true;
        }


    /// Construct the SQL

        if ($where = $table->get_sql_where()) {
            $where = str_replace('firstname', 'u.firstname', $where);
            $where = str_replace('lastname', 'u.lastname', $where);
            $where .= ' AND ';
        }

        if ($sort = $table->get_sql_sort()) {
            $sortparts = explode(',', $sort);
            $newsort   = array();
            foreach ($sortparts as $sortpart) {
                $sortpart = trim($sortpart);
                $newsort[] = $sortpart;
            }
            $sort = ' ORDER BY '.implode(', ', $newsort);
        }


        $select = 'SELECT '.$db->Concat('u.id', '\'#\'', $db->IfNull('s.userid', '0')).' AS uvs, u.id, u.firstname, u.lastname, u.picture, s.id AS submissionid, s.grade, s.comment, s.timemodified, s.timemarked, ((s.timemarked > 0) && (s.timemarked >= s.timemodified)) AS status ';
        $group  = 'GROUP BY uvs ';
        $sql = 'FROM '.$CFG->prefix.'user u '.
               'LEFT JOIN '.$CFG->prefix.'assignment_submissions s ON u.id = s.userid AND s.assignment = '.$this->assignment->id.' '.
               'WHERE '.$where.'u.id IN ('.implode(',', array_keys($users)).') ';


        $total = count_records_sql('SELECT COUNT(DISTINCT('.$db->Concat('u.id', '\'#\'', $db->IfNull('s.userid', '0')).')) '.$sql);

        $table->pagesize($perpage, $total);
        
        if($table->get_page_start() !== '' && $table->get_page_size() !== '') {
            $limit = ' '.sql_paging_limit($table->get_page_start(), $table->get_page_size());     
        }
        else {
            $limit = '';
        }

        $strupdate = get_string('update');
        $strgrade  = get_string('grade');
        $grademenu = make_grades_menu($this->assignment->grade);

        if (($ausers = get_records_sql($select.$sql.$group.$sort.$limit)) !== false) {
            
            foreach ($ausers as $auser) {
                $picture = print_user_picture($auser->id, $course->id, $auser->picture, false, true);
                if (!empty($auser->submissionid)) {
                    if ($auser->timemodified > 0) {
                        $studentmodified = '<div id="ts'.$auser->id.'">'.userdate($auser->timemodified).'</div>';
                    } else {
                        $studentmodified = '<div id="ts'.$auser->id.'">&nbsp;</div>';
                    }
                    if ($auser->timemarked > 0) {
                        $teachermodified = '<div id="tt'.$auser->id.'">'.userdate($auser->timemarked).'</div>';
                        $grade = '<div id="g'.$auser->id.'">'.$this->display_grade($auser->grade).'</div>';
                    } else {
                        $teachermodified = '<div id="tt'.$auser->id.'">&nbsp;</div>';
                        $grade = '<div id="g'.$auser->id.'"></div>';
                    }
                    
                    $comment = '<div id="com'.$auser->id.'">'.shorten_text($auser->comment, 15).'</div>';

                } else {
                    $studentmodified = '<div id="ts'.$auser->id.'">&nbsp;</div>';
                    $teachermodified = '<div id="tt'.$auser->id.'">&nbsp;</div>';
                    $status          = '<div id="st'.$auser->id.'"></div>';
                    $grade           = '<div id="g'.$auser->id.'">&nbsp;</div>';
                    $comment         = '<div id="com'.$auser->id.'">&nbsp;</div>';
                }

                if ($auser->status === NULL) {
                    $auser->status = 0;
                }

                $buttontext = ($auser->status == 1) ? $strupdate : $strgrade;

                $button = button_to_popup_window ('/mod/assignment/submissions.php?id='.$this->cm->id.'&amp;userid='.$auser->id.'&amp;mode=single', 
                        'grade'.$auser->id, $buttontext, 450, 700, $buttontext, 'none', true, 'button'.$auser->id);

                $status  = '<div id="up'.$auser->id.'" class="s'.$auser->status.'">'.$button.'</div>';

                $row = array($picture, fullname($auser), $grade, $comment, $studentmodified, $teachermodified, $status);
                $table->add_data($row);
            }
        }

        $table->print_html();

        print_footer($this->course);

    }



    /*
     *  Display and process the submissions 
     */
    function process_feedback() {

        global $USER;

        if (!$feedback = data_submitted()) {      // No incoming data?
            return false;
        }

        if (!empty($feedback->cancel)) {          // User hit cancel button
            return false;
        }

        $newsubmission = $this->get_submission($feedback->userid, true);  // Get or make one

        $newsubmission->grade      = $feedback->grade;
        $newsubmission->comment    = $feedback->comment;
        $newsubmission->format     = $feedback->format;
        $newsubmission->teacher    = $USER->id;
        $newsubmission->mailed     = 0;       // Make sure mail goes out (again, even)
        $newsubmission->timemarked = time();

        if (empty($submission->timemodified)) {   // eg for offline assignments
            $newsubmission->timemodified = time();
        }

        if (! update_record('assignment_submissions', $newsubmission)) {
            return false;
        }

        add_to_log($this->course->id, 'assignment', 'update grades', 
                   'submissions.php?id='.$this->assignment->id.'&user='.$feedback->userid, $feedback->userid, $this->cm->id);
        
        return $newsubmission;

    }


    function get_submission($userid=0, $createnew=false) {
        global $USER;

        if (empty($userid)) {
            $userid = $USER->id;
        }

        $submission = get_record('assignment_submissions', 'assignment', $this->assignment->id, 'userid', $userid);

        if ($submission || !$createnew) {
            return $submission;
        }

        $newsubmission = new Object;
        $newsubmission->assignment = $this->assignment->id;
        $newsubmission->userid = $userid;
        $newsubmission->timecreated = time();
        if (!insert_record("assignment_submissions", $newsubmission)) {
            error("Could not insert a new empty submission");
        }

        return get_record('assignment_submissions', 'assignment', $this->assignment->id, 'userid', $userid);
    }


    function get_submissions($sort='', $dir='DESC') {
        /// Return all assignment submissions by ENROLLED students (even empty)
        global $CFG;

        if ($sort == "lastname" or $sort == "firstname") {
            $sort = "u.$sort $dir";
        } else if (empty($sort)) {
            $sort = "a.timemodified DESC";
        } else {
            $sort = "a.$sort $dir";
        }

        $select = "s.course = '$this->assignment->course' AND";
        $site = get_site();
        if ($this->assignment->course == $site->id) {
            $select = '';
        }   
        return get_records_sql("SELECT a.* 
                FROM {$CFG->prefix}assignment_submissions a, 
                {$CFG->prefix}user_students s,
                {$CFG->prefix}user u
                WHERE a.userid = s.userid
                AND u.id = a.userid
                AND $select a.assignment = '$this->assignment->id' 
                ORDER BY $sort");
    }


    function count_real_submissions($groupid=0) {
        /// Return all real assignment submissions by ENROLLED students (not empty ones)
        global $CFG;

        if ($groupid) {     /// How many in a particular group?
            return count_records_sql("SELECT COUNT(DISTINCT g.userid, g.groupid)
                    FROM {$CFG->prefix}assignment_submissions a,
                    {$CFG->prefix}groups_members g
                    WHERE a.assignment = {$this->assignment->id} 
                    AND a.timemodified > 0
                    AND g.groupid = '$groupid' 
                    AND a.userid = g.userid ");
        } else {
            $select = "s.course = '{$this->assignment->course}' AND";
            if ($this->assignment->course == SITEID) {
                $select = '';
            }
            return count_records_sql("SELECT COUNT(*)
                    FROM {$CFG->prefix}assignment_submissions a, 
                    {$CFG->prefix}user_students s
                    WHERE a.assignment = '{$this->assignment->id}' 
                    AND a.timemodified > 0
                    AND $select a.userid = s.userid ");
        } 
    }

    function email_teachers($submission) {
        /// Alerts teachers by email of new or changed assignments that need grading

        global $CFG;

        if (empty($this->assignment->emailteachers)) {          // No need to do anything
            return;
        }

        $user = get_record('user', 'id', $submission->userid);

        if (groupmode($this->course, $this->cm) == SEPARATEGROUPS) {   // Separate groups are being used
            if (!$group = user_group($this->course->id, $user->id)) {             // Try to find a group
                $group->id = 0;                                             // Not in a group, never mind
            }
            $teachers = get_group_teachers($this->course->id, $group->id);        // Works even if not in group
        } else {
            $teachers = get_course_teachers($this->course->id);
        }

        if ($teachers) {

            $strassignments = get_string('modulenameplural', 'assignment');
            $strassignment  = get_string('modulename', 'assignment');
            $strsubmitted  = get_string('submitted', 'assignment');

            foreach ($teachers as $teacher) {
                unset($info);
                $info->username = fullname($user);
                $info->assignment = format_string($this->assignment->name,true);
                $info->url = $CFG->wwwroot.'/mod/assignment/submissions.php?id='.$this->cm->id;

                $postsubject = $strsubmitted.': '.$info->username.' -> '.$this->assignment->name;
                $posttext = $this->email_teachers_text($info);
                $posthtml = ($teacher->mailformat == 1) ? $this->email_teachers_html($info) : '';

                @email_to_user($teacher, $user, $postsubject, $posttext, $posthtml);  // If it fails, oh well, too bad.
            }
        }
    }

    function email_teachers_text($info) {
        $posttext  = $this->course->shortname.' -> '.$this->strassignments.' -> '.
                     format_string($this->assignment->name, true)."\n";
        $posttext .= '---------------------------------------------------------------------'."\n";
        $posttext .= get_string("emailteachermail", "assignment", $info)."\n";
        $posttext .= '---------------------------------------------------------------------'."\n";
        return $posttext;
    }


    function email_teachers_html($info) {
        $posthtml  = '<p><font face="sans-serif">'.
                     '<a href="'.$CFG->wwwroot.'/course/view.php?id='.$this->course->id.'">'.$course->shortname.'</a> ->'.
                     '<a href="'.$CFG->wwwroot.'/mod/assignment/index.php?id='.$this->course->id.'">'.$this->strassignments.'</a> ->'.
                     '<a href="'.$CFG->wwwroot.'/mod/assignment/view.php?id='.$cm->id.'">'.format_string($this->assignment->name,true).'</a></font></p>';
        $posthtml .= '<hr /><font face="sans-serif">';
        $posthtml .= '<p>'.get_string('emailteachermailhtml', 'assignment', $info).'</p>';
        $posthtml .= '</font><hr />';
    }


    function print_user_files($userid=0, $return=false) {
    
        global $CFG, $USER;

        if (!$userid) {
            if (!isloggedin()) {
                return '';
            }
            $userid = $USER->id;
        }
    
        $filearea = $this->file_area_name($userid);

        $output = '';
    
        if ($basedir = $this->file_area($userid)) {
            if ($files = get_directory_list($basedir)) {
                foreach ($files as $key => $file) {
                    require_once($CFG->libdir.'/filelib.php');
                    $icon = mimeinfo('icon', $file);
                    if ($CFG->slasharguments) {
                        $ffurl = "file.php/$filearea/$file";
                    } else {
                        $ffurl = "file.php?file=/$filearea/$file";
                    }
    
                    $output = '<img align="middle" src="'.$CFG->pixpath.'/f/'.$icon.'" height="16" width="16" alt="'.$icon.'" />'.
                              link_to_popup_window ('/'.$ffurl, 'file'.$key, $file, 450, 580, $file, 'none', true).
                             '<br />';
                }
            }
        }

        $output = '<div class="files">'.$output.'</div>';

        if ($return) {
            return $output;
        }
        echo $output;
    }

    function count_user_files($userid) {
        global $CFG;

        $filearea = $this->file_area_name($userid);

        if ($basedir = $this->file_area($userid)) {
            if ($files = get_directory_list($basedir)) {
                return count($files);
            }
        }
        return 0;
    }

    function file_area_name($userid) {
    //  Creates a directory file name, suitable for make_upload_directory()
        global $CFG;
    
        return $this->course->id.'/'.$CFG->moddata.'/assignment/'.$this->assignment->id.'/'.$userid;
    }
    
    function file_area($userid) {
        return make_upload_directory( $this->file_area_name($userid) );
    }

    function isopen() {
        $time = time();
        if ($this->assignment->preventlate) {
            return ($this->assignment->timeavailable <= $time && $time <= $this->assignment->timedue);
        } else {
            return ($this->assignment->timeavailable <= $time);
        }
    }

    function user_outline($user) {
        if ($submission = $this->get_submission($user->id)) {
    
            if ($submission->grade) {
                $result->info = get_string('grade').': '.$this->display_grade($submission->grade);
            }
            $result->time = $submission->timemodified;
            return $result;
        }
        return NULL;
    }
    
    function user_complete($user) {
        if ($submission = $this->get_submission($user->id)) {
            if ($basedir = $this->file_area($user->id)) {
                if ($files = get_directory_list($basedir)) {
                    $countfiles = count($files)." ".get_string("uploadedfiles", "assignment");
                    foreach ($files as $file) {
                        $countfiles .= "; $file";
                    }
                }
            }
    
            print_simple_box_start();
            echo get_string("lastmodified").": ";
            echo userdate($submission->timemodified);
            echo $this->display_lateness($submission->timemodified);
    
            $this->print_user_files($user->id);
    
            echo '<br />';
    
            if (empty($submission->timemarked)) {
                print_string("notgradedyet", "assignment");
            } else {
                $this->view_feedback($submission);
            }
    
            print_simple_box_end();
    
        } else {
            print_string("notsubmittedyet", "assignment");
        }
    }

    function display_lateness($timesubmitted) {
        $time = $this->assignment->timedue - $timesubmitted;
        if ($time < 0) {
            $timetext = get_string('late', 'assignment', format_time($time));
            return ' (<span class="late">'.$timetext.'</span>)';
        } else {
            $timetext = get_string('early', 'assignment', format_time($time));
            return ' (<span class="early">'.$timetext.'</span>)';
        }
    }





} ////// End of the assignment_base class



/// OTHER STANDARD FUNCTIONS ////////////////////////////////////////////////////////


function assignment_delete_instance($id){
    global $CFG;

    if (! $assignment = get_record('assignment', 'id', $id)) {
        return false;
    }

    require_once("$CFG->dirroot/mod/assignment/type/$assignment->assignmenttype/assignment.class.php");
    $assignmentclass = "assignment_$assignment->assignmenttype";
    $ass = new $assignmentclass();
    return $ass->delete_instance($assignment);
}


function assignment_update_instance($assignment){
    global $CFG;

    require_once("$CFG->dirroot/mod/assignment/type/$assignment->assignmenttype/assignment.class.php");
    $assignmentclass = "assignment_$assignment->assignmenttype";
    $ass = new $assignmentclass();
    return $ass->update_instance($assignment);
}    


function assignment_add_instance($assignment) {
    global $CFG;

    require_once("$CFG->dirroot/mod/assignment/type/$assignment->assignmenttype/assignment.class.php");
    $assignmentclass = "assignment_$assignment->assignmenttype";
    $ass = new $assignmentclass();
    return $ass->add_instance($assignment);
}


function assignment_user_outline($course, $user, $mod, $assignment) {
    global $CFG;

    require_once("$CFG->dirroot/mod/assignment/type/$assignment->assignmenttype/assignment.class.php");
    $assignmentclass = "assignment_$assignment->assignmenttype";
    $ass = new $assignmentclass($mod->id, $assignment, $mod, $course);
    return $ass->user_outline($user);
}

function assignment_user_complete($course, $user, $mod, $assignment) {
    global $CFG;

    require_once("$CFG->dirroot/mod/assignment/type/$assignment->assignmenttype/assignment.class.php");
    $assignmentclass = "assignment_$assignment->assignmenttype";
    $ass = new $assignmentclass($mod->id, $assignment, $mod, $course);
    return $ass->user_complete($user);
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
            $assignmentinfo->assignment = format_string($submission->name,true);
            $assignmentinfo->url = "$CFG->wwwroot/mod/assignment/view.php?id=$mod->id";

            $postsubject = "$course->shortname: $strassignments: ".format_string($submission->name,true);
            $posttext  = "$course->shortname -> $strassignments -> ".format_string($submission->name,true)."\n";
            $posttext .= "---------------------------------------------------------------------\n";
            $posttext .= get_string("assignmentmail", "assignment", $assignmentinfo);
            $posttext .= "---------------------------------------------------------------------\n";

            if ($user->mailformat == 1) {  // HTML
                $posthtml = "<p><font face=\"sans-serif\">".
                "<a href=\"$CFG->wwwroot/course/view.php?id=$course->id\">$course->shortname</a> ->".
                "<a href=\"$CFG->wwwroot/mod/assignment/index.php?id=$course->id\">$strassignments</a> ->".
                "<a href=\"$CFG->wwwroot/mod/assignment/view.php?id=$mod->id\">".format_string($submission->name,true)."</a></font></p>";
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

    } else { // Scale
        if ($grades) {
            $scaleid = - ($assignment->grade);
            if ($scale = get_record('scale', 'id', $scaleid)) {
                $scalegrades = make_menu_from_list($scale->scale);
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
    $students = get_records_sql("SELECT DISTINCT u.id, u.id
                                 FROM {$CFG->prefix}user u,
                                      {$CFG->prefix}assignment_submissions a
                                 WHERE a.assignment = '$assignmentid' and
                                       u.id = a.userid");
    //Get teachers
    $teachers = get_records_sql("SELECT DISTINCT u.id, u.id
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

    $rec = get_record('assignment','id',$assignmentid,'grade',-$scaleid);

    if (!empty($rec) && !empty($scaleid)) {
        $return = true;
    }

    return $return;
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


function assignment_print_recent_activity($course, $isteacher, $timestart) {
    global $CFG;

    $content = false;
    $assignments = NULL;

    if (!$logs = get_records_select('log', 'time > \''.$timestart.'\' AND '.
                                           'course = \''.$course->id.'\' AND '.
                                           'module = \'assignment\' AND '.
                                           'action = \'upload\' ', 'time ASC')) {
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
        print_headline(get_string('newsubmissions', 'assignment').':');
        foreach ($assignments as $assignment) {
            print_recent_activity_note($assignment->time, $assignment, $isteacher, $assignment->name,
                                       $CFG->wwwroot.'/mod/assignment/'.$assignment->url);
        }
        $content = true;
    }

    return $content;
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
                                           a.grade as maxgrade, name, cm.instance, cm.section, a.assignmenttype
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

          $tmpactivity = new Object;

          $tmpactivity->type = "assignment";
          $tmpactivity->defaultindex = $index;
          $tmpactivity->instance = $assignment->instance;
          $tmpactivity->name = $assignment->name;
          $tmpactivity->section = $assignment->section;

          $tmpactivity->content->grade = $assignment->grade;
          $tmpactivity->content->maxgrade = $assignment->maxgrade;
          $tmpactivity->content->type = $assignment->assignmenttype;

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
    global $CFG;

    echo '<table border="0" cellpadding="3" cellspacing="0">';

    echo "<tr><td class=\"userpicture\" width=\"35\" valign=\"top\">";
    print_user_picture($activity->user->userid, $course, $activity->user->picture);
    echo "</td><td width=\"100%\"><font size=2>";

    if ($detail) {
        echo "<img src=\"$CFG->modpixpath/$activity->type/icon.gif\" ".
             "height=16 width=16 alt=\"$activity->type\">  ";
        echo "<a href=\"$CFG->wwwroot/mod/assignment/view.php?id=" . $activity->instance . "\">"
             . format_string($activity->name,true) . "</a> - ";

    }

    if (isteacher($course)) {
        $grades = "(" .  $activity->content->grade . " / " . $activity->content->maxgrade . ") ";

        $assignment->id = $activity->instance;
        $assignment->course = $course;
        $user->id = $activity->user->userid;

        echo $grades;
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

/// GENERIC SQL FUNCTIONS

function assignment_log_info($log) {
    global $CFG;
    return get_record_sql("SELECT a.name, u.firstname, u.lastname
                             FROM {$CFG->prefix}assignment a, 
                                  {$CFG->prefix}user u
                            WHERE a.id = '$log->info' 
                              AND u.id = '$log->userid'");
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




/// OTHER GENERAL FUNCTIONS FOR ASSIGNMENTS  ///////////////////////////////////////


function assignment_types() {
    $types = array();
    $names = get_list_of_plugins('mod/assignment/type');
    foreach ($names as $name) {
        $types[$name] = get_string('type'.$name, 'assignment');
    }
    asort($types);
    return $types;
}

function assignment_upgrade_submodules() {
    global $CFG;

    $types = assignment_types();

    include_once($CFG->dirroot.'/mod/assignment/version.php');  // defines $module with version etc

    foreach ($types as $type) {

        $fullpath = $CFG->dirroot.'/mod/assignment/type/'.$type;

    /// Check for an external version file (defines $submodule)

        if (!is_readable($fullpath .'/version.php')) {
            continue;
        }
        unset($module);
        include_once($fullpath .'/version.php');

    /// Check whether we need to upgrade

        if (!isset($submodule->version)) {
            continue;
        }

    /// Make sure this submodule will work with this assignment version

        if (isset($submodule->requires) and ($submodules->requires > $module->version)) {
            notify("Assignment submodule '$type' is too new for your assignment");
            continue;
        }

    /// If we use versions, make sure an internal record exists

        $currentversion = 'assignment_'.$type.'_version';

        if (!isset($CFG->$currentversion)) {
            set_config($currentversion, 0);
        }

    /// See if we need to upgrade
        
        if ($submodule->version <= $CFG->$currentversion) {
            continue;
        }

    /// Look for the upgrade file

        if (!is_readable($fullpath .'/db/'.$CFG->dbtype.'.php')) {
            continue;
        }

        include_once($fullpath .'/db/'. $CFG->dbtype .'.php');  // defines assignment_xxx_upgrade

    /// Perform the upgrade

        $upgrade_function = 'assignment_'.$type.'_upgrade';
        if (function_exists($upgrade_function)) {
            $db->debug=true;
            if ($upgrade_function($CFG->$currentversion)) {
                $db->debug=false;
                set_config($currentversion, $submodule->version);
            }
            $db->debug=false;
        }
    }


}

?>
