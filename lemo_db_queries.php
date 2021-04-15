<?php
// This file is part of Moodle - http://moodle.org/
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
 * This file contains the queries to the moodle database.
 *
 * The resutls of the queries are also already made
 * usable for the charts. This file is included in index.php.
 *
 * @package    block_lemo4moodle
 * @copyright  2020 Finn Ueckert, Margarita Elkina
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// SQL Query -> ActivityChart (date, hits, user counter).
$querylinechart = "SELECT FROM_UNIXTIME (timecreated, '%d-%m-%Y') AS 'date', COUNT(action) AS 'allHits',
                                COUNT(DISTINCT userid) AS 'users', COUNT(CASE WHEN " .  $DB->sql_compare_text('userid') . " = " . $DB->sql_compare_text(':userid') . "
                                THEN $userid END) AS 'ownhits'
                           FROM {logstore_standard_log}
                          WHERE (" .  $DB->sql_compare_text('action') . " = " . $DB->sql_compare_text(':action') . "
                                AND " .  $DB->sql_compare_text('courseid') . " = " . $DB->sql_compare_text(':courseid') . ")
                       GROUP BY FROM_UNIXTIME (timecreated, '%y-%m-%d')
                       ORDER BY 'date'";

//Query function parameters.
$params = ['userid' => $userid, 'action' => 'viewed', 'courseid' => $courseid];

$linechart = $DB->get_records_sql($querylinechart, $params);

unset($params);

// Transform result of the query from Object to an array of Objects.
$linechartdata = array();
foreach ($linechart as $l) {
    $linechartdata[] = $l;
}


// Get the first recorded date of the datasets (used to indicate the first date of data-timespan in index.php).
$splitdate = explode("-", $linechartdata[0]->date);
$firstdateindex = $splitdate[0] . '.' . $splitdate[1] . '.' . $splitdate[2];


// SQL Query for bar chart data.

$querybarchart = "SELECT LOGS1.id, FROM_UNIXTIME (LOGS1.timecreated, '%d-%m-%Y') AS 'date', LOGS1.contextid, LOGS1.userid, LOGS1.component
                    FROM {logstore_standard_log} LOGS1
                   WHERE " . $DB->sql_like('LOGS1.component', ':component') . "
                            AND " .  $DB->sql_compare_text('LOGS1.action') . " = " . $DB->sql_compare_text(':action2') . "
                            AND " .  $DB->sql_compare_text('LOGS1.courseid') . " = " . $DB->sql_compare_text(':courseid') . "
                            AND LOGS1.objecttable IS NOT NULL
                            AND " .  $DB->sql_compare_text('target') . " = " . $DB->sql_compare_text(':target');

//Query function parameters.
$params = ['component' => 'mod%', 'action2' => 'viewed', 'courseid' => $courseid, 'target' => 'course_module'];

// Perform SQL-Query.
$barchart = $DB->get_records_sql($querybarchart, $params);

unset($params);

// Get module information of the current course to later complement the query.
GLOBAL $COURSE;
// Use moodle function get_fast_modinfo().
$modinfo = get_fast_modinfo($COURSE);
$modulesarray = array();


// Add name, modulename and contextid of each object in the course to an associative array with the time an object was added to the course as key.
foreach ($modinfo->get_cms() as $cminfo) {
    $modulesarray[] = array('name' => $cminfo->name, 'module' => 'mod_' . $cminfo->modname, 'contextid' => $cminfo->context->id);
}

// Transform result of the query from Object to an array of Objects.
$barchartdatatemp = array();
foreach ($barchart as $b) {
    $barchartdatatemp[] = $b;
}

// Assign the objectname to each result of the barchart query by comparing the contextids from the
// objectlist ($modulesarray) with the contextid of each query result.
foreach($barchartdatatemp as $bd) {
    $found = false;
    foreach($modulesarray as $ma) {
        if($ma['contextid'] == $bd->contextid) {
            $bd->name = $ma['name'];
            $found = true;
            break;
        }
    }

    if($found == false) {
        $bd->contextid = 0;
    }

    // Replace the component (module) name with the string from the language file.
    $bd->component = get_string($bd->component, 'block_lemo4moodle');
}

// Filter out any not assigned/no longer existing objects.
$barchartdata = array();
foreach($barchartdatatemp as $bd) {
    if($bd->contextid != 0) {
        $barchartdata[] = $bd;
    }
}


// Query for heatmap.
$queryheatmap = "SELECT  id, timecreated, FROM_UNIXTIME(timecreated, '%W') AS 'weekday', FROM_UNIXTIME(timecreated, '%k') AS 'hour',
                            COUNT(action) AS 'allHits',  COUNT(CASE WHEN " .  $DB->sql_compare_text('userid') . " = " . $DB->sql_compare_text(':userid') . "
                            THEN $userid END) AS 'ownhits'
                       FROM {logstore_standard_log}
                      WHERE (" .  $DB->sql_compare_text('action') . " = " . $DB->sql_compare_text(':action') . "
                            AND " .  $DB->sql_compare_text('courseid') . " = " . $DB->sql_compare_text(':courseid') . ")
                     GROUP BY timecreated";

//Query function parameters.
$params = ['userid' => $userid, 'action' => 'viewed', 'courseid' => $courseid];

$heatmap = $DB->get_records_sql($queryheatmap, $params);

unset($params);

// Transform result of the query from Object to array of Objects.
$heatmaptdata = array();
foreach ($heatmap as $h) {
    $h->date = date("d-m-Y", $h->timecreated);
    $heatmapdata[] = $h;
}


// Create dataarray.
// Data as JSON [activityData[date, overallHits, ownhits, users], barchartdata[name, hits, users],
// treemapdata[name, title, hits, color(as int)]].
$dataarray = array();
$dataarray[] = $linechartdata;
$dataarray[] = $barchartdata;
$dataarray[] = $heatmapdata;
//$dataarray[] = $treemapdataarray;

// Encode dataarray as JSON !JSON_NUMERIC_CHECK.
$alldata = str_replace("'", "\'", json_encode($dataarray));
$alldatahtml = str_replace("'", "&#39;", json_encode($dataarray));
