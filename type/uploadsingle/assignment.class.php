<?php // $Id$

/**
 * Extend the base assignment class for assignments where you upload a single file
 *
 */
class assignment_uploadsingle extends assignment_base {
    

    function print_student_answer($userid, $return=false){
           global $CFG, $USER;
       
        $filearea = $this->file_area_name($userid);

        $output = '';
    
        if ($basedir = $this->file_area($userid)) {
            if ($files = get_directory_list($basedir)) {
                
                foreach ($files as $key => $file) {
                    require_once($CFG->libdir.'/filelib.php');
                    
                    $icon = mimeinfo('icon', $file);
                    
                    if ($CFG->slasharguments) {
                        $ffurl = "$CFG->wwwroot/file.php/$filearea/$file";
                    } else {
                        $ffurl = "$CFG->wwwroot/file.php?file=/$filearea/$file";
                    }
                    //died right here
                    //require_once($ffurl);                
                    $output = '<img align="middle" src="'.$CFG->pixpath.'/f/'.$icon.'" height="16" width="16" alt="'.$icon.'" />'.
                            '<a href="'.$ffurl.'" >'.$file.'</a><br />';
                }
            }
        }

        $output = '<div class="files">'.$output.'</div>';
        return $output;    
    }

    function assignment_uploadsingle($cmid=0) {
        parent::assignment_base($cmid);

    }

    function view() {
	
        global $USER;
		
		$context = get_context_instance(CONTEXT_MODULE,$this->cm->id);
        has_capability('mod/assignment:view', $context->id, true);
        
        add_to_log($this->course->id, "assignment", "view", "view.php?id={$this->cm->id}", $this->assignment->id, $this->cm->id);

        $this->view_header();

        $this->view_intro();

        $this->view_dates();

        $filecount = $this->count_user_files($USER->id);

        if ($submission = $this->get_submission()) {
            if ($submission->timemarked) {
                $this->view_feedback();
            } else if ($filecount) {
                print_simple_box($this->print_user_files($USER->id, true), 'center');
            }
        }

        if (has_capability('mod/assignment:submit', $context->id)  && $this->isopen() && (!$filecount || $this->assignment->resubmit || !$submission->timemarked)) {
            $this->view_upload_form();
        }

        $this->view_footer();
    }


    function view_upload_form() {
        global $CFG;
        $struploadafile = get_string("uploadafile");
        $strmaxsize = get_string("maxsize", "", display_size($this->assignment->maxbytes));

        echo '<center>';
        echo '<form enctype="multipart/form-data" method="post" '.
             "action=\"$CFG->wwwroot/mod/assignment/upload.php\">";
        echo "<p>$struploadafile ($strmaxsize)</p>";
        echo '<input type="hidden" name="id" value="'.$this->cm->id.'" />';
        require_once($CFG->libdir.'/uploadlib.php');
        upload_print_form_fragment(1,array('newfile'),false,null,0,$this->assignment->maxbytes,false);
        echo '<input type="submit" name="save" value="'.get_string('uploadthisfile').'" />';
        echo '</form>';
        echo '</center>';
    }


    function upload() {
        global $CFG, $USER;

        if (isguest($USER->id)) {
            error(get_string('guestnoupload','assignment'));
        }

        $this->view_header(get_string('upload'));

        $filecount = $this->count_user_files($USER->id);
        $submission = $this->get_submission($USER->id);
        if ($this->isopen() && (!$filecount || $this->assignment->resubmit || !$submission->timemarked)) {
            if ($submission = $this->get_submission($USER->id)) {
                //TODO: change later to ">= 0", to prevent resubmission when graded 0
                if (($submission->grade > 0) and !$this->assignment->resubmit) {
                    notify(get_string('alreadygraded', 'assignment'));
                }
            }

            $dir = $this->file_area_name($USER->id);

            require_once($CFG->dirroot.'/lib/uploadlib.php');
            $um = new upload_manager('newfile',true,false,$this->course,false,$this->assignment->maxbytes);
            if ($um->process_file_uploads($dir)) {
                $newfile_name = $um->get_new_filename();
                if ($submission) {
                    $submission->timemodified = time();
                    $submission->numfiles     = 1;
                    $submission->comment = addslashes($submission->comment);
                    unset($submission->data1);  // Don't need to update this.
                    unset($submission->data2);  // Don't need to update this.
                    if (update_record("assignment_submissions", $submission)) {
                        add_to_log($this->course->id, 'assignment', 'upload', 
                                'view.php?a='.$this->assignment->id, $this->assignment->id, $this->cm->id);
                        $this->email_teachers($submission);
                        print_heading(get_string('uploadedfile'));
                    } else {
                        notify(get_string("uploadfailnoupdate", "assignment"));
                    }
                } else {
                    $newsubmission = $this->prepare_new_submission($USER->id);
                    $newsubmission->numfiles = 1;
                    if (insert_record('assignment_submissions', $newsubmission)) {
                        add_to_log($this->course->id, 'assignment', 'upload', 
                                'view.php?a='.$this->assignment->id, $this->assignment->id, $this->cm->id);
                        $this->email_teachers($newsubmission);
                        print_heading(get_string('uploadedfile'));
                    } else {
                        notify(get_string("uploadnotregistered", "assignment", $newfile_name) );
                    }
                }
            }
        } else {
            notify(get_string("uploaderror", "assignment")); //submitting not allowed!
        }

        print_continue('view.php?id='.$this->cm->id);

        $this->view_footer();
    }




}

?>
