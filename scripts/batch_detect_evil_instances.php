#!/usr/bin/php
<?php
/* Copyright (C) 2007-2022 Laurent Destailleur  <eldy@users.sourceforge.net>
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
 *      \file       sellyoursaas/scripts/batch_detect_evil_instances.php
 *		\ingroup    sellyoursaas
 *      \brief      Script to detect evils instances by scanning inside its data for blacklist content
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
include_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
include_once DOL_DOCUMENT_ROOT.'/core/class/utils.class.php';
include_once dol_buildpath("/sellyoursaas/class/blacklistip.class.php");
include_once dol_buildpath("/sellyoursaas/class/blacklistfrom.class.php");
include_once dol_buildpath("/sellyoursaas/class/blacklistto.class.php");
include_once dol_buildpath("/sellyoursaas/class/blacklistcontent.class.php");
include_once dol_buildpath("/sellyoursaas/class/blacklistdir.class.php");


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

// Read /etc/sellyoursaas-public.conf file
$maxemailperday = 0;
$maxemailperdaypaid = 0;
$pathtospamdir = '/tmp/spam';
$fp = @fopen('/etc/sellyoursaas-public.conf', 'r');
// Add each line to an array
if ($fp) {
	$array = explode("\n", fread($fp, filesize('/etc/sellyoursaas-public.conf')));
	foreach ($array as $val) {
		$tmpline=explode("=", $val);
		if ($tmpline[0] == 'maxemailperday') {
			$maxemailperday = $tmpline[1];
		}
		if ($tmpline[0] == 'maxemailperdaypaid') {
			$maxemailperdaypaid = $tmpline[1];
		}
		if ($tmpline[0] == 'pathtospamdir') {
			$pathtospamdir = $tmpline[1];
		}
	}
} else {
	print "ERROR Failed to open /etc/sellyoursaas-public.conf file\n";
	//exit(-1);
}
if (is_numeric($maxemailperday) && $maxemailperday > 0) {
	$MAXPERDAY = (int) $maxemailperday;
}
if (is_numeric($maxemailperdaypaid) && $maxemailperdaypaid > 0) {
	$MAXPERDAYPAID = (int) $maxemailperdaypaid;
}
if (empty($MAXPERDAY)) {
	$MAXPERDAY=1000;
}
if (empty($MAXPERDAYPAID)) {
	$MAXPERDAYPAID=1000;
}



/*
 * Main
 */

$dbmaster=getDoliDBInstance('mysqli', $databasehost, $databaseuser, $databasepass, $database, $databaseport);
if ($dbmaster->error) {
	dol_print_error($dbmaster, "host=".$databasehost.", port=".$databaseport.", user=".$databaseuser.", databasename=".$database.", ".$dbmaster->error);
	exit(-1);
}
if ($dbmaster) {
	$conf->setValues($dbmaster);
}
if (empty($db)) {
	$db=$dbmaster;
}

//$langs->setDefaultLang('en_US'); 	// To change default language of $langs
$langs->load("main");				// To load language file for default language
@set_time_limit(0);					// No timeout for this script

// Load user and its permissions
//$result=$user->fetch('','admin');	// Load user for login 'admin'. Comment line to run as anonymous user.
//if (! $result > 0) { dol_print_error('',$user->error); exit(-1); }
//$user->getrights();


print "***** ".$script_file." (".$version.") - ".dol_print_date(dol_now('gmt'), "%Y%m%d-%H%M%S", 'gmt')." *****\n";

if (0 != posix_getuid()) {
	echo "Script must be ran with root.\n";
	exit(-1);
}

if (! isset($argv[1])) {	// Check parameters
	print "Script to detect evils instances by scanning inside its data for blacklist content.\n";
	print "Usage on deployment servers: ".$script_file." (test|testemail|remove) [instancefilter]\n";
	print "\n";
	print "Options are:\n";
	print "- test          Do a test scan\n";
	print "- testemail     Do a test scan and send email\n";
	print "- remove        not yet available\n";
	exit(-1);
}
print '--- Start script with mode '.$argv[1]."\n";
//print 'Argument 1='.$argv[1]."\n";
//print 'Argument 2='.$argv[2]."\n";

