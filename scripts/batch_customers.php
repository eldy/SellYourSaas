#!/usr/bin/php
<?php
/* Copyright (C) 2007-2018 Laurent Destailleur  <eldy@users.sourceforge.net>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 * or see http://www.gnu.org/*
 */

/**
 *      \file       sellyoursaas/scripts/batch_customers.php
 *		\ingroup    sellyoursaas
 *      \brief      Main SellYourSaas batch:
 *      			- to run on master hosts for action updatedatabase|updatecountsonly|updatestatsonly
 *      			- to run on deployment hosts for action backup* (payed customers rsync + databases backup)
 */

if (!defined('NOREQUIREDB')) define('NOREQUIREDB', '1');					// Do not create database handler $db
if (!defined('NOSESSION')) define('NOSESSION', '1');
if (!defined('NOREQUIREVIRTUALURL')) define('NOREQUIREVIRTUALURL', '1');

$sapi_type = php_sapi_name();
$script_file = basename(__FILE__);
$path=dirname($_SERVER['PHP_SELF']).'/';

// Test if batch mode
if (substr($sapi_type, 0, 3) == 'cgi') {
	echo "Error: You are using PHP for CGI. To execute ".$script_file." from command line, you must use PHP for CLI mode.\n";
	exit(-1);
}
if (0 == posix_getuid()) {
	echo "Script must NOT be ran with root (but with the 'admin' sellyoursaas account).\n";
	print "\n";
	exit(-1);
}

// Global variables
$version='1.0';
$error=0;


// -------------------- START OF YOUR CODE HERE --------------------
@set_time_limit(0);							// No timeout for this script
define('EVEN_IF_ONLY_LOGIN_ALLOWED', 1);		// Set this define to 0 if you want to lock your script when dolibarr setup is "locked to admin user only".

// Load Dolibarr environment
$res=0;
// Try master.inc.php into web root detected using web root caluclated from SCRIPT_FILENAME
$tmp=empty($_SERVER['SCRIPT_FILENAME'])?'':$_SERVER['SCRIPT_FILENAME'];$tmp2=realpath(__FILE__); $i=strlen($tmp)-1; $j=strlen($tmp2)-1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i]==$tmp2[$j]) { $i--; $j--; }
if (! $res && $i > 0 && file_exists(substr($tmp, 0, ($i+1))."/master.inc.php")) $res=@include substr($tmp, 0, ($i+1))."/master.inc.php";
if (! $res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i+1)))."/master.inc.php")) $res=@include dirname(substr($tmp, 0, ($i+1)))."/master.inc.php";
// Try master.inc.php using relative path
if (! $res && file_exists("../master.inc.php")) $res=@include "../master.inc.php";
if (! $res && file_exists("../../master.inc.php")) $res=@include "../../master.inc.php";
if (! $res && file_exists("../../../master.inc.php")) $res=@include "../../../master.inc.php";
if (! $res && file_exists(__DIR__."/../../master.inc.php")) $res=@include __DIR__."/../../master.inc.php";
if (! $res && file_exists(__DIR__."/../../../master.inc.php")) $res=@include __DIR__."/../../../master.inc.php";
if (! $res) {
	print "Include of master fails";
	exit(-1);
}
// After this $db, $mysoc, $langs, $conf and $hookmanager are defined (Opened $db handler to database will be closed at end of file).
// $user is created but empty.

include_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';
include_once DOL_DOCUMENT_ROOT.'/core/class/utils.class.php';
include_once dol_buildpath("/sellyoursaas/backoffice/lib/refresh.lib.php");		// This set $serverprice

// Global variables
$FORCE=0;
if (!empty($argv[2]) && $argv[2] == '--force') {
	unset($argv[2]);
	$FORCE=1;
}
if (!empty($argv[3]) && $argv[3] == '--force') {
	$FORCE=1;
}

// Read /etc/sellyoursaas.conf file
$domain='';
$databasehost='localhost';
$databaseport='3306';
$database='';
$databaseuser='sellyoursaas';
$databasepass='';
$ipserverdeployment='';
$emailfrom='';
$emailsupervision='';
$usemastermailserver='';
$fp = @fopen('/etc/sellyoursaas.conf', 'r');
// Add each line to an array
if ($fp) {
	$array = explode("\n", fread($fp, filesize('/etc/sellyoursaas.conf')));
	foreach ($array as $val) {
		$tmpline=explode("=", $val);
		if ($tmpline[0] == 'domain') {
			$domain = dol_string_nospecial($tmpline[1]);
		}
		if ($tmpline[0] == 'databasehost') {
			$databasehost = $tmpline[1];
		}
		if ($tmpline[0] == 'databaseport') {
			$databaseport = $tmpline[1];
		}
		if ($tmpline[0] == 'database') {
			$database = $tmpline[1];
		}
		if ($tmpline[0] == 'databaseuser') {
			$databaseuser = $tmpline[1];
		}
		if ($tmpline[0] == 'databasepass') {
			$databasepass = $tmpline[1];
		}
		if ($tmpline[0] == 'ipserverdeployment') {
			$ipserverdeployment = $tmpline[1];
		}
		if ($tmpline[0] == 'emailfrom') {
			$emailfrom = $tmpline[1];
		}
		if ($tmpline[0] == 'emailsupervision') {
			$emailsupervision = $tmpline[1];
		}
		if ($tmpline[0] == 'usemastermailserver') {
			$usemastermailserver = $tmpline[1];
		}
	}
} else {
	print "Failed to open /etc/sellyoursaas.conf file\n";
	exit(-1);
}
if (empty($emailfrom)) {
	$emailfrom="noreply@".$domain;
}
if (empty($emailsupervision)) {
	$emailsupervision="supervision@".$domain;
}

