<?php
require_once '../../define_root.php';

// Set maximum execution time to 6 minutes as cron runs every 7 minutes. (There should be better way to do this)
$max_exec_time = 6 * 60;
set_time_limit($max_exec_time);

// Define vars
$start_time = microtime(true);
$start_date = date('r');
$lockfile = INCLUDE_ROOT . 'tmp/cron.lock';

/**  Define cron specific functions */

// log to formr log file
function cron_log($message) {
	$logfile = INCLUDE_ROOT . 'tmp/logs/formr_error.log';
	error_log(date('Y-m-d H:i:s') . ' ' . $message. "\n", 3, $logfile);
}

// clean up on shutdown
function cron_cleanup() {
	global $lockfile, $start_time, $max_exec_time;
	$exec_time = microtime(true) - $start_time;
	if ($exec_time >= $max_exec_time) {
		cron_log("Cron exceeded or reached set maximum script execution time of $max_exec_time secs.");
	}

	if (file_exists($lockfile)) {
		unlink($lockfile);
	}
}

function cron_parse_executed_types($types) {
	$str = '';
	foreach ($types as $key => $value) {
		$str .= " {$value} {$key}s,";
	}
	return $str;
}

// even though the cronjobs are supposed to run only 6 min and are spaced 7 min, there seem to be problems due to overlapping CJs
// the lockfile is supposed to fix this
if (file_exists($lockfile)) {
	global $start_date;
	$started = file_get_contents($lockfile);
	cron_log("Cron overlapped. Started: $started, Overlapped: $start_date");
	echo "Cron still running...";
	exit(0);
}

// Lock cron
file_put_contents($lockfile, $start_date);
register_shutdown_function('cron_cleanup');


/** Do the Work */
ob_start();

// Require necessary modules (solved with autoloader in next releases)
require_once INCLUDE_ROOT . "Model/Site.php";
require_once INCLUDE_ROOT . 'Model/Run.php';
require_once INCLUDE_ROOT . "View/header.php";
require_once INCLUDE_ROOT . "View/acp_nav.php";
require_once INCLUDE_ROOT . "Model/RunSession.php";
session_over($site, $user);

$user->cron = true;

// Get all runs
$g_runs = $fdb->query("SELECT name FROM `survey_runs` WHERE cron_active = 1 ORDER BY RAND();");
$runs = array();
while($tmp = $g_runs->fetch()) {
	$runs[] = $tmp;
}

$r = 0;
foreach($runs as $run_data):
	$i = 0;
	$r++;
	$done = array('Pause' => 0,'Email' => 0,'SkipForward' => 0, 'SkipBackward' => 0, 'Shuffle' => 0);
	$created = date('Y-m-d H:i:s');

	$run = new Run($fdb, $run_data['name']);
	if(!$run->valid) {
		alert("This run '{$run_data['name']}' caused problems", 'alert-danger');
		continue;
	}
	
	// get all session codes that have Branch, Pause, or Email lined up (not ended)
	$dues = $run->getCronDues();

	// Foreach session, execute all units
	foreach($dues as $session) {
		$run_session = new RunSession($fdb, $run->id, 'cron', $session);
		// Q. How will this go through all units of a session?
		$types = $run_session->getUnit(); // start looping thru their units.
		$i++;

		if($types === false):
			alert("This session '$session' caused problems", 'alert-danger');
			continue 1;
		endif;

		foreach($types as $type => $nr) {
			if (!isset($done[$type])) {
				$done[$type] = 0;
			}
			$done[$type] += $nr;
		}
	}

	// Build message report for current Run (saved in cron log)
	$alert_types = $site->alert_types;
	$alerts = $site->renderAlerts();
	$alerts = str_replace('<button type="button" class="close" data-dismiss="alert">&times;</button>', '', $alerts);

	$executed_types = cron_parse_executed_types($types);
	
	$msg = date( 'Y-m-d H:i:s' ) . ' ' . "$i sessions in the run ". $run->name. " were processed. {$executed_types} ended.<br>" . "\n";
	$msg .= $alerts;

	// Save cron log (This should be moved to logging in file system to avoid clustering DB)
	unset($done["Page"]);
	if(array_sum($done) > 0 OR array_sum($alert_types) > 0) {	
		$log = $fdb->prepare("
			INSERT INTO `survey_cron_log` (run_id, created, ended, sessions, skipforwards, skipbackwards, pauses, emails, shuffles, errors, warnings, notices, message)
			VALUES (:run_id, :created, NOW(), :sessions, :skipforwards, :skipbackwards, :pauses, :emails, :shuffles, :errors, :warnings, :notices, :message)");
		$log->bindParam(':run_id', $run->id);
		$log->bindParam(':created', $created);
		$log->bindParam(':sessions', $i);
		$log->bindParam(':skipforwards', $done['SkipForward']);
		$log->bindParam(':skipbackwards', $done['SkipBackward']);
		$log->bindParam(':pauses', $done['Pause']);
		$log->bindParam(':emails', $done['Email']);
		$log->bindParam(':shuffles', $done['Shuffle']);
		$log->bindParam(':errors', $alert_types['alert-danger']);
		$log->bindParam(':warnings', $alert_types['alert-warning']);
		$log->bindParam(':notices', $alert_types['alert-info']);
		$log->bindParam(':message', $msg);
		$log->execute();
	}

	echo $msg. "<br>";
	if(microtime() - $start_cron_time > 60 * 6):
		echo "Cronjob interrupted after running ". (microtime() - $start_cron_time) . " seconds";
		break;
	endif;
endforeach;

// error_log( $msg, 3, INCLUDE_ROOT ."tmp/logs/cron.log");
$user->cron = false;

require_once INCLUDE_ROOT . "View/footer.php";

ob_flush();
ob_clean();
// Q is buffering really needed?

// Do cleanup just in case
cron_cleanup();
