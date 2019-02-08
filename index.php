<?php
/**
 * This standalone tool displays the tasks that have been logged in Toggl time tracking app for a selected date range and
 * for a given project from a specified workspace.
 * Product page: https://orbisius.com/products/tools/orbisius-toggl-reports
 *
 * To request a customization contact the author.
 *
 * @author Svetoslav Marinov (Slavi) | http://orbisius.com
 * @license GPL v2. See the full license disclaimer below
 * @copyright 2019 All Rights Reserved.
 */

require_once dirname(__FILE__) . '/config.php';

/*
The GPL2 License
Copyright © 2019-3000 Svetoslav Marinov | orbisius.com
Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the “Software”), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:
The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.
THE SOFTWARE IS PROVIDED “AS IS”, WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE
FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION
WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
*/
//////////////////////////////////////////////////////////////////////////////////////////

// Sanitize data
$data = $_REQUEST;
$data = array_map('strip_tags', $data);
$data = array_map('trim', $data);

// https://stackoverflow.com/questions/1686724/how-to-find-the-last-day-of-the-month-from-date
$date = new DateTime();
$date->modify('last day of this month');
$default_first_date_of_the_month = date('Y-m-01');
$default_last_date_of_the_month = $date->format('Y-m-d');

$page = empty($data['page']) ? 1 : (int) $data['page'];
$page = $page <= 1 ? 1 : $page;
$req_url = $_SERVER['REQUEST_URI'];
$req_url = preg_replace('#\?.*#', '', $req_url);
$start_date = empty($data['start_date']) || @strtotime($data['start_date']) <= 0 ? $default_first_date_of_the_month : $data['start_date'];
$end_date = empty($data['end_date']) || @strtotime($data['end_date']) <= 0 ? $default_last_date_of_the_month : $data['end_date'];

// php is so smart :)
$first_day_last_month = date('Y-m-d', strtotime('first day of last month'));
$last_day_last_month = date('Y-m-d', strtotime('last day of last month'));

$api_params = array(
	'page' => $page,
	'project_ids' => $project_id,
	'workspace_id' => $workspace_id,
	'since' => $start_date,
	'until' => $end_date,
	'user_agent' => $admin_email,
	'calculate' => 'time',
);

// so we get a GET req.
$url .= '?' . http_build_query($api_params);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
//curl_setopt($ch, CURLOPT_POST, 1);
//curl_setopt($ch, CURLOPT_POSTFIELDS, $api_params); // we need get
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
curl_setopt($ch, CURLOPT_USERPWD, $api_token . ':api_token');

$content_json_maybe = curl_exec($ch);
$error = curl_error($ch);

if ( !empty( $error ) ) {
	echo "Error: $error\n";
	$info = curl_getinfo($ch);
	var_dump($info);
}

$result = json_decode($content_json_maybe, true);

if (!empty($result['data'])) {
	$last_month_link = $req_url . '?' . http_build_query([ 'last_month' => 1, 'start_date' => $first_day_last_month, 'end_date' => $last_day_last_month ]);

	$total_time_ms = empty($result['total_grand']) ? 0 : $result['total_grand'];
	$total_time = $total_time_ms / 1000;
	$total_time_fmt = orb_integr_toggl_format_time($total_time);
	echo "Total Time: [$total_time_fmt] | Start date: $start_date | End date: $end_date | ";

	if (empty($data['last_month'])) {
		echo "<a href='$last_month_link'>Last month stats</a>";
	} else {
		echo "<a href='$req_url'>This month stats</a>";
	}

	echo "<br/>\n<br/>\n";

	$total_records = $result['total_count'];
	$records_per_page = $result['per_page'];
	$total_pages = ceil($total_records / $records_per_page);

	if ($total_pages > 1) {
		//echo 'Pages:' . $total_pages;
		$page_pairs = array();

		for ($i = 1; $i <= $total_pages; $i++) {
			$link_data = $data;
			$link_data['page'] = $i;
			$link = $req_url . '?' . http_build_query($link_data);

			if ($page == $i) {
				$page_pairs[] = "<span>Page #$i</span>";
			} else {
				$page_pairs[] = "<a href='$link'>Page #$i</a>";
			}
		}

		echo join(' | ', $page_pairs);
		echo "<br/>\n<br/>\n";
	}

	echo "<table border='1'>\n";
	echo "<tr>\n";
	echo "<td>Date</td>";
	echo "<td>Task</td>";
	echo "<td>Duration</td>";
	echo "</tr>\n";

	foreach ($result['data'] as $rec) {
		echo "<tr>\n";

		$day_iso = $rec['start'];
		$date = $day_iso;
		$date = preg_replace('#T.*#si', '', $date); // rm iso sh**
		$dur_ms = $rec['dur'];
		$dur_sec = $dur_ms / 1000;
		$dur = orb_integr_toggl_format_time($dur_sec);
//		echo $date . ' | ' . $rec['description'] . ' | duration: ' . $dur . "<br/>\n";
		echo "<td>$date</td>";
		echo "<td>{$rec['description']}</td>";
		echo "<td>$dur</td>";
		echo "</tr>\n";
	}

	echo "</table>\n";
} else {
	echo "<pre style='color:red;'>";
	echo "Error:";
	var_dump($result);
	echo "</pre>";
}

curl_close($ch);

exit(0);

// https://stackoverflow.com/questions/3172332/convert-seconds-to-hourminutesecond
function orb_integr_toggl_format_time($t, $f=':') { // t = seconds, f = separator
	return sprintf("%02d%s%02d%s%02d", floor($t/3600), $f, ($t/60)%60, $f, $t%60);
}