$sendcontext = 'emailing';
//$sendcontext = 'standard';



/*
 * Main
 */

$dbmaster=getDoliDBInstance('mysqli', $databasehost, $databaseuser, $databasepass, $database, $databaseport);
if ($dbmaster->error) {
	dol_print_error($dbmaster, "host=".$databasehost.", port=".$databaseport.", user=".$databaseuser.", databasename=".$database.", ".$dbmaster->error);

	$from = $emailfrom;
	$to = $emailsupervision;
	// Supervision tools are generic for all domain. No way to target a specific supervision email.
	// Force to use local sending (MAIN_MAIL_SENDMODE is the one of the master server. It may be to an external SMTP server not allowed to the deployment server)
	if (empty($usemastermailserver)) {
		$conf->global->MAIN_MAIL_SENDMODE = 'mail';
		$conf->global->MAIN_MAIL_SMTP_SERVER = 'localhost';
	}

	$msg = 'Error in '.$script_file." ".(empty($argv[1]) ? '' : $argv[1])." ".(empty($argv[2]) ? '' : $argv[2])." (finished at ".dol_print_date(dol_now('gmt'), "%Y%m%d-%H%M%S", 'gmt').")\n\n".$dbmaster->error;

	include_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
	print 'Send email MAIN_MAIL_SENDMODE='.getDolGlobalString('MAIN_MAIL_SENDMODE').' MAIN_MAIL_SMTP_SERVER='.getDolGlobalString('MAIN_MAIL_SMTP_SERVER').' from='.$from.' to='.$to.' title=[Warning] Error(s) in backups - '.gethostname().' - '.dol_print_date(dol_now(), 'dayrfc')."\n";
	$cmail = new CMailFile('[Warning] Error(s) in backups - '.gethostname().' - '.dol_print_date(dol_now(), 'dayrfc'), $to, $from, $msg, array(), array(), array(), '', '', 0, 0, '', '', '', '', $sendcontext);
	$result = $cmail->sendfile();		// Use the $conf->global->MAIN_MAIL_SMTPS_PW_$SENDCONTEXT for password

	exit(-1);
}
if ($dbmaster) {
	$conf->setValues($dbmaster);
}
if (empty($db)) $db=$dbmaster;

// Set serverprice with the param from $conf of the $dbmaster server.
$serverprice = empty($conf->global->SELLYOURSAAS_INFRA_COST)?'100':$conf->global->SELLYOURSAAS_INFRA_COST;

//$langs->setDefaultLang('en_US'); 	// To change default language of $langs
$langs->load("main");				// To load language file for default language
@set_time_limit(0);					// No timeout for this script

// Load user and its permissions
//$result=$user->fetch('','admin');	// Load user for login 'admin'. Comment line to run as anonymous user.
//if (! $result > 0) { dol_print_error('',$user->error); exit(-1); }
//$user->getrights();


print "***** ".$script_file." (".$version.") - ".dol_print_date(dol_now('gmt'), "%Y%m%d-%H%M%S", 'gmt')." *****\n";
if (! isset($argv[1])) {	// Check parameters
	print "Usage on master            : ".$script_file." (updatedatabase|updatecountsonly|updatestatsonly) [instancefilter] [--force]\n";
	print "Usage on deployment servers: ".$script_file." backup... [instancefilter] [--force]\n";
	print "\n";
	print "action can be:\n";
	print "- updatecountsonly    updates metrics of instances only (list and nb of users for each instance)\n";
	print "- updatestatsonly     updates stats only (only table sellyoursaas_stats) and send data to Datagog if enabled ***** Used by cron on master server *****\n";
	print "- updatedatabase      (=updatecountsonly+updatestatsonly) updates list and nb of users, modules and version and stats table.\n";
	print "- backuptest          test rsync+database backup\n";
	print "- backuptestrsync     test rsync backup\n";
	print "- backuptestdatabase  test database backup\n";
	print "- backuprsync         creates backup (rsync)\n";
	print "- backupdatabase      creates backup (mysqldump)\n";
	print "- backup              creates backup (rsync + database) ***** Used by cron on deployment servers *****\n";
	print "- backupdelete        creates backup (rsync with delete + database)\n";
	print "\n";
	print "with a backup... action, you can also add the option --force to execute backup even if done recently.\n";

	exit(-1);
}
print '--- start script with mode '.$argv[1]."\n";
//print 'Argument 1='.$argv[1]."\n";
//print 'Argument 2='.$argv[2]."\n";

$now = dol_now();

$action=$argv[1];
$nbofok=0;
$nbofokdiscarded=0;


// Initialize the array $instances*


// Nb of deployed instances
$nbofinstancedeployed=0;
// Nb of errors
$nboferrors=0;
// List of instances
$instances=array();				// array of paid instances
$instancestrial=array();		// array of trial instances
$instancespaidsuspended=array();
$instancespaidsuspendedandpaymenterror=array();
$instancespaidnotsuspended=array();
$instancespaidnotsuspendedpaymenterror=array();
$instancesbackuperror=array();
$instancesupdateerror=array();
$instancesbackupsuccess=array();
$instancesbackupsuccessdiscarded=array();