$now = dol_now();

$action=$argv[1];
$nbofok=0;
// Nb of deployed instances
$nbofinstancedeployed=0;
// Nb of paying instance
$nboferrors=0;
$instances=array();
$instancespaidsuspended=array();
$instancespaidsuspendedandpaymenterror=array();
$instancespaidnotsuspended=array();
$instancespaidnotsuspendedpaymenterror=array();
$instancesbackuperror=array();
$instancesupdateerror=array();
$instancesbackupsuccess=array();

if (! in_array($action, array('test', 'testemail', 'remove'))) {
	echo "Bad value for 1st parameter (must be test|testemail|remove)\n";
	exit(-1);
}

$instancefilter = '';
$datefilter = 0;
if (isset($argv[2])) {
	if (is_numeric($argv[2])) {
		$datefilter = $argv[2];
	} else {
		$instancefilter = $argv[2];
	}
}
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

$return_var = 0;


// Preparation

print "Go into dir /home/jail/home\n";
chdir('/home/jail/home/');

dol_mkdir($pathtospamdir);


print "----- Init - Generate file blacklistip\n";

$tmpblacklistip = new Blacklistip($db);
$tmparrayblacklistip = $tmpblacklistip->fetchAll('', '', 1000, 0, array('status'=>1));
if (is_numeric($tmparrayblacklistip) && $tmparrayblacklistip < 0) {
	echo "Erreur: failed to get blacklistip elements.\n";
}

if (!empty($tmparrayblacklistip)) {
	//home/admin/wwwroot/dolibarr_documents/sellyoursaas_local/spam

	// Generate the file balcklistip
	$filetobuild = $pathtospamdir.'/blacklistip';
	$fh = fopen($filetobuild, "w");
	foreach ($tmparrayblacklistip as $val) {
		$buffer = trim($val->content);
		fwrite($fh, $buffer."\n");
	}
	fclose($fh);
	$newmask = '0666';
	@chmod($filetobuild, octdec($newmask));
	print 'File '.$filetobuild.' has been updated'."\n";
}


print "----- Init - Generate file blacklistfrom\n";

$tmpblacklistfrom = new Blacklistfrom($db);
$tmparrayblacklistfrom = $tmpblacklistfrom->fetchAll('', '', 1000, 0, array('status'=>1));
if (is_numeric($tmparrayblacklistfrom) && $tmparrayblacklistfrom < 0) {
	echo "Erreur: failed to get blacklistfrom elements.\n";
}

if (!empty($tmparrayblacklistfrom)) {
	// Generate the file balcklistfrom
	$filetobuild = $pathtospamdir.'/blacklistfrom';
	$fh = fopen($filetobuild, "w");
	foreach ($tmparrayblacklistfrom as $val) {
		$buffer = trim($val->content);
		fwrite($fh, $buffer."\n");
	}
	fclose($fh);
	$newmask = '0664';
	@chmod($filetobuild, octdec($newmask));

	print 'File '.$filetobuild.' has been updated'."\n";
}


print "----- Init - Generate file blacklistto\n";

$tmpblacklistto = new Blacklistto($db);
$tmparrayblacklistto = $tmpblacklistto->fetchAll('', '', 1000, 0, array('status'=>1));
if (is_numeric($tmparrayblacklistto) && $tmparrayblacklistto < 0) {
	echo "Erreur: failed to get blacklistto elements.\n";
}

if (!empty($tmparrayblacklistto)) {
	// Generate the file balcklistip
	$filetobuild = $pathtospamdir.'/blacklistto';
	$fh = fopen($filetobuild, "w");
	foreach ($tmparrayblacklistto as $val) {
		$buffer = trim($val->content);
		fwrite($fh, $buffer."\n");
	}
	fclose($fh);
	$newmask = '0664';
	@chmod($filetobuild, octdec($newmask));
	print 'File '.$filetobuild.' has been updated'."\n";
}


