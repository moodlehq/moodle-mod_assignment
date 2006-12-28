<?php // $Id$
require_once($CFG->libdir.'/formslib.php');

/**
 * Extend the base assignment class for assignments where you upload a single file
 *
 */
class assignment_online extends assignment_base {

    function assignment_online($cmid=0) {
        parent::assignment_base($cmid);
    }

    function view() {

        global $USER;

        $edit  = optional_param('edit', 0, PARAM_BOOL);
        $saved = optional_param('saved', 0, PARAM_BOOL);

        $context = get_context_instance(CONTEXT_MODULE, $this->cm->id);
        require_capability('mod/assignment:view', $context);

        $submission = $this->get_submission();

        //Guest can not submit nor edit an assignment (bug: 4604)
        if (!has_capability('mod/assignment:submit', $context)) {
            $editable = null;
        } else {
            $editable = $this->isopen() && (!$submission || $this->assignment->resubmit || !$submission->timemarked);
        }
        $editmode = ($editable and $edit);

        if ($editmode) {
            //guest can not edit or submit assignment
            if (!has_capability('mod/assignment:submit', $context)) {
                error(get_string('guestnosubmit', 'assignment'));
            }
        }

/// prepare form and process submitted data
        $mform = new assignment_online_edit_form('view.php');

        $defaults = new object();
        $defaults->id = $this->cm->id;
        if (!empty($submission)) {
            if ($this->usehtmleditor) {
                $options = new object();
                $options->smiley = false;
                $options->filter = false;

                $defaults->text   = format_text($submission->data1, $submission->data2, $options);
                $defaults->format = FORMAT_HTML;
            } else {
                $defaults->text   = $submission->data1;
                $defaults->format = $submission->data2;
            }
        }
        $mform->set_defaults($defaults);

        if ($mform->is_cancelled()) {
            redirect('view.php?id='.$this->cm->id);
        }

        if ($data = $mform->data_submitted()) {      // No incoming data?
            if ($editable && $this->update_submission($data)) {
                //TODO fix log actions - needs db upgrade
                $submission = $this->get_submission();
                add_to_log($this->course->id, 'assignment', 'upload',
                        'view.php?a='.$this->assignment->id, $this->assignment->id, $this->cm->id);
                $this->email_teachers($submission);
                //redirect to get updated submission date and word count
                redirect('view.php?id='.$this->cm->id.'&saved=1');
            } else {
                // TODO: add better error message
                notify(get_string("error")); //submitting not allowed!
            }
        }

/// print header, etc. and display form if needed
        if ($editmode) {
            $this->view_header(get_string('editmysubmission', 'assignment'));
        } else {
            $this->view_header();
        }

        $this->view_intro();

        $this->view_dates();

        if ($saved) {
            notify(get_string('submissionsaved', 'assignment'), 'notifysuccess');
        }

        if (has_capability('mod/assignment:submit', $context)) {
            print_simple_box_start('center', '70%', '', 0, 'generalbox', 'online');
            if ($editmode) {
                $mform->display();
            } else {
                if ($submission) {
                    echo format_text($submission->data1, $submission->data2);
                } else if (!has_capability('mod/assignment:submit', $context)) { //fix for #4604
                    echo '<center>'. get_string('guestnosubmit', 'assignment').'</center>';
                } else if ($this->isopen()){    //fix for #4206
                    echo '<center>'.get_string('emptysubmission', 'assignment').'</center>';
                }
                if ($editable) {
                    print_single_button('view.php', array('id'=>$this->cm->id, 'edit'=>'1'),
                                         get_string('editmysubmission', 'assignment'));
                }
            }
            print_simple_box_end();

        }

        $this->view_feedback();

        $this->view_footer();
    }

    /*
     * Display the assignment dates
     */
    function view_dates() {
        global $USER, $CFG;

        if (!$this->assignment->timeavailable && !$this->assignment->timedue) {
            return;
        }

        print_simple_box_start('center', '', '', 0, 'generalbox', 'dates');
        echo '<table>';
        if ($this->assignment->timeavailable) {
            echo '<tr><td class="c0">'.get_string('availabledate','assignment').':</td>';
            echo '    <td class="c1">'.userdate($this->assignment->timeavailable).'</td></tr>';
        }
        if ($this->assignment->timedue) {
            echo '<tr><td class="c0">'.get_string('duedate','assignment').':</td>';
            echo '    <td class="c1">'.userdate($this->assignment->timedue).'</td></tr>';
        }
        $submission = $this->get_submission($USER->id);
        if ($submission) {
            echo '<tr><td class="c0">'.get_string('lastedited').':</td>';
            echo '    <td class="c1">'.userdate($submission->timemodified);
        /// Decide what to count
            if ($CFG->assignment_itemstocount == ASSIGNMENT_COUNT_WORDS) {
                echo ' ('.get_string('numwords', '', count_words(format_text($submission->data1, $submission->data2))).')</td></tr>';
            } else if ($CFG->assignment_itemstocount == ASSIGNMENT_COUNT_LETTERS) {
                echo ' ('.get_string('numletters', '', count_letters(format_text($submission->data1, $submission->data2))).')</td></tr>';
            }
        }
        echo '</table>';
        print_simple_box_end();
    }