$instancefilter=(isset($argv[2])?$argv[2]:'');
$instancefiltercomplete=$instancefilter;

// Forge complete name of instance
if (! empty($instancefiltercomplete) && ! preg_match('/\./', $instancefiltercomplete) && ! preg_match('/\.home\.lan$/', $instancefiltercomplete)) {
	if (empty(getDolGlobalString('SELLYOURSAAS_OBJECT_DEPLOYMENT_SERVER_MIGRATION'))) {
		$tmparray = explode(',', getDolGlobalString('SELLYOURSAAS_SUB_DOMAIN_NAMES'));
	} else {
		dol_include_once('sellyoursaas/class/deploymentserver.class.php');
		$staticdeploymentserver = new Deploymentserver($db);
		$tmparray = $staticdeploymentserver->fetchAllDomains();
	}
	$tmpstring = preg_replace('/:.*$/', '', $tmparray[0]);
	$instancefiltercomplete = $instancefiltercomplete.".".$tmpstring;   // Automatically concat first domain name
}

include_once DOL_DOCUMENT_ROOT.'/contrat/class/contrat.class.php';
$object=new Contrat($dbmaster);

// Get list of instance
$sql = "SELECT c.rowid as id, c.ref, c.ref_customer as instance,";
$sql.= " ce.deployment_status as instance_status, ce.latestbackup_date_ok, ce.backup_frequency";
$sql.= " FROM ".MAIN_DB_PREFIX."contrat as c LEFT JOIN ".MAIN_DB_PREFIX."contrat_extrafields as ce ON c.rowid = ce.fk_object";
$sql.= " WHERE c.ref_customer <> '' AND c.ref_customer IS NOT NULL";
if ($instancefiltercomplete) {
	$stringforsearch = '';
	$tmparray = explode(',', $instancefiltercomplete);
	foreach ($tmparray as $instancefiltecompletevalue) {
		if (! empty($stringforsearch)) $stringforsearch.=", ";
		$stringforsearch.="'".trim($instancefiltecompletevalue)."'";
	}
	$sql.= " AND c.ref_customer IN (".$stringforsearch.")";
} else {
	$sql.= " AND ce.deployment_status = 'done'";		// Get 'deployed' only, but only if we don't request a specific instance
}
$sql.= " AND ce.deployment_status IS NOT NULL";
$sql.= " AND (ce.suspendmaintenance_message IS NULL OR ce.suspendmaintenance_message NOT LIKE 'http%')";	// Exclude instance of type redirect
// Add filter on deployment server
if (preg_match('/backup/', $action)) {
	$sql.=" AND ce.deployment_host = '".$dbmaster->escape($ipserverdeployment)."'";
}

$dbtousetosearch = $dbmaster;

print $sql."\n";                                    // To have this into the ouput of cron job

dol_syslog($script_file, LOG_DEBUG);

$resql=$dbtousetosearch->query($sql);
if ($resql) {
	$num = $dbtousetosearch->num_rows($resql);
	$i = 0;
	if ($num) {
		// Loop on each deployed instance/contract
		while ($i < $num) {
			$obj = $dbtousetosearch->fetch_object($resql);
			if ($obj) {
				// We process the instance
				$instance = $obj->instance;

				dol_include_once('/sellyoursaas/lib/sellyoursaas.lib.php');

				unset($object->linkedObjects);
				unset($object->linkedObjectsIds);

				// Load data of instance and set $instance_status (PROCESSING, DEPLOYED, SUSPENDED, UNDEPLOYED)
				$instance_status = 'UNKNOWN';
				$result = $object->fetch($obj->id);
				if ($result <= 0) {
					$i++;
					dol_print_error($dbmaster, $object->error, $object->errors);
					continue;
				} else {
					if ($object->array_options['options_deployment_status'] == 'processing') {
						$instance_status = 'PROCESSING';
					} elseif ($object->array_options['options_deployment_status'] == 'undeployed') {
						$instance_status = 'UNDEPLOYED';
					} elseif ($object->array_options['options_deployment_status'] == 'done') {
						// should be here due to test into SQL request
						$instance_status = 'DEPLOYED';
						$nbofinstancedeployed++;
					}
				}

				if ($instance_status == 'DEPLOYED') {
					$issuspended = sellyoursaasIsSuspended($object);
					if ($issuspended) {
						$instance_status = 'SUSPENDED';
					}
					// Note: to check that all non deployed instance has line with status that is 5 (close), you can run
					// select * from llx_contrat as c, llx_contrat_extrafields as ce, llx_contratdet as cd WHERE ce.fk_object = c.rowid
					// AND cd.fk_contrat = c.rowid AND ce.deployment_status <> 'done' AND cd.statut <> 5;
					// You should get nothing.
				}

				// Set $payment_status ('TRIAL', 'PAID' or 'FAILURE')
				$payment_status='PAID';
				$ispaid = sellyoursaasIsPaidInstance($object);	// This load linkedObjectsIds
				if (! $ispaid) {
					$payment_status='TRIAL';
				} else {
					$ispaymentko = sellyoursaasIsPaymentKo($object);
					if ($ispaymentko) {
						$payment_status='FAILURE';
					}
				}

				print "Analyze".($instancefiltercomplete ? '' : ' deployed')." instance ".($i+1)." ".$instance.": instance_status=".$instance_status." payment_status=".$payment_status.(empty($object->array_options['options_suspendmaintenance_message']) ? "" : " (maintenance/redirect: ".($object->array_options['options_suspendmaintenance_message']).")")."\n";

				// Count
				if (! in_array($payment_status, array('TRIAL'))) {
					// We analyze all non trial deployed instances
					if (in_array($instance_status, array('SUSPENDED'))) {
						$instancespaidsuspended[$obj->id] = $obj->ref.' ('.$instance.')';
						if (in_array($payment_status, array('FAILURE'))) {
							$instancespaidsuspendedandpaymenterror[$obj->id] = $obj->ref.' ('.$instance.')';
						}
					} else {
						$instancespaidnotsuspended[$obj->id] = $obj->ref.' ('.$instance.')';
						if (in_array($payment_status, array('FAILURE'))) {
							$instancespaidnotsuspendedpaymenterror[$obj->id] = $obj->ref.' ('.$instance.')';
						}
					}

					$instances[$obj->id] = array('id'=>$obj->id, 'ref'=>$obj->ref, 'instance'=>$instance, 'latestbackup_date_ok'=>$dbtousetosearch->jdate($obj->latestbackup_date_ok), 'backup_frequency'=>$obj->backup_frequency);
					print "Qualify instance ".$instance." with instance_status=".$instance_status." payment_status=".$payment_status."\n";
				} elseif ($instancefiltercomplete) {
					$instances[$obj->id] = array('id'=>$obj->id, 'ref'=>$obj->ref, 'instance'=>$instance, 'latestbackup_date_ok'=>$dbtousetosearch->jdate($obj->latestbackup_date_ok), 'backup_frequency'=>$obj->backup_frequency);
					print "Qualify instance ".$instance." with instance_status=".$instance_status." payment_status=".$payment_status."\n";
				} else {
					$instancestrial[$obj->id] = array('id'=>$obj->id, 'ref'=>$obj->ref, 'instance'=>$instance, 'latestbackup_date_ok'=>$dbtousetosearch->jdate($obj->latestbackup_date_ok), 'backup_frequency'=>$obj->backup_frequency);
					//print "Found instance ".$instance." with instance_status=".$instance_status." instance_status_bis=".$instance_status_bis." payment_status=".$payment_status."\n";
				}
			}
			$i++;
		}
	}
} else {
	$error++;
	$nboferrors++;
	dol_print_error($dbtousetosearch);
}
print "We found ".count($instancestrial)." deployed trial + ".count($instances)." deployed paid instances including ".count($instancespaidsuspended)." suspended + ".count($instancespaidnotsuspendedpaymenterror)." active with payment ko\n";


