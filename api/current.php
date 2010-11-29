<?php
/*
	Copyright 2010 Craig A Rodway <craig.rodway@gmail.com>
	
	This file is part of LoginTracker.

	LoginTracker is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	LoginTracker is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with LoginTracker.  If not, see <http://www.gnu.org/licenses/>.
*/


/**
 * Get current login sessions
 * Return as JSON data
 */

include_once('inc/init.php');

// Parameters
#$start = fRequest::get('start', 'integer');
$page = fRequest::get('page', 'integer', 1);
$limit = fRequest::get('rp', 'integer', 15);
$sort = fRequest::getValid('sortname', array('login_time', 'users.username', 'computer', 'ou', 'usertype'));
$dir = fRequest::getValid('sortorder', array('asc', 'desc'));
$duplicates = fRequest::get('duplicates', 'boolean', FALSE);
$filter = fRequest::getValid('filter', array('all', 'students', 'staff'));
// SQL start offset based on page number & limit
$start = ($page - 1) * $limit;

// Search query
#$query = fRequest::get('query', 'string?');
$query_type = fRequest::getValid('qtype', array(NULL, 'username', 'computer', 'location'));
$query_value = fRequest::get('query', 'string?');

// Extra HAVING clause if we are filtering for duplicates
$having = ($duplicates == TRUE) ? 'HAVING user_total > 1' : '';


// Search query
$search = '';
if( empty($query_type) && !empty($query_value) ){
	
	// If query type field is empty, search all possible fields for it
	
	$query_value = $db->escape('string', "%$query_value%");
	$search = " AND (
		users.username LIKE $query_value OR 
		hostnames.hostname LIKE $query_value OR
		ous.name LIKE $query_value
	) ";
	
} elseif( !empty($query_type) && !empty($query_value) ){
	
	// A specific query field is present - search on it only.
	
	$query_value = $db->escape('string', "%$query_value%");
	
	switch($query_type){
		case 'username':
			$search = " AND users.username LIKE $query_value ";
			break;
		case 'computer':
			$search = " AND hostnames.hostname LIKE $query_value ";
			break;
		case 'location':
			$search = " AND ous.name LIKE $query_value ";
			break;
	}
}


// Usertype filter applied? Append to existing search query
if($filter != 'all'){
	
	if($filter == 'students'){ $search .= " AND logins.type = 'STUDENT' "; }
	if($filter == 'staff'){ $search .= " AND logins.type = 'STAFF' "; }
	
}


// Create query
$sql = "SELECT 
			logins.session_id, 
			UNIX_TIMESTAMP(logins.login_time) AS login_time, 
			UNIX_TIMESTAMP(logins.logout_time) AS logout_time,
			logins.type AS usertype,
			hostnames.hostname AS computer,
			hostnames.hostname_id,
			ous.name AS ou,
			users.username AS username,
			users.user_id,
			(
				SELECT count(logins.session_id)
				FROM logins
				WHERE users.user_id = logins.user_id AND active = 1
			) AS user_total
		FROM logins
		LEFT JOIN hostnames ON logins.hostname_id = hostnames.hostname_id
		LEFT JOIN ous ON logins.ou_id = ous.ou_id
		LEFT JOIN users ON logins.user_id = users.user_id
		WHERE logins.active = 1
		$search
		$having
		ORDER BY %r $dir
		LIMIT %i, %i";

// Escape data for query
$sql = $db->escape($sql, array($sort, $start, $limit));

// Make a copy of the SQL string without limits (for pagination reasons)
$sql_no_limit = preg_replace('/LIMIT\s([0-9]+),\s([0-9]+)/', '', $sql);

// Remove LIMIT if loading duplicate login list
if($duplicates == TRUE){
	$sql = preg_replace('/LIMIT\s([0-9]+),\s([0-9]+)/', '', $sql);
}

try{
	
	// Run the query
	$sessions = $db->query($sql)->FetchAllRows();
	
	// Get session length for each row, update the array
	foreach($sessions as &$row){
		$logout_time = ($row['logout_time'] = '0000-00-00 00:00:00') ? time() : $row['logout_time'];
		$row['length'] = timespan($row['login_time'], $row['logout_time']);
		$row['login_time'] = date(DATE_FORMAT, $row['login_time']);
		$row['usertype_img'] = '<img src="normal/img/ico/user-' . strtolower($row['usertype']) . '.png" 
			width="16" height="16" title="' . strtolower($row['usertype']) . '" />';
	}
	
	if($duplicates == TRUE){
		$total['total'] = count($sessions);
	}
	
	
	// If total was not set in previous query (only when doing duplicate logins) then get it now
	if(!isset($total)){
		// Find out how many total rows there should be, without the limit
		$sql = "SELECT count(session_id) AS total 
				FROM logins
				LEFT JOIN hostnames ON logins.hostname_id = hostnames.hostname_id
				LEFT JOIN ous ON logins.ou_id = ous.ou_id
				LEFT JOIN users ON logins.user_id = users.user_id
				WHERE logins.active = 1 $search";
		$total = $db->query($sql)->fetchRow();
	}
	
	/*$rows = array();
	foreach($sessions as $session){
		$rows[] = array('id' => $session['session_id'], 'cell' => $session);
	}*/
	
	// Finally format array as JSON data
	$json['status'] = 'ok';
	$json['dupes'] = ($duplicates == TRUE) ? 'yes' : 'no';
	$json['total'] = $total['total'];
	$json['sort'] = $sort;
	$json['dir'] = $dir;
	$json['sessions'] = $sessions;
	$json['process'] = 'convertJSON';
	$json['page'] = $page;
	
	fJSON::output($json);
	exit;
	
} catch (fSQLException $e) {
	
	$json['status'] = 'err';
	$json['text'] = "Database error: " . $e->getMessage();
	fJSON::output($json);
	exit;
	
} catch (fNoRowsException $e) {
	
	$json['status'] = 'warn';
	$json['text'] = 'No results to return';
	fJSON::output($json);
	exit;
	
} catch (fException $e) {
	
	$json['status'] = 'err';
	$json['text'] = 'Unexpected error: ' . $e->getMessage();
	fJSON::output($json);
	exit;
	
}


/* End of file ./api/current.php */