    function update_submission($data) {
        global $CFG, $USER;

        $submission = $this->get_submission($USER->id, true);

        $update = new object();
        $update->id           = $submission->id;
        $update->data1        = $data->text;
        $update->data2        = $data->format;
        $update->timemodified = time();

        return update_record('assignment_submissions', $update);
    }


    function print_student_answer($userid, $return=false){
        global $CFG;
        if (!$submission = $this->get_submission($userid)) {
            return '';
        }
        $output = '<div class="files">'.
                  '<img align="middle" src="'.$CFG->pixpath.'/f/html.gif" height="16" width="16" alt="html" />'.
                  link_to_popup_window ('/mod/assignment/type/online/file.php?id='.$this->cm->id.'&amp;userid='.
                  $submission->userid, 'file'.$userid, shorten_text(trim(strip_tags(format_text($submission->data1,$submission->data2))), 15), 450, 580,
                  get_string('submission', 'assignment'), 'none', true).
                  '</div>';
                  return $output;
    }

    function print_user_files($userid, $return=false) {
        global $CFG;

        if (!$submission = $this->get_submission($userid)) {
            return '';
        }

        $output = '<div class="files">'.
                  '<img align="middle" src="'.$CFG->pixpath.'/f/html.gif" height="16" width="16" alt="html" />'.
                  link_to_popup_window ('/mod/assignment/type/online/file.php?id='.$this->cm->id.'&amp;userid='.
                  $submission->userid, 'file'.$userid, shorten_text(trim(strip_tags(format_text($submission->data1,$submission->data2))), 15), 450, 580,
                  get_string('submission', 'assignment'), 'none', true).
                  '</div>';

        ///Stolen code from file.php

        print_simple_box_start('center', '', '', 0, 'generalbox', 'wordcount');
        echo '<table>';
        //if ($assignment->timedue) {
        //    echo '<tr><td class="c0">'.get_string('duedate','assignment').':</td>';
        //    echo '    <td class="c1">'.userdate($assignment->timedue).'</td></tr>';
        //}
        echo '<tr>';//<td class="c0">'.get_string('lastedited').':</td>';
        echo '    <td class="c1">';//.userdate($submission->timemodified);
        /// Decide what to count
            if ($CFG->assignment_itemstocount == ASSIGNMENT_COUNT_WORDS) {
                echo ' ('.get_string('numwords', '', count_words(format_text($submission->data1, $submission->data2))).')</td></tr>';
            } else if ($CFG->assignment_itemstocount == ASSIGNMENT_COUNT_LETTERS) {
                echo ' ('.get_string('numletters', '', count_letters(format_text($submission->data1, $submission->data2))).')</td></tr>';
            }
        echo '</table>';
        print_simple_box_end();
        print_simple_box(format_text($submission->data1, $submission->data2), 'center', '100%');

        ///End of stolen code from file.php

        if ($return) {
            //return $output;
        }
        //echo $output;
    }

    function preprocess_submission(&$submission) {
        if ($this->assignment->var1 && empty($submission->submissioncomment)) {  // comment inline
            if ($this->usehtmleditor) {
                // Convert to html, clean & copy student data to teacher
                $submission->submissioncomment = format_text($submission->data1, $submission->data2);
                $submission->format = FORMAT_HTML;
            } else {
                // Copy student data to teacher
                $submission->submissioncomment = $submission->data1;
                $submission->format = $submission->data2;
            }
        }
    }

}

class mod_assignment_online_edit_form extends moodleform {
    function definition() {
        $mform =& $this->_form;

        // visible elements
        $mform->addElement('htmleditor', 'text', get_string('submission', 'assignment'), array('cols'=>85, 'rows'=>30));
        $mform->setType('text', PARAM_RAW); // to be cleaned before display

        $mform->addElement('format', 'format', get_string('format'));
        $mform->setHelpButton('format', array('textformat', get_string('helpformatting')));

        // hidden params
        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);

        // buttons
        $this->add_action_buttons();
    }
}

?>