//print "----- Start loop for backup_instance\n";
if ($action == 'backup' || $action == 'backupdelete' ||$action == 'backuprsync' || $action == 'backupdatabase' || $action == 'backuptest' || $action == 'backuptestrsync' || $action == 'backuptestdatabase') {
	if (empty($conf->global->DOLICLOUD_BACKUP_PATH)) {
		print "Error: Setup of module SellYourSaas not complete. Path to backup not defined.\n";
		exit(-1);
	}

	// Loop on each instance
	if (! $error) {
		$i = 0;
		foreach ($instances as $arrayofinstance) {
			$instance = $arrayofinstance['instance'];

			$now = dol_now();

			$return_val=0; $error=0; $errors=array();	// No error by default into each loop

			$qualifiedforbackup = 1;
			// TODO Use a frequency on contract to know if we have to do backup or not
			$frequencyindayforbackup = max(1, (int) $arrayofinstance['backup_frequency']);
			if ($arrayofinstance['latestbackup_date_ok'] > ($now - ($frequencyindayforbackup * 24 * 3600) + (12 * 3600))) {
				$qualifiedforbackup = 0;
			}

			if (empty($qualifiedforbackup) && empty($FORCE)) {
				// Discard backup
				print "***** Discard backup of paid instance ".($i+1)." ".$instance.' at '.dol_print_date(dol_now('gmt'), "%Y%m%d-%H%M%S", 'gmt')." - Already successfull recently the ".dol_print_date($arrayofinstance['latestbackup_date_ok'], "%Y%m%d-%H%M%S").".\n";

				$nbofokdiscarded++;
				$instancesbackupsuccessdiscarded[$instance] = array('date' => dol_now('gmt'));
			} else {
				// Run backup
				print "***** Process backup of paid instance ".($i+1)." ".$instance.' at '.dol_print_date(dol_now('gmt'), "%Y%m%d-%H%M%S", 'gmt')." - Previous success was on ".dol_print_date($arrayofinstance['latestbackup_date_ok'], "%Y%m%d-%H%M%S").".\n";

				$mode = 'unknown';
				$mode = ($action == 'backup'?'confirm':$mode);
				$mode = ($action == 'backupdelete'?'confirm':$mode);
				$mode = ($action == 'backuprsync'?'confirmrsync':$mode);
				$mode = ($action == 'backupdatabase'?'confirmdatabase':$mode);
				$mode = ($action == 'backuptest'?'test':$mode);
				$mode = ($action == 'backuptestdatabase'?'testdatabase':$mode);
				$mode = ($action == 'backuptestrsync'?'testrsync':$mode);

				$command = ($path?$path:'')."backup_instance.php ".escapeshellarg($instance)." ".escapeshellarg($conf->global->DOLICLOUD_BACKUP_PATH)." ".$mode;
				if ($action == 'backupdelete') {
					$command .= ' --delete';
				}
				if ($FORCE) {
					$command .= ' --forcersync --forcedump';
				}
				//$command .= " --notransaction";
				$command .= " --quick";

				echo $command."\n";

				if ($action == 'backup' || $action == 'backupdelete' ||$action == 'backuprsync' || $action == 'backupdatabase') {
					$utils = new Utils($db);
					$outputfile = $conf->admin->dir_temp.'/out.tmp';
					$resultarray = $utils->executeCLI($command, $outputfile);

					$return_val = $resultarray['result'];
					$content_grabbed = $resultarray['output'];

					echo "Result: ".$return_val."\n";
					echo "Output: ".$content_grabbed."\n";
				}

				if ($return_val != 0) {
					$error++;
				}

				// Return
				if (! $error) {
					$nbofok++;
					$instancesbackupsuccess[$instance] = array('date' => dol_now('gmt'));
					print '-> Backup process success for '.$instance."\n";
					sleep(2);	// On success, we wait 2 seconds
				} else {
					$nboferrors++;
					$instancesbackuperror[$instance] = array('date' => dol_now('gmt'));
					print '-> Backup process fails for '.$instance."\n";
					sleep(5);	// On error, we wait 5 seconds
				}
			}

			$i++;
		}
	}
}