print "----- Init - Generate file blacklistcontent\n";

$tmpblacklistcontent = new Blacklistcontent($db);
$tmparrayblacklistcontent = $tmpblacklistcontent->fetchAll('', '', 1000, 0, array('status'=>1));
if (is_numeric($tmparrayblacklistcontent) && $tmparrayblacklistcontent < 0) {
	echo "Erreur: failed to get blacklistcontent elements.\n";
}

if (!empty($tmparrayblacklistcontent)) {
	// Generate the file balcklistcontent
	$filetobuild = $pathtospamdir.'/blacklistcontent';
	$fh = fopen($filetobuild, "w");
	foreach ($tmparrayblacklistcontent as $val) {
		$buffer = trim($val->content);
		fwrite($fh, $buffer."\n");
	}
	fclose($fh);
	$newmask = '0664';
	@chmod($filetobuild, octdec($newmask));
	print 'File '.$filetobuild.' has been updated'."\n";
}


$tmpblacklistdir = new Blacklistdir($db);
$tmparrayblacklistdir = $tmpblacklistdir->fetchAll('', '', 1000, 0, array('status'=>1));
if (is_numeric($tmparrayblacklistdir) && $tmparrayblacklistdir < 0) {
	echo "Erreur: failed to get blacklistdir elements.\n";
}


print "----- Init - Generate file blacklistdir\n";

if (!empty($tmparrayblacklistdir)) {
	// Generate the file balcklistdir
	$filetobuild = $pathtospamdir.'/blacklistdir';
	$fh = fopen($filetobuild, "w");
	foreach ($tmparrayblacklistdir as $val) {
		$buffer = trim($val->content);
		fwrite($fh, $buffer."\n");
	}
	fclose($fh);
	$newmask = '0664';
	@chmod($filetobuild, octdec($newmask));
	print 'File '.$filetobuild.' has been updated'."\n";
}



// Initialize the array $instances*
print "----- Initialize the arrayinstances*\n";


// Nb of deployed instances
$nbofinstancedeployed=0;
// Nb of errors
$nboferrors=0;
// List of instances
$instances=array();
$instancestrial=array();
$instancespaidsuspended=array();
$instancespaidsuspendedandpaymenterror=array();
$instancespaidnotsuspended=array();
$instancespaidnotsuspendedpaymenterror=array();
$instancesbackuperror=array();
$instancesupdateerror=array();
$instancesbackupsuccess=array();

$instancefiltercomplete=$instancefilter;

// Forge complete name of instance
if (! empty($instancefiltercomplete) && ! preg_match('/\./', $instancefiltercomplete) && ! preg_match('/\.home\.lan$/', $instancefiltercomplete)) {
	$tmparray = explode(',', $conf->global->SELLYOURSAAS_SUB_DOMAIN_NAMES);
	$tmpstring = preg_replace('/:.*$/', '', $tmparray[0]);
	$instancefiltercomplete = $instancefiltercomplete.".".$tmpstring;   // Automatically concat first domain name
}

include_once DOL_DOCUMENT_ROOT.'/contrat/class/contrat.class.php';
$object=new Contrat($dbmaster);

