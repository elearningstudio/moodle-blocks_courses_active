<?php
// This file is part of the active courses block
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
 * Returns list of courses current $USER is enrolled in and can access
 *
 * - $fields is an array of field names to ADD
 *   so name the fields you really need, which will
 *   be added and uniq'd
 *
 * @param string|array $fields
 * @param string $sort
 * @param int $limit max number of courses
 * @return array
 */
function get_my_incomplete_courses($fields = null, $sort = 'visible DESC,sortorder ASC', $limit = 0) {
    global $DB, $USER;

    // Guest account does not have any courses.
    if (isguestuser() or !isloggedin()) {
        return(array());
    }

    // Active courses (courses with completion tracking enabled).
    $courses_sql = "SELECT c.id, c.shortname, c.fullname FROM {course_completions} cc, {course} c
                WHERE cc.course = c.id
                    AND cc.timeenrolled > 0
                    AND cc.timestarted > 0
                    AND cc.timecompleted IS NULL
                    AND cc.userid = :userid
                ORDER BY c.shortname ASC";
    
    $params = array();
    $params['userid'] = $USER->id;

    $courses = $DB->get_records_sql($courses_sql, $params);

    // Preload contexts and check visibility.
    foreach ($courses as $id => $course) {
        context_instance_preload($course);
        $courses[$id] = $course;
    }

    // Exclude courses with completions status of active, inactive or completed.
    $exclude_courses = $DB->get_records_select('course_completions', 'timeenrolled > 0 AND userid = '.$USER->id, null, 'course');
    // Preload contexts and check visibility.
    foreach ($exclude_courses as $id => $exclude_course) {
        $excluded[] = $exclude_course->course;
    }

    $excluded = implode(',', $excluded);

    $mycourses = get_my_other_courses(null, 'visible DESC,sortorder ASC', 0, $excluded);
    // Preload contexts and check visibility.
    foreach ($mycourses as $myid => $mycourse) {
        context_instance_preload($mycourse);
        $mycourses[$myid] = $mycourse;
    }

    $all_courses = array_merge($courses, $mycourses);

    return $all_courses;
}

/**
 * Returns list of courses current $USER is enrolled in and can access
 *
 * - $fields is an array of field names to ADD
 *   so name the fields you really need, which will
 *   be added and uniq'd
 *
 * @param string|array $fields
 * @param string $sort
 * @param int $limit max number of courses
 * @return array
 */
function get_my_other_courses($fields = null, $sort = 'visible DESC,sortorder ASC', $limit = 0, $exclude = null) {
    global $DB, $USER;

    // Guest account does not have any courses.
    if (isguestuser() or !isloggedin()) {
        return(array());
    }

    $basefields = array('id', 'category', 'sortorder',
        'shortname', 'fullname', 'idnumber',
        'startdate', 'visible',
        'groupmode', 'groupmodeforce');

    if (empty($fields)) {
        $fields = $basefields;
    } else if (is_string($fields)) {
        // Turn the fields from a string to an array.
        $fields = explode(',', $fields);
        $fields = array_map('trim', $fields);
        $fields = array_unique(array_merge($basefields, $fields));
    } else if (is_array($fields)) {
        $fields = array_unique(array_merge($basefields, $fields));
    } else {
        throw new coding_exception('Invalid $fileds parameter in enrol_get_my_courses()');
    }
    if (in_array('*', $fields)) {
        $fields = array('*');
    }

    $orderby = "";
    $sort = trim($sort);
    if (!empty($sort)) {
        $rawsorts = explode(',', $sort);
        $sorts = array();
        foreach ($rawsorts as $rawsort) {
            $rawsort = trim($rawsort);
            if (strpos($rawsort, 'c.') === 0) {
                $rawsort = substr($rawsort, 2);
            }
            $sorts[] = trim($rawsort);
        }
        $sort = 'c.' . implode(',c.', $sorts);
        $orderby = "ORDER BY $sort";
    }

    $wheres = array("c.id <> :siteid");
    $params = array('siteid' => SITEID);

    if (isset($USER->loginascontext) and $USER->loginascontext->contextlevel == CONTEXT_COURSE) {
        // List _only_ this course - anything else is asking for trouble...
        $wheres[] = "courseid = :loginas";
        $params['loginas'] = $USER->loginascontext->instanceid;
    }

    $coursefields = 'c.' . join(',c.', $fields);
    list($ccselect, $ccjoin) = context_instance_preload_sql('c.id', CONTEXT_COURSE, 'ctx');
    $wheres = implode(" AND ", $wheres);

    // Note: we can not use DISTINCT + text fields due to Oracle and MS limitations, that is why we have the subselect there.
    $sql = "SELECT $coursefields $ccselect
              FROM {course} c
              JOIN (SELECT DISTINCT e.courseid
                      FROM {enrol} e
                      JOIN {user_enrolments} ue ON (ue.enrolid = e.id AND ue.userid = :userid)
                      WHERE ue.status = :active AND e.status = :enabled AND ue.timestart < :now1
                      AND (ue.timeend = 0 OR ue.timeend > :now2)
                   ) en ON (en.courseid = c.id)
           $ccjoin
             WHERE $wheres AND c.id NOT IN ($exclude)
          $orderby";

    $params['userid'] = $USER->id;
    $params['active'] = ENROL_USER_ACTIVE;
    $params['enabled'] = ENROL_INSTANCE_ENABLED;
    $params['now1'] = round(time(), -2); // Improves db caching.
    $params['now2'] = $params['now1'];

    $courses = $DB->get_records_sql($sql, $params, 0, $limit);

    // Preload contexts and check visibility.
    foreach ($courses as $id => $course) {
        context_instance_preload($course);
        if (!$course->visible) {
            if (!$context = get_context_instance(CONTEXT_COURSE, $id)) {
                unset($courses[$id]);
                continue;
            }
            if (!has_capability('moodle/course:viewhiddencourses', $context)) {
                unset($courses[$id]);
                continue;
            }
        }
        $courses[$id] = $course;
    }

    return $courses;
}