$today=dol_now();

$error=''; $errors=array();
$servicetouse=strtolower($conf->global->SELLYOURSAAS_NAME);

if ($action == 'updatedatabase' || $action == 'updatestatsonly' || $action == 'updatecountsonly') {	// updatedatabase = updatestatsonly + updatecountsonly
	print "----- Start updatedatabase\n";

	dol_include_once('sellyoursaas/class/sellyoursaasutils.class.php');
	$sellyoursaasutils = new SellYourSaasUtils($dbmaster);


	if (! $error && $action != 'updatestatsonly') {		// make updatecountsonly so refresh remote metrics only
		$i=0;
		// Loop on each paid instance
		foreach ($instances as $arrayofinstance) {
			$instance = $arrayofinstance['instance'];

			$return_val=0; $error=0; $errors=array();

			// Run database update
			print "Process update database info (nb of user) of instance ".($i+1)." ".$instance.' - '.dol_print_date(dol_now('gmt'), "%Y%m%d-%H%M%S", 'gmt')." : ";

			$dbmaster->begin();

			$result=$object->fetch('', '', $instance);
			if ($result < 0) dol_print_error('', $object->error);

			$object->oldcopy=dol_clone($object, 1);

			$result = $sellyoursaasutils->sellyoursaasRemoteAction('refreshmetrics', $object);
			if ($result <= 0) {
				$errors[] = 'Failed to do sellyoursaasRemoteAction(refresh) '.$sellyoursaasutils->error.(is_array($sellyoursaasutils->errors)?' '.join(',', $sellyoursaasutils->errors):'');
			}

			if (count($errors) == 0) {
				print "OK\n";

				$nbofok++;
				$dbmaster->commit();
			} else {
				$nboferrors++;
				$instancesupdateerror[$instance] = array('date' => dol_now('gmt'));
				print 'KO. '.join(',', $errors)."\n";
				$dbmaster->rollback();
			}

			$i++;
		}
	}


	if (! $error && $action != 'updatecountsonly') {	// make updatestatsonly so count existing instances only
		$stats=array();

		// Load list of existing stats into $stats
		// name can be 'total', 'totalcommissions', 'totalinstancepaying', 'totalinstances', 'totalusers', 'benefit', 'totalcustomers', 'totalcustomerspaying'...
		$sql ="SELECT name, x, y";
		$sql.=" FROM ".MAIN_DB_PREFIX."sellyoursaas_stats";
		$sql.=" WHERE service = '".$dbmaster->escape($servicetouse)."'";
		$sql.=" ORDER BY x, name";

		dol_syslog($script_file."", LOG_DEBUG);
		$resql=$dbmaster->query($sql);
		if ($resql) {
			$num = $dbmaster->num_rows($resql);
			$i = 0;
			if ($num) {
				while ($i < $num) {
					$obj = $dbmaster->fetch_object($resql);
					if ($obj) {
						$stats[$obj->name][$obj->x]=$obj->y;
						print "Found stats for ".$obj->name." x=".$obj->x." y=".$obj->y."\n";
					}
					$i++;
				}
			}
		} else {
			$error++;
			$nboferrors++;
			dol_print_error($dbmaster);
		}
		//print "Found already existing stats entries.\n";

		$tmp=dol_getdate(dol_now('tzserver'));
		$endyear=$tmp['year'];
		if (empty($serverprice)) {
			print 'ERROR Value of variable $serverprice is not defined.';
			exit(-1);
		}

		// Update all missing stats (we start from january of previous year, but update will be done only if stats not yet
		// already calculated or if it is stats of current month)
		$YEARSTART = $endyear - 1;
		for ($year = $YEARSTART; $year <= $endyear; $year++) {
			for ($m = 1; $m <= 12; $m++) {
				$datefirstday=dol_get_first_day($year, $m, 1);
				$datelastday=dol_get_last_day($year, $m, 1);
				if ($datefirstday > $today) {
					continue;
				}

				//$statkeylist=array('total','totalcommissions','totalinstancespaying','totalinstancespayingall','totalinstances','totalusers','totalcustomers','totalcustomerspaying','benefit','serverprice');
				$statkeylist=array(
					'total', 'totalcommissions', 'totalnewinstances', 'totallostinstances',
					'totalinstancespaying','totalinstancespayingall','totalinstances','totalusers','totalcustomers','totalcustomerspaying',
					'benefit','serverprice',
					'newinstances', 'lostinstances'
				);

				$x=sprintf("%04d%02d", $year, $m);

				$dowehavetomakeupdatefordate = 0;
				foreach ($statkeylist as $statkey) {
					if (! isset($stats[$statkey][$x]) || ($today <= $datelastday)) {	// If metric does not exist yet or if we are current month.
						$dowehavetomakeupdatefordate = 1;
						break;
					}
				}

				if ($dowehavetomakeupdatefordate) {
					// Update stats for the metric
					print 'Calculate statistics for x='.$x."\n";

					$rep = sellyoursaas_calculate_stats($dbmaster, $datelastday, $datefirstday);	// Get qty and amount into all template invoices linked to active contracts deployed before the $datelastday

					$part = (empty($conf->global->SELLYOURSAAS_PERCENTAGE_FEE) ? 0 : $conf->global->SELLYOURSAAS_PERCENTAGE_FEE);

					foreach ($statkeylist as $statkey) {
						if (! isset($stats[$statkey][$x]) || ($today <= $datelastday)) {	// If metric does not exist yet or if we are current month.
							// Update stats for the metric $statkey
							print "Update stats for ".$statkey." x=".$x.' datelastday='.dol_print_date($datelastday, 'dayhour', 'gmt');

							if ($rep) {
								$total = $rep['total'];
								$totalcommissions = $rep['totalcommissions'];
								$totalnewinstances = $rep['totalnewinstances'];
								$totallostinstances = $rep['totallostinstances'];
								$totalinstancespaying = $rep['totalinstancespaying'];
								$totalinstancespayingall = $rep['totalinstancespayingall'];
								$totalinstances = $rep['totalinstances'];		// total trial only instances
								$totalusers = $rep['totalusers'];
								$totalcustomerspaying = $rep['totalcustomerspaying'];
								$totalcustomers = $rep['totalcustomers'];
								$newinstances = count($rep['listofnewinstances']);
								$lostinstances = count($rep['listoflostinstances']);
								$benefit = ($total * (1 - $part) - $serverprice - $totalcommissions);

								$y=0;
								if ($statkey == 'total') $y=$total;
								if ($statkey == 'totalcommissions') $y=$totalcommissions;
								if ($statkey == 'totalnewinstances') $y=$totalnewinstances;
								if ($statkey == 'totallostinstances') $y=$totallostinstances;
								if ($statkey == 'totalinstancespaying') $y=$totalinstancespaying;
								if ($statkey == 'totalinstancespayingall') $y=$totalinstancespayingall;
								if ($statkey == 'totalinstances') $y=$totalinstances;
								if ($statkey == 'totalusers') $y=$totalusers;
								if ($statkey == 'totalcustomerspaying') $y=$totalcustomerspaying;
								if ($statkey == 'totalcustomers') $y=$totalcustomers;
								if ($statkey == 'newinstances') $y=$newinstances;
								if ($statkey == 'lostinstances') $y=$lostinstances;
								if ($statkey == 'serverprice') $y=$serverprice;
								if ($statkey == 'benefit') $y=$benefit;

								print " -> ".$y."\n";

								if ($today <= $datelastday) {	// Remove existing entry if current month
									$sql ="DELETE FROM ".MAIN_DB_PREFIX."sellyoursaas_stats";
									$sql.=" WHERE name = '".$dbmaster->escape($statkey)."' AND x='".$dbmaster->escape($x)."'";
									$sql.=" AND service = '".$dbmaster->escape($servicetouse)."'";
									dol_syslog("sql=".$sql);
									$resql = $dbmaster->query($sql);
									if (! $resql) {
										dol_print_error($dbmaster, '');
									}
								}

								$sql = "INSERT INTO ".MAIN_DB_PREFIX."sellyoursaas_stats(service, name, x, y)";
								$sql .= " VALUES('".$dbmaster->escape($servicetouse)."', '".$dbmaster->escape($statkey)."', '".$dbmaster->escape($x)."', ".((float) $y).")";

								$resql = $dbmaster->query($sql);
								//if (! $resql) dol_print_error($dbmaster,'');		// Ignore error, we may have duplicate record here if record already exists and not deleted
							}
						}
					}	// end of loop on each metric
				}	// if we have to make update for this period
			}	// end loop on month
		} // end loop on year
	}
}




