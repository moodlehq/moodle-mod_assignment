#
# Table structure for table assignment
#

CREATE TABLE assignment (
  id SERIAL PRIMARY KEY,
  course integer NOT NULL default '0',
  name varchar(255) NOT NULL default '',
  description text NOT NULL default '',
  format integer NOT NULL default '0',
  type integer NOT NULL default '1',
  maxbytes integer NOT NULL default '100000',
  timedue integer NOT NULL default '0',
  grade integer NOT NULL default '0',
  timemodified integer NOT NULL default '0'
);
# --------------------------------------------------------

#
# Table structure for table assignment_submissions
#

CREATE TABLE assignment_submissions (
  id SERIAL PRIMARY KEY,
  assignment integer NOT NULL default '0',
  "user" integer NOT NULL default '0',
  timecreated integer NOT NULL default '0',
  timemodified integer NOT NULL default '0',
  numfiles integer NOT NULL default '0',
  grade integer NOT NULL default '0',
  comment text NOT NULL default '',
  teacher integer NOT NULL default '0',
  timemarked integer NOT NULL default '0',
  mailed integer NOT NULL default '0'
);
# --------------------------------------------------------


INSERT INTO log_display VALUES ('assignment', 'view', 'assignment', 'name');
INSERT INTO log_display VALUES ('assignment', 'add', 'assignment', 'name');
INSERT INTO log_display VALUES ('assignment', 'update', 'assignment', 'name');
INSERT INTO log_display VALUES ('assignment', 'view submissions', 'assignment', 'name');
INSERT INTO log_display VALUES ('assignment', 'upload', 'assignment', 'name');