// Get list of instance (not already flagged as spammer of flagged as clean)
$sql = "SELECT c.rowid as id, c.ref, c.ref_customer as instance,";
$sql.= " ce.deployment_date_start, ce.deployment_status as instance_status, ce.latestbackup_date_ok, ce.username_os as osu, ce.database_db as dbn,";
$sql.= " ce.maxperday";
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
$sql.=" AND ce.deployment_host = '".$dbmaster->escape($ipserverdeployment)."'";
if (empty($instancefiltercomplete)) {
	$sql.=" AND (ce.spammer IS NULL or ce.spammer = '')";
}
print $sql;
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

					$instances[$obj->id] = array('id'=>$obj->id, 'ref'=>$obj->ref, 'instance'=>$instance, 'osu'=>$obj->osu, 'maxperday'=>$obj->maxperday, 'dbn'=>$obj->dbn, 'deployment_date_start'=>$dbtousetosearch->jdate($obj->deployment_date_start), 'latestbackup_date_ok'=>$dbtousetosearch->jdate($obj->latestbackup_date_ok));
					print "Qualify instance ".$instance." with instance_status=".$instance_status." payment_status=".$payment_status."\n";
				} elseif ($instancefiltercomplete) {
					//$instances[$obj->id] = array('id'=>$obj->id, 'ref'=>$obj->ref, 'instance'=>$instance, 'osu'=>$obj->osu, 'dbn'=>$obj->dbn, 'latestbackup_date_ok'=>$dbtousetosearch->jdate($obj->latestbackup_date_ok));
					$instancestrial[$obj->id] = array('id'=>$obj->id, 'ref'=>$obj->ref, 'instance'=>$instance, 'osu'=>$obj->osu, 'maxperday'=>$obj->maxperday, 'dbn'=>$obj->dbn, 'deployment_date_start'=>$dbtousetosearch->jdate($obj->deployment_date_start), 'latestbackup_date_ok'=>$dbtousetosearch->jdate($obj->latestbackup_date_ok));
					print "Qualify instance ".$instance." with instance_status=".$instance_status." payment_status=".$payment_status."\n";
				} else {
					$instancestrial[$obj->id] = array('id'=>$obj->id, 'ref'=>$obj->ref, 'instance'=>$instance, 'osu'=>$obj->osu, 'maxperday'=>$obj->maxperday, 'dbn'=>$obj->dbn, 'deployment_date_start'=>$dbtousetosearch->jdate($obj->deployment_date_start), 'latestbackup_date_ok'=>$dbtousetosearch->jdate($obj->latestbackup_date_ok));
					//print "Found instance ".$instance." with instance_status=".$instance_status." instance_status_bis=".$instance_status_bis." payment_status=".$payment_status."\n";
					print "Qualify instance ".$instance." with instance_status=".$instance_status." payment_status=".$payment_status."\n";
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



print "----- Generate file mailquota for paid instances\n";

file_put_contents($pathtospamdir.'/mailquota', "# File of paid instance with their quota\n");

$i = 0;
foreach ($instances as $instanceid => $instancearray) {
	$i++;
	// We complete the file $filetobuild = $pathtospamdir.'/mailquota';
	echo 'Process paid instance id='.$instancearray['id'].' ref='.$instancearray['ref'].' osu='.$instancearray['osu']." mailquota=".($instancearray['maxperday'] ? $instancearray['maxperday'] : $MAXPERDAYPAID)."\n";
	file_put_contents($pathtospamdir.'/mailquota', 'Paid instance '.$i.' id='.$instancearray['id'].' ref='.$instancearray['ref'].' osu='.$instancearray['osu']." mailquota=".($instancearray['maxperday'] ? $instancearray['maxperday'] : $MAXPERDAYPAID)."\n", FILE_APPEND);
}


$searchkeysintoindex = 0;

if ($searchkeysintoindex) {
	print "----- Loop for spam keys into index.php using blacklistcontent - in trial instances (for spam and phishing detection)\n";

	if (!empty($tmparrayblacklistcontent)) {
		foreach ($tmparrayblacklistcontent as $val) {
			$buffer = dol_sanitizePathName(trim($val->content));
			if ($buffer) {
				$ok=1;
				$commandexample = "grep -l '".escapeshellcmd(str_replace("'", ".", $buffer))."' osu.../dbn*/htdocs/index.php";
				echo 'Scan if we found the string '.$buffer.' with '.$commandexample;
				foreach ($instancestrial as $instanceid => $instancearray) {
					$command = "grep -l '".escapeshellcmd(str_replace("'", ".", $buffer))."' ".$instancearray['osu']."/dbn*/htdocs/index.php";
					$fullcommand=$command;
					$output=array();
					//echo $command."\n";

					exec($fullcommand, $output, $return_var);
					if ($return_var == 0) {		// grep -l returns 0 if something was found
						// We found an evil string
						print "\nALERT: Evil string '".$buffer."' was found into instance id=".$instanceid." in content of index.php with command ".$command;
						$nboferrors = 1;
						$ok = 0;
					}
				}
				if ($ok) {
					print " - OK\n";
				} else {
					print "\n";
				}
			}
		}
	}
}


print "----- Loop for spam dir or files using blacklistdir - in trial instances\n";

if (!empty($tmparrayblacklistdir)) {
	foreach ($tmparrayblacklistdir as $val) {
		$buffer = dol_sanitizePathName(trim($val->content));
		if ($buffer) {
			// Define the string to exclude some patterns
			$exclude = "";
			if (!empty($val->noblacklistif)) {
				$tmpdirarray = explode('|', $val->noblacklistif);
				foreach ($tmpdirarray as $tmpdir) {
					if ($tmpdir) {
						$exclude .= " ! -path '*".escapeshellcmd(preg_replace('/[^a-z0-9\.\-]/', '', $tmpdir))."*'";
					}
				}
			}

			$ok=1;
			$commandexample = "find osu.../dbn*/htdocs/ -maxdepth 2".$exclude;
			echo 'Scan if we found the blacklist dir '.$buffer.' with '.$commandexample;
			foreach ($instancestrial as $instanceid => $instancearray) {
				$command = "find ".$instancearray['osu']."/dbn*/htdocs/ -maxdepth 2";
				$command .= $exclude;
				$command .= " | grep '".escapeshellcmd($buffer)."'";
				$fullcommand=$command;
				$output=array();
				//echo $command."\n";

				exec($fullcommand, $output, $return_var);
				if ($return_var == 0) {		// command returns 0 if something was found
					// We found an evil string
					print "\nALERT: the evil dir/file '".$buffer."' was found into instance id=".$instanceid." with command ".$command;
					$nboferrors = 2;
					$ok = 0;
				}
			}
			if ($ok) {
				print " - OK\n";
			} else {
				print "\n";
			}
		}
	}
}

/*
print "----- Loop for test instance not matching file permissions - in trial instances\n";
print 'TODO...';
foreach ($instancestrial as $instanceid => $instancearray) {
	$command = "...";
	$fullcommand=$command;
	$output=array();

	$return_var = 0;
	//exec($fullcommand, $output, $return_var);
	if ($return_var == 0) {		// command returns 0 if something was found
		// We found an evil string
		print "\nALERT: the test instance id=".$instanceid." does not match the signature, found with command ".$command;
		$nboferrors = 2;
		$ok = 0;
	}
}
if ($ok) {
	print " - OK\n";
} else {
	print "\n";
}
*/

dol_delete_file('/tmp/batch_detect_evil_instance.tmp');

print "----- Loop for test instance not matching the file signature - in trial instances (".count($instancestrial)." instances)\n";
foreach ($instancestrial as $instanceid => $instancearray) {
	$error = 0;		// error for this instance

	if ($datefilter && $instancearray['deployment_date_start'] < (dol_now() - $datefilter)) {
		print 'Discard '.$instancearray['instance']." - too old (< now - ".$datefilter.")\n";
		continue;
	} else {
		print 'Process '.$instancearray['instance']."\n";
		file_put_contents('/tmp/batch_detect_evil_instance.tmp', "--- Process instance ".$instancearray['instance']." - Deployed on ".dol_print_date($instancearray['deployment_date_start'], 'dayhourrfc', 'gmt')."\n", FILE_APPEND);
	}

	$dirtocheck = "/home/jail/home/".$instancearray['osu']."/".$instancearray['dbn'].'/htdocs';
	$dirforxml = "/home/jail/home/".$instancearray['osu']."/".$instancearray['dbn'].'/htdocs/install';
	$tmparray = dol_dir_list($dirforxml, 'files', 0, 'filelist.*\.xml.*', null, 'name', SORT_DESC, 0, 1);

	if (!empty($tmparray)) {
		$xmlfileorig = $tmparray[0]['fullname'];
		$xmlfile = $tmparray[0]['fullname'];

		if (dol_is_file($xmlfile)) {
			// If file is a zip file (.../filelist-x.y.z.xml.zip), we uncompress it before
			if (preg_match('/\.zip$/i', $xmlfile)) {
				dol_mkdir($conf->admin->dir_temp);
				$xmlfilenew = preg_replace('/\.zip$/i', '', $xmlfile);
				$result = dol_uncompress($xmlfile, $conf->admin->dir_temp);
				if (empty($result['error'])) {
					$xmlfile = $conf->admin->dir_temp.'/'.basename($xmlfilenew);
				} else {
					print $langs->trans('FailedToUncompressFile').': '.$xmlfile;
					$nboferrors++;
					$error++;
				}
			}
			$xml = simplexml_load_file($xmlfile);
			if ($xml === false) {
				print $langs->trans('XmlCorrupted').': '.$xmlfile."\n";
				$nboferrors++;
				$error++;
			}
		} else {
			print $langs->trans('XmlNotFound').': '.$xmlfileorig."\n";
			$nboferrors++;
			$error++;
		}
	} else {
		print $langs->trans('XmlNotFound').' searching into dir '.$dirtocheck."\n";
		$nboferrors++;
		$error++;
	}

	$return_var = 0;

	if (empty($error) && !empty($xml)) {
		$checksumconcat = array();
		$file_list = array();
		$out = '';

		// Scan htdocs
		if (is_object($xml->dolibarr_htdocs_dir[0])) {
			//var_dump($xml->dolibarr_htdocs_dir[0]['includecustom']);exit;
			//$includecustom = (empty($xml->dolibarr_htdocs_dir[0]['includecustom']) ? 0 : $xml->dolibarr_htdocs_dir[0]['includecustom']);

			// Define qualified files (must be same than into generate_filelist_xml.php and in api_setup.class.php)
			$regextoinclude = '\.(php|php3|php4|php5|phtml|phps|phar|inc|css|scss|html|xml|js|json|tpl|jpg|jpeg|png|gif|ico|sql|lang|txt|yml|bak|md|mp3|mp4|wav|mkv|z|gz|zip|rar|tar|less|svg|eot|woff|woff2|ttf|manifest)$';
			//$regextoexclude = '('.($includecustom ? '' : 'custom|').'documents|conf|install|dejavu-fonts-ttf-.*|public\/test|sabre\/sabre\/.*\/tests|Shared\/PCLZip|nusoap\/lib\/Mail|php\/example|php\/test|geoip\/sample.*\.php|ckeditor\/samples|ckeditor\/adapters)$'; // Exclude dirs
			//$regextoexclude = 'conf.php|custom\/README.md';
			$regextoexclude = 'conf.php|custom\/README.md|install|public|includes';
			$scanfiles = dol_dir_list($dirtocheck, 'files', 1, $regextoinclude, $regextoexclude);

			// Fill file_list with files in signature, new files, modified files
			$ret = getFilesUpdated($file_list, $xml->dolibarr_htdocs_dir[0], '', $dirtocheck, $checksumconcat); // Fill array $file_list
			// Complete with list of new files
			foreach ($scanfiles as $keyfile => $valfile) {
				$tmprelativefilename = preg_replace('/^'.preg_quote($dirtocheck, '/').'/', '', $valfile['fullname']);
				if (!in_array($tmprelativefilename, $file_list['insignature'])) {
					$md5newfile = @md5_file($valfile['fullname']); // Can fails if we don't have permission to open/read file
					$file_list['added'][] = array('filename'=>$tmprelativefilename, 'md5'=>$md5newfile);
				}
			}

			$nbmissing = (is_array($file_list['missing']) ? count($file_list['missing']) : 0);
			$nbupdated = (is_array($file_list['updated']) ? count($file_list['updated']) : 0);
			$nbadded = (is_array($file_list['added']) ? count($file_list['added']) : 0);

			$s = 'Missing: '.$nbmissing;
			$s .= ' - Updated: '.$nbupdated;
			$s .= ' - Added: '.$nbadded;

			print $s;
			file_put_contents('/tmp/batch_detect_evil_instance.tmp', $s."\n", FILE_APPEND);

			if ($nbupdated + $nbadded) {
				print "\norig signature file = ".$xmlfileorig;
				print "\nused signature file = ".$xmlfile."\n";
				print 'Warning: Some files on instance id='.$instanceid.' - '.$instancearray['instance'].' have been modified or added.';
				if ($nbupdated) {
					$s = '';
					foreach ($file_list['updated'] as $tmp) {
						$s .= $tmp['filename']."\n";
					}
					file_put_contents('/tmp/batch_detect_evil_instance.tmp', "-- Files updated:\n".$s."\n", FILE_APPEND);
				}
				if ($nbadded) {
					$s = '';
					foreach ($file_list['added'] as $tmp) {
						$s .= $tmp['filename']."\n";
					}
					file_put_contents('/tmp/batch_detect_evil_instance.tmp', "-- Files added:\n".$s."\n", FILE_APPEND);
				}
				print ' A summary is available into /tmp/batch_detect_evil_instance.tmp'."\n";

				$nboferrors++;
			} else {
				print " - OK\n";
			}
		} else {
			print 'Error: Failed to found <b>dolibarr_htdocs_dir</b> into content of XML file:<br>'.dol_escape_htmltag(dol_trunc($xmlfile, 500))."\n";
			file_put_contents('/tmp/batch_detect_evil_instance.tmp', 'Error: Failed to found <b>dolibarr_htdocs_dir</b> into content of XML file:<br>'.dol_escape_htmltag(dol_trunc($xmlfile, 500)), FILE_APPEND);
			$nboferrors++;
			$error++;
		}
	}
}


// Update data of scan
// if (dol_is_file('/tmp/batch_detect_evil_instance.tmp')) {
// $xxx = new Server($dbmaster);
// $xxx->detect_evil_instance_nberrors = $nboferrors;
// $xxx->detect_evil_instance_string = file_get_contents('/tmp/batch_detect_evil_instance.tmp');
// $xxx->update($user);
// }


$dbmaster->close();	// Close database opened handler

print "\n";
if ($nboferrors) {
	print '***** end ERROR nb='.$nboferrors.' - '.dol_print_date(dol_now('gmt'), "%Y%m%d-%H%M%S", 'gmt')."\n";
} else {
	print '***** end OK with no error - '.dol_print_date(dol_now('gmt'), "%Y%m%d-%H%M%S", 'gmt')."\n";
}

$sendcontext = 'emailing';
//$sendcontext = 'standard';

if ($nboferrors) {
	if ($action == 'testemail') {
		$from = $emailfrom;
		$to = $emailsupervision;
		// Force to use local sending (MAIN_MAIL_SENDMODE is the one of the master server. It may be to an external SMTP server not allowed to the deployment server)
		if (empty($usemastermailserver)) {
			$conf->global->MAIN_MAIL_SENDMODE = 'mail';
			$conf->global->MAIN_MAIL_SMTP_SERVER = 'localhost';
		}

		// Supervision tools are generic for all domain. No way to target a specific supervision email.

		$msg = 'Error in '.$script_file." ".$argv[1]." ".$argv[2]." (finished at ".dol_print_date(dol_now('gmt'), "%Y%m%d-%H%M%S", 'gmt').")\n\n".$out;

		include_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
		print 'Send email MAIN_MAIL_SENDMODE='.getDolGlobalString('MAIN_MAIL_SENDMODE').' MAIN_MAIL_SMTP_SERVER='.getDolGlobalString('MAIN_MAIL_SMTP_SERVER').' from='.$from.' to='.$to.' title=[Warning] Alert(s) in batch_detect_evil_instances - '.gethostname().' - '.dol_print_date(dol_now(), 'dayrfc')."\n";
		$cmail = new CMailFile('[Alert] Alert(s) in batch_detect_evil_instances - '.gethostname().' - '.dol_print_date(dol_now(), 'dayrfc'), $to, $from, $msg, array(), array(), array(), '', '', 0, 0, '', '', '', '', $sendcontext);
		$result = $cmail->sendfile();
	}
}

exit($nboferrors);