// Output result
$out = '';
if ($action == 'backup' || $action == 'backupdelete' ||$action == 'backuprsync' || $action == 'backupdatabase' || $action == 'backuptest' || $action == 'backuptestrsync' || $action == 'backuptestdatabase') {
	$out.= "\n";
	$out.= "***** Summary for host ".$ipserverdeployment."\n";
} else {
	$out.= "***** Summary for all deployment servers\n";
}
$out.= "** Nb of instances deployed: ".$nbofinstancedeployed."\n\n";
$out.= "** Nb of paying instances (deployed with or without payment error): ".count($instances)."\n\n";	// $instance is qualified instances
$out.= "** Nb of paying instances (deployed suspended): ".count($instancespaidsuspended)."\n";
$out.= (count($instancespaidsuspended)?"Suspension on ".join(', ', $instancespaidsuspended)."\n\n":"\n");
$out.= "** Nb of paying instances (deployed suspended and payment error): ".count($instancespaidsuspendedandpaymenterror)."\n";
$out.= (count($instancespaidsuspendedandpaymenterror)?"Suspension and payment error on ".join(', ', $instancespaidsuspendedandpaymenterror)."\n\n":"\n");
$out.= "** Nb of paying instances (deployed not suspended): ".count($instancespaidnotsuspended)."\n\n";
$out.= "** Nb of paying instances (deployed not suspended but payment error): ".count($instancespaidnotsuspendedpaymenterror)."\n";
$out.= (count($instancespaidnotsuspendedpaymenterror)?"Not yet suspended but payment error on ".join(', ', $instancespaidnotsuspendedpaymenterror)."\n\n":"\n");

if ($action != 'updatestatsonly') {
	$out.= "** Nb of paying instances processed ko: ".$nboferrors;
}
if (count($instancesbackuperror)) {
	$out.= ", ERROR FOR BACKUP ON ";
	foreach ($instancesbackuperror as $instance => $val) {
		$out .= $instance.' ('.dol_print_date($val['date'], 'standard').') ';
	}
}
if (count($instancesupdateerror)) {
	$out.= ", error for update on ";
	foreach ($instancesupdateerror as $instance => $val) {
		$out .= $instance.' ('.dol_print_date($val['date'], 'standard').') ';
	}
}

$out.= "\n\n";

if ($action != 'updatestatsonly') {
	$out.= "** Nb of paying instances processed ok+discarded: ".$nbofok."+".$nbofokdiscarded."=".($nbofok + $nbofokdiscarded);
}
if (count($instancesbackupsuccess)) {
	$out.= ", success for backup on ";
	foreach ($instancesbackupsuccess as $instance => $val) {
		$out .= $instance.' ('.dol_print_date($val['date'], 'standard').') ';
	}
}
if (count($instancesbackupsuccessdiscarded)) {
	$out.= ", discarded for backup on ";
	foreach ($instancesbackupsuccessdiscarded as $instance => $val) {
		$out .= $instance.' ('.dol_print_date($val['date'], 'standard').') ';
	}
}
$out.= "\n\n";

print $out;

// Write instances into tmp file
$createlistofpaidinstance = 0;
if ($createlistofpaidinstance) {
	if ($handle = fopen('/tmp/listofpaidinstances', 'w')) {
		foreach ($instances as $id => $arrayofinstance) {
			$instance = $arrayofinstance['instance'];

			fwrite($handle, $id." ".$instance."\n");
		}
		fclose($handle);
	}
}

// Send to DataDog (metric)
if ($action == 'updatestatsonly') {
	if (! empty($conf->global->SELLYOURSAAS_DATADOG_ENABLED)) {
		try {
			print 'Send data to DataDog (sellyoursaas.instancedeployed='.((float) $nbofinstancedeployed).', sellyoursaas.instancepaymentko='.((float) (count($instancespaidsuspended) + count($instancespaidnotsuspendedpaymenterror))).', sellyoursaas.instancepaymentok='.((float) (count($instances) - (count($instancespaidsuspended) + count($instancespaidnotsuspendedpaymenterror)))).")\n";
			dol_include_once('/sellyoursaas/core/includes/php-datadogstatsd/src/DogStatsd.php');

			$arrayconfig=array();
			if (! empty($conf->global->SELLYOURSAAS_DATADOG_APIKEY)) {
				$arrayconfig=array('apiKey'=>$conf->global->SELLYOURSAAS_DATADOG_APIKEY, 'app_key' => $conf->global->SELLYOURSAAS_DATADOG_APPKEY);
			}

			$statsd = new DataDog\DogStatsd($arrayconfig);

			$arraytags=null;
			$statsd->gauge('sellyoursaas.instancedeployed', (float) ($nbofinstancedeployed), 1.0, $arraytags);
			$statsd->gauge('sellyoursaas.instancepaymentko', (float) (count($instancespaidsuspended) + count($instancespaidnotsuspendedpaymenterror)), 1.0, $arraytags);
			$statsd->gauge('sellyoursaas.instancepaymentok', (float) (count($instances) - (count($instancespaidsuspended) + count($instancespaidnotsuspendedpaymenterror))), 1.0, $arraytags);
		} catch (Exception $e) {
			print 'Failed to send to datadog';
		}
	}
}

if (! $nboferrors) {
	print '--- end OK - '.dol_print_date(dol_now('gmt'), "%Y%m%d-%H%M%S", 'gmt')."\n";

	if ($action == 'backup' || $action == 'backupdelete' ||$action == 'backuprsync' || $action == 'backupdatabase' || $action == 'backuptest' || $action == 'backuptestrsync' || $action == 'backuptestdatabase') {
		if (empty($instancefilter)) {
			$from = $emailfrom;
			$to = $emailsupervision;
			// Force to use local sending (MAIN_MAIL_SENDMODE is the one of the master server. It may be to an external SMTP server not allowed to the deployment server)
			if (empty($usemastermailserver)) {
				$conf->global->MAIN_MAIL_SENDMODE = 'mail';
				$conf->global->MAIN_MAIL_SMTP_SERVER = 'localhost';
			}

			$msg = 'Backup done without errors on '.gethostname().' by '.$script_file." ".(empty($argv[1]) ? '' : $argv[1])." ".(empty($argv[2]) ? '' : $argv[2])." (finished at ".dol_print_date(dol_now('gmt'), "%Y%m%d-%H%M%S", 'gmt').")\n\n".$out;

			$sellyoursaasname = $conf->global->SELLYOURSAAS_NAME;                 // exemple 'DoliCloud'
			$sellyoursaasdomain = $conf->global->SELLYOURSAAS_MAIN_DOMAIN_NAME;   // exemple 'dolicloud.com'

			/*$domainname=getDomainFromURL($_SERVER['SERVER_NAME'], 1);
			$constforaltname = 'SELLYOURSAAS_NAME_FORDOMAIN-'.$domainname;
			if (! empty($conf->global->$constforaltname)) {
				$sellyoursaasdomain = $domainname;
				$sellyoursaasname = $conf->global->$constforaltname;
			}*/

			include_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
			print 'Send email MAIN_MAIL_SENDMODE='.getDolGlobalString('MAIN_MAIL_SENDMODE').' MAIN_MAIL_SMTP_SERVER='.getDolGlobalString('MAIN_MAIL_SMTP_SERVER').' from='.$from.' to='.$to.' title=[Backup instances - '.gethostname().'] Backup of user instances succeed'."\n";
			$cmail = new CMailFile('[Backup instances - '.gethostname().'] Backup of user instances succeed', $to, $from, $msg, array(), array(), array(), '', '', 0, 0, '', '', '', '', $sendcontext);
			$result = $cmail->sendfile();		// Use the $conf->global->MAIN_MAIL_SMTPS_PW_$SENDCONTEXT for password
		} else {
			print 'Script was called for a given instance. No email or indicator sent in such situation'."\n";
		}
	}
} else {
	print '--- end ERROR nb='.$nboferrors.' - '.dol_print_date(dol_now('gmt'), "%Y%m%d-%H%M%S", 'gmt')."\n";

	if ($action == 'backup' || $action == 'backupdelete' ||$action == 'backuprsync' || $action == 'backupdatabase' || $action == 'backuptest' || $action == 'backuptestrsync' || $action == 'backuptestdatabase') {
		if (empty($instancefilter)) {
			$from = $emailfrom;
			$to = $emailsupervision;
			// Force to use local sending (MAIN_MAIL_SENDMODE is the one of the master server. It may be to an external SMTP server not allowed to the deployment server)
			if (empty($usemastermailserver)) {
				$conf->global->MAIN_MAIL_SENDMODE = 'mail';
				$conf->global->MAIN_MAIL_SMTP_SERVER = 'localhost';
			}

			// Supervision tools are generic for all domains. No way to target a specific supervision email.

			$msg = 'Error in '.$script_file." ".$argv[1]." ".$argv[2]." (finished at ".dol_print_date(dol_now('gmt'), "%Y%m%d-%H%M%S", 'gmt').")\n\n".$out;

			include_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
			print 'Send email MAIN_MAIL_SENDMODE='.getDolGlobalString('MAIN_MAIL_SENDMODE').' MAIN_MAIL_SMTP_SERVER='.getDolGlobalString('MAIN_MAIL_SMTP_SERVER').' from='.$from.' to='.$to.' title=[Warning] Error(s) in backups - '.gethostname().' - '.dol_print_date(dol_now(), 'dayrfc')."\n";
			$cmail = new CMailFile('[Warning] Error(s) in backups - '.gethostname().' - '.dol_print_date(dol_now(), 'dayrfc'), $to, $from, $msg, array(), array(), array(), '', '', 0, 0, '', '', '', '', $sendcontext);
			$result = $cmail->sendfile();		// Use the $conf->global->MAIN_MAIL_SMTPS_PW_$SENDCONTEXT for password

			// Send to DataDog (metric + event)
			if (! empty($conf->global->SELLYOURSAAS_DATADOG_ENABLED)) {
				try {
					dol_include_once('/sellyoursaas/core/includes/php-datadogstatsd/src/DogStatsd.php');

					$arrayconfig=array();
					if (! empty($conf->global->SELLYOURSAAS_DATADOG_APIKEY)) {
						$arrayconfig=array('apiKey'=>$conf->global->SELLYOURSAAS_DATADOG_APIKEY, 'app_key' => $conf->global->SELLYOURSAAS_DATADOG_APPKEY);
					}

					$statsd = new DataDog\DogStatsd($arrayconfig);

					//$arraytags=array('result'=>'ko');
					//$statsd->increment('sellyoursaas.backup', 1, $arraytags);

					$sellyoursaasname = $conf->global->SELLYOURSAAS_NAME;                 // exemple 'My SaaS Service'
					$sellyoursaasdomain = $conf->global->SELLYOURSAAS_MAIN_DOMAIN_NAME;   // exemple 'mysaasdomain.com'

					/*$domainname=getDomainFromURL($_SERVER['SERVER_NAME'], 1);
					$constforaltname = 'SELLYOURSAAS_NAME_FORDOMAIN-'.$domainname;
					if (! empty($conf->global->$constforaltname)) {
						$sellyoursaasdomain = $domainname;
						$sellyoursaasname = $conf->global->$constforaltname;
					}*/

					$titleofevent =  dol_trunc('[Warning] '.$sellyoursaasname.' - '.gethostname().' - Backup in error', 90);
					$statsd->event($titleofevent,
						array(
							'text'       => $titleofevent." : \n".$msg,
							'alert_type' => 'warning',
							'source_type_name' => 'API',
							'host'       => gethostname()
							)
						);

					print "Event sent to DataDog\n";
				} catch (Exception $e) {
					print "Failed to send event to datadog\n";
				}
			}
		} else {
			print 'Script was called for only one given instance. No email or supervision event sent in such situation'."\n";
		}
	}
}

$dbmaster->close();	// Close database opened handler

exit($nboferrors);
