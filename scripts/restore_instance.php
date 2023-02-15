#!/usr/bin/php
<?php
/* Copyright (C) 2012-2023 Laurent Destailleur	<eldy@users.sourceforge.net>
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
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 * or see http://www.gnu.org/
 *
 * FEATURE
 *
 * Restore a backup of files (rsync) or database (mysqdump) of a deployed instance.
 * There is no report/tracking done into any database. This must be done by a parent script.
 * This script is run from the source or the target deployment servers.
 *
 * Note:
 * ssh public key of admin must be authorized in the .ssh/authorized_keys_support of targeted user to have testrsync and confirmrsync working.
 * remote access to database must be granted for option 'testdatabase' or 'confirmdatabase'.
 */

if (!defined('NOREQUIREDB')) define('NOREQUIREDB', '1');					// Do not create database handler $db
if (!defined('NOSESSION')) define('NOSESSION', '1');
if (!defined('NOREQUIREVIRTUALURL')) define('NOREQUIREVIRTUALURL', '1');

$sapi_type = php_sapi_name();
$script_file = basename(__FILE__);
$path=dirname(__FILE__).'/';

// Test if batch mode
if (substr($sapi_type, 0, 3) == 'cgi') {
	echo "Error: You are using PHP for CGI. To execute ".$script_file." from command line, you must use PHP for CLI mode.\n";
	exit;
}

// Global variables
$version='1.0';
$error=0;
$RSYNCDELETE=0;

$dirroot=isset($argv[1])?$argv[1]:'';
$dayofmysqldump=isset($argv[2])?$argv[2]:'';
$instance=isset($argv[3])?$argv[3]:'';
$mode=isset($argv[4])?$argv[4]:'';

@set_time_limit(0);							// No timeout for this script
define('EVEN_IF_ONLY_LOGIN_ALLOWED', 1);		// Set this define to 0 if you want to lock your script when dolibarr setup is "locked to admin user only".

// Read /etc/sellyoursaas.conf file
$domain='';
$databasehost='localhost';
$databaseport='3306';
$database='';
$databaseuser='sellyoursaas';
$databasepass='';
$dolibarrdir='';
$usecompressformatforarchive='gzip';
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
		if ($tmpline[0] == 'ipserverdeployment') {
			$ipserverdeployment = dol_string_nospecial($tmpline[1]);
		}
		if ($tmpline[0] == 'instanceserver') {
			$instanceserver = (int) $tmpline[1];
		}
		if ($tmpline[0] == 'databasehost') {
			$databasehost = dol_string_nospecial($tmpline[1]);
		}
		if ($tmpline[0] == 'databaseport') {
			$databaseport = (int) $tmpline[1];
		}
		if ($tmpline[0] == 'database') {
			$database = dol_string_nospecial($tmpline[1]);
		}
		if ($tmpline[0] == 'databaseuser') {
			$databaseuser = dol_string_nospecial($tmpline[1]);
		}
		if ($tmpline[0] == 'databasepass') {
			$databasepass = $tmpline[1];
		}
		if ($tmpline[0] == 'dolibarrdir') {
			$dolibarrdir = dol_sanitizePathName($tmpline[1]);
		}
		if ($tmpline[0] == 'usecompressformatforarchive') {
			$usecompressformatforarchive = dol_string_nospecial($tmpline[1]);
		}
		if ($tmpline[0] == 'emailfrom') {
			$emailfrom = dol_sanitizeEmail($tmpline[1]);
		}
		if ($tmpline[0] == 'emailsupervision') {
			$emailsupervision = dol_sanitizeEmail($tmpline[1]);
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


if (empty($dolibarrdir)) {
	print "Failed to find 'dolibarrdir' entry into /etc/sellyoursaas.conf file\n";
	exit(-1);
}

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
if (! $res && file_exists(__DIR__."/../../master.inc.php")) $res=@include __DIR__."/../../../master.inc.php";
if (! $res && file_exists(__DIR__."/../../../master.inc.php")) $res=@include __DIR__."/../../../master.inc.php";
if (! $res && file_exists($dolibarrdir."/htdocs/master.inc.php")) $res=@include $dolibarrdir."/htdocs/master.inc.php";
if (! $res) {
	print ("Include of master fails");
	exit(-1);
}
include_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
dol_include_once("/sellyoursaas/core/lib/dolicloud.lib.php");
dol_include_once("/sellyoursaas/lib/sellyoursaas.lib.php");

// Read /etc/sellyoursaas.conf file
$databasehost='localhost';
$databaseport='3306';
$database='';
$databaseuser='sellyoursaas';
$databasepass='';
$fp = @fopen('/etc/sellyoursaas.conf', 'r');
// Add each line to an array
if ($fp) {
	$array = explode("\n", fread($fp, filesize('/etc/sellyoursaas.conf')));
	foreach ($array as $val) {
		$tmpline=explode("=", $val);
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
	}
} else {
	print "Failed to open /etc/sellyoursaas.conf file\n";
	exit;
}


/*
 *	Main
 */

print "***** ".$script_file." (".$version.") - mode = ".$mode." - ".dol_print_date(dol_now('gmt'), "%Y%m%d-%H%M%S", 'gmt')." *****\n";

if (0 == posix_getuid()) {
	echo "Script must not be ran with root (but with the 'admin' sellyoursaas account).\n";
	exit(-1);
}

$dbmaster=getDoliDBInstance('mysqli', $databasehost, $databaseuser, $databasepass, $database, $databaseport);
if ($dbmaster->error) {
	dol_print_error($dbmaster, "host=".$databasehost.", port=".$databaseport.", user=".$databaseuser.", databasename=".$database.", ".$dbmaster->error);
	exit;
}
if ($dbmaster) {
	$conf->setValues($dbmaster);
}
if (empty($db)) $db=$dbmaster;

if (empty($dirroot) || empty($instance) || empty($mode)) {
	print "This script must be ran as 'admin' user.\n";
	print "Usage:   $script_file backup_dir  autoscan|mysqldump_dbn...sql.zst|dayofmysqldump instance [testrsync|testdatabase|test|confirmrsync|confirmdatabase|confirm]\n";
	print "Example: $script_file ".$conf->global->DOLICLOUD_BACKUP_PATH."/osu123456/dbn789012  myinstance  31  testrsync\n";
	print "Note:    ssh public key of admin must be authorized in the .ssh/authorized_keys_support of targeted user to have testrsync and confirmrsync working.\n";
	print "Return code: 0 if success, <>0 if error\n";
	exit(-1);
}


// Forge complete name of instance
if (! empty($instance) && ! preg_match('/\./', $instance) && ! preg_match('/\.home\.lan$/', $instance)) {
	if (empty(getDolGlobalString('SELLYOURSAAS_OBJECT_DEPLOYMENT_SERVER_MIGRATION'))) {
		$tmparray = explode(',', getDolGlobalString('SELLYOURSAAS_SUB_DOMAIN_NAMES'));
	} else {
		dol_include_once('sellyoursaas/class/deploymentserver.class.php');
		$staticdeploymentserver = new Deploymentserver($db);
		$tmparray = $staticdeploymentserver->fetchAllDomains();
	}
	$tmpstring = preg_replace('/:.*$/', '', $tmparray[0]);
	$instance = $instance.".".$tmpstring;   // Automatically concat first domain name
}


$idofinstancefound = 0;

$sql = "SELECT c.rowid, c.statut";
$sql.= " FROM ".MAIN_DB_PREFIX."contrat as c LEFT JOIN ".MAIN_DB_PREFIX."contrat_extrafields as ce ON c.rowid = ce.fk_object";
$sql.= "  WHERE c.entity IN (".getEntity('contract').")";
//$sql.= " AND c.statut > 0";
$sql.= " AND c.ref_customer = '".$dbmaster->escape($instance)."'";
$sql.= " AND ce.deployment_status = 'done'";

$resql = $dbmaster->query($sql);
if (! $resql) {
	dol_print_error($resql);
	exit(-2);
}
$num_rows = $dbmaster->num_rows($resql);
if ($num_rows > 1) {
	print 'Error: several instance with name '.$instance.' were found. We stop here.'."\n";
	exit(-2);
} else {
	$obj = $dbmaster->fetch_object($resql);
	if ($obj) $idofinstancefound = $obj->rowid;
}

dol_include_once("/sellyoursaas/class/sellyoursaascontract.class.php");

$object = new SellYourSaasContract($dbmaster);
$result=0;
if ($idofinstancefound) {
	$result=$object->fetch($idofinstancefound);
	if ($result > 0) {
		$result = $object->fetch_thirdparty();
	}
}

if ($result <= 0) {
	print "Error: Instance named '".$instance."' with status 'done' could not be found or loaded.\n";
	exit(-2);
}

$object->instance = $object->ref_customer;
$object->username_os = $object->array_options['options_username_os'];
$object->password_os = $object->array_options['options_password_os'];
$object->username_db = $object->array_options['options_username_db'];
$object->password_db = $object->array_options['options_password_db'];
$object->database_db = $object->array_options['options_database_db'];
$object->hostname_db = $object->array_options['options_hostname_db'];
$object->deployment_host = $object->array_options['options_deployment_host'];
$object->username_web = $object->thirdparty->email;
$object->password_web = $object->thirdparty->array_options['options_password'];

if (empty($object->instance) && empty($object->username_os) && empty($object->password_os) && empty($object->database_db)) {
	print "Error: properties for instance ".$instance." was not registered into database.\n";
	exit(-3);
}
if (! is_dir($dirroot)) {
	print "Error: Source directory ".$dirroot." where backup is stored does not exist.\n";
	exit(-4);
}

$dirdb = preg_replace('/_([a-zA-Z0-9]+)/', '', $object->database_db);
$login = $object->username_os;
$password = $object->password_os;

$targetdir=$conf->global->DOLICLOUD_INSTANCES_PATH.'/'.$login.'/'.$dirdb;
$server=($object->deployment_host ? $object->deployment_host : $object->array_options['options_hostname_os']);

if (empty($login) || empty($dirdb)) {
	print "Error: properties for instance ".$instance." are not registered completely (missing at least login or database name).\n";
	exit(-5);
}

print 'Restore from '.$dirroot." to ".$targetdir.' into instance '.$instance."\n";
print 'Target SFTP password '.dol_trunc($object->password_os, 2, 'right', 'UTF-8', 1).preg_replace('/./', '*', dol_substr($object->password_os, 3))."\n";
print 'Target Database password '.dol_trunc($object->password_db, 2, 'right', 'UTF-8', 1).preg_replace('/./', '*', dol_substr($object->password_db, 3))."\n";

if (! in_array($mode, array('testrsync', 'testdatabase', 'test', 'confirmrsync', 'confirmdatabase', 'confirm'))) {
	print "Error: Bad value for last parameter (action must be testrsync|testdatabase|test|confirmrsync|confirmdatabase|confirm).\n";
	exit(-6);
}

if ($dayofmysqldump == 'autoscan') {
	print 'Scan directory '.$dirroot.'/.. for database dumps.'."\n";
	$arrayoffiles = dol_dir_list($dirroot.'/..', 'files', 0, 'sql\.gz|sql\.bz2|sql\.zst', null, 'name', SORT_ASC, 1);
	if (count($arrayoffiles)) {
		$i = 1;
		foreach ($arrayoffiles as $filevar) {
			print $i." - ".$filevar['relativename']." - ".dol_print_date($filevar['date'], 'dayhour')." (".dol_print_size($filevar['size'], 1, 1).")\n";
			$i++;
		}
		do {
			print "Enter choice : ";
			$input = rtrim(fgets(STDIN));
		} while ($input <= 0 || $input > count($arrayoffiles));
		$dayofmysqldump = $arrayoffiles[($input - 1)]['relativename'];
	} else {
		print 'No dump file found into '.$dirroot.'/..'."\n";
		exit(-7);
	}
}

// Restore rsynced files
$return_var=0;
if ($mode == 'testrsync' || $mode == 'test' || $mode == 'confirmrsync' || $mode == 'confirm') {
	if (! is_dir($dirroot)) {
		print "ERROR failed to find source dir ".$dirroot."\n";
		exit(1);
	}

	$command="rsync";
	$param=array();
	if ($mode != 'confirm' && $mode != 'confirmrsync') $param[]="-n";
	//$param[]="-a";
	$param[]="-rltz";
	//$param[]="-vv";
	$param[]="-v";
	$param[]="--exclude 'conf.php'";			// a conf file for dolibarr
	$param[]="--exclude 'config_db.php'";		// a conf file for glpi
	$param[]="--exclude 'downstream.php'";		// a conf file for glpi
	$param[]="--exclude .buildpath";
	$param[]="--exclude .git";
	$param[]="--exclude .gitignore";
	$param[]="--exclude .settings";
	$param[]="--exclude .project";
	//$param[]="--exclude '*last_mysqlrestore_*'";
	//$param[]="--exclude '*last_rsyncrestore_*'";
	$param[]="--exclude '*.com*SSL'";
	$param[]="--exclude '*.log'";
	$param[]="--exclude '*.pdf_preview*.png'";
	$param[]="--exclude '(PROV*)'";
	//$param[]="--exclude '*/build/'";
	//$param[]="--exclude '*/doc/images/'";	// To keep files into htdocs/core/module/xxx/doc/ dir
	//$param[]="--exclude '*/doc/install/'";	// To keep files into htdocs/core/module/xxx/doc/ dir
	//$param[]="--exclude '*/doc/user/'";		// To keep files into htdocs/core/module/xxx/doc/ dir
	//$param[]="--exclude '*/dev/'";
	//$param[]="--exclude '*/test/'";
	$param[]="--exclude '*/thumbs/'";
	$param[]="--exclude '*/temp/'";
	$param[]="--exclude '*/documents/admin/backup/'";
	$param[]="--exclude '*/htdocs/install/filelist-*.xml'";
	$param[]="--exclude '*/htdocs/includes/tecnickcom/tcpdf/font/ae_fonts_*'";
	$param[]="--exclude '*/htdocs/includes/tecnickcom/tcpdf/font/dejavu-fonts-ttf-*'";
	$param[]="--exclude '*/htdocs/includes/tecnickcom/tcpdf/font/freefont-*'";
	// For old versions
	$param[]="--exclude '*/_source/*'";

	if ($RSYNCDELETE) {
		//$param[]="--backup --suffix=.old --delete --delete-excluded";
		$param[]="--delete --delete-excluded";
	} else {
		//$param[]="--backup --suffix=.old";
	}
	$param[]="--stats";
	$param[]="-e 'ssh -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -o PasswordAuthentication=no'";

	//var_dump($param);
	//print "- Backup documents dir ".$dirroot."/".$instance."\n";
	$param[]=$dirroot.'/*';
	$param[]=(in_array($server, array('127.0.0.1','localhost')) ? '' : $login.'@'.$server.":") . $targetdir;
	$fullcommand=$command." ".join(" ", $param);
	$output=array();
	print dol_print_date(dol_now('gmt'), "%Y%m%d-%H%M%S", 'gmt').' '.$fullcommand."\n";
	exec($fullcommand, $output, $return_var);
	if ($return_var > 0) {
		print dol_print_date(dol_now('gmt'), "%Y%m%d-%H%M%S", 'gmt').' rsync failed'."\n";
	} else {
		print dol_print_date(dol_now('gmt'), "%Y%m%d-%H%M%S", 'gmt').' rsync done'."\n";
	}

	// Output result
	foreach ($output as $outputline) {
		print $outputline."\n";
	}

	// Add file tag
	if ($mode == 'confirm' || $mode == 'confirmrsync') {
		$handle=fopen($dirroot.'/../last_rsyncrestore_'.$instance.'.txt', 'w');
		if ($handle) {
			fwrite($handle, 'File created after rsync for restore of '.$instance.". return_var=".$return_var."\n");
			fclose($handle);
		} else {
			print dol_print_date(dol_now('gmt'), "%Y%m%d-%H%M%S", 'gmt').' Warning: Failed to create file last_rsync_'.$instance.'.txt'."\n";
		}
	}
}


// Restore database
$return_varmysql=0;
if ($mode == 'testdatabase' || $mode == 'test' || $mode == 'confirmdatabase' || $mode == 'confirm') {
	$serverdb = $server;
	if (filter_var($object->hostname_db, FILTER_VALIDATE_IP) !== false) {
		print dol_print_date(dol_now('gmt'), "%Y%m%d-%H%M%S", 'gmt').' hostname_db value is an IP, so we use it in priority instead of ip of deployment server'."\n";
		$serverdb = $object->hostname_db;
	}

	$command="mysql";
	$param=array();
	$param[]=$object->database_db;
	$param[]="-h";
	$param[]=$serverdb;
	$param[]="-P";
	$param[]=(! empty($object->port_db) ? $object->port_db : "3306");
	$param[]="-u";
	$param[]=$object->username_db;
	$param[]='-p"'.str_replace(array('"','`'), array('\"','\`'), $object->password_db).'"';

	// Define filename
	if (is_numeric($dayofmysqldump)) {
		$src_database_db = basename($dirroot);
		$dateselected=sprintf("%02s", $dayofmysqldump);
		if (command_exists("zstd") && "x$usecompressformatforarchive" == 'xzstd') {
			$dumpfiletoload='mysqldump_'.$src_database_db.'_'.$dateselected.".sql.zst";
		} else {
			$dumpfiletoload='mysqldump_'.$src_database_db.'_'.$dateselected.".sql.gz";
		}
	} else {
		$dumpfiletoload=$dayofmysqldump;
	}

	// TODO
	// Drop table to avoid error on load due to foreign keys

	// Launch load
	$fullcommand=$command." ".join(" ", $param);
	if (command_exists("zstd") && "x$usecompressformatforarchive" == 'xzstd') {
		if ($mode != 'confirm' && $mode != 'confirmdatabase') $fullcommand="cat '".$dirroot.'/../'.$dumpfiletoload."' | zstd -d -q > /dev/null";
		else $fullcommand="cat '".$dirroot.'/../'.$dumpfiletoload."' | zstd -d -q  | ".$fullcommand;
	} else {
		if ($mode != 'confirm' && $mode != 'confirmdatabase') $fullcommand="cat '".$dirroot.'/../'.$dumpfiletoload."' | gzip -d > /dev/null";
		else $fullcommand="cat '".$dirroot.'/../'.$dumpfiletoload."' | gzip -d | ".$fullcommand;
	}

	$output=array();
	$fullcommandwithoutpass = preg_replace('/\-p"(..).*"$/', '-p\1***hidden***', $fullcommand); // Hide password ecept the 2 first chars
	print dol_print_date(dol_now('gmt'), "%Y%m%d-%H%M%S", 'gmt').' '.$fullcommandwithoutpass."\n";
	if ($mode == 'confirm' || $mode == 'confirmdatabase') {
		exec($fullcommand, $output, $return_varmysql);
	}
	print dol_print_date(dol_now('gmt'), "%Y%m%d-%H%M%S", 'gmt').' mysql load done (return='.$return_varmysql.')'."\n";

	// Output result
	foreach ($output as $outputline) {
		print $outputline."\n";
	}

	// Add file tag
	if ($mode == 'confirm' || $mode == 'confirmdatabase') {
		$handle=fopen($dirroot.'/../last_mysqlrestore_'.$instance.'.txt', 'w');
		if ($handle) {
			fwrite($handle, 'File created after mysql load into '.$instance.". return_varmysql=".$return_varmysql."\n");
			fwrite($handle, 'The dump file restored was: '.$dirroot.'/../'.$dumpfiletoload."\n");
			fclose($handle);
		} else {
			print dol_print_date(dol_now('gmt'), "%Y%m%d-%H%M%S", 'gmt').' Warning: Failed to create file last_mysqlrestore_'.$instance.'.txt'."\n";
		}
	}
}

$now=dol_now();

// Update database
if (!sellyoursaasIsPaidInstance($object)) {
	// TODO Add message to update end date manually for the moment
	if ($object->array_options['options_date_endfreeperiod'] < dol_now()) {
		print "\n";
		print "TRIAL HAS EXPIRED (".dol_print_date($object->array_options['options_date_endfreeperiod'], 'day')."), DON'T FORGET TO UPDATE THE END OF TRIAL TO AVOID TO HAVE INSTANCE DISABLED IN FEW MINUTES\n";
		print "\n";
	} else {
		print "\n";
		print "You may want to increase end of trial date of the target instance (currently ".dol_print_date($object->array_options['options_date_endfreeperiod'], 'day')."). Do it from the backoffice if this is required.\n";
		print "\n";
	}
} else {
	// No message
}

$sendcontext = 'emailing';

// Send result to supervision if enabled
if (empty($return_var) && empty($return_varmysql)) {
	if ($mode == 'confirm') {
		$from = $emailfrom;
		$to = $emailsupervision;
		// Force to use local sending (MAIN_MAIL_SENDMODE is the one of the master server. It may be to an external SMTP server not allowed to the deployment server)
		if (empty($usemastermailserver)) {
			$conf->global->MAIN_MAIL_SENDMODE = 'mail';
			$conf->global->MAIN_MAIL_SMTP_SERVER = 'localhost';
		}

		// Supervision tools are generic for all domain. No way to target a specific supervision email.

		$msg = 'Restore done without errors by '.$script_file." ".(empty($argv[1]) ? '' : $argv[1])." ".(empty($argv[2]) ? '' : $argv[2])." ".(empty($argv[3]) ? '' : $argv[3])." (finished at ".dol_print_date(dol_now('gmt'), "%Y%m%d-%H%M%S", 'gmt').")\n\n";

		include_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
		print 'Send email MAIN_MAIL_SENDMODE='.getDolGlobalString('MAIN_MAIL_SENDMODE').' MAIN_MAIL_SMTP_SERVER='.getDolGlobalString('MAIN_MAIL_SMTP_SERVER').' from='.$from.' to='.$to.' title=[Restore instance - '.gethostname().'] Restore of user instance succeed.'."\n";
		$cmail = new CMailFile('[Restore instance - '.gethostname().'] Restore of user instance succeed - '.dol_print_date(dol_now(), 'dayrfc'), $to, $from, $msg, array(), array(), array(), '', '', 0, 0, '', '', '', '', $sendcontext);
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

				$arraytags=array('result'=>'ok');
				$statsd->increment('sellyoursaas.restore', 1, $arraytags);
			} catch (Exception $e) {
				// No action
			}
		}
	}
} else {
	if (! empty($return_var))      print "ERROR into restore process of rsync: ".$return_var."\n";
	if (! empty($return_varmysql)) print "ERROR into restore process of mysqldump: ".$return_varmysql."\n";

	if ($mode == 'confirm') {
		$from = $emailfrom;
		$to = $emailsupervision;
		// Force to use local sending (MAIN_MAIL_SENDMODE is the one of the master server. It may be to an external SMTP server not allowed to the deployment server)
		if (empty($usemastermailserver)) {
			$conf->global->MAIN_MAIL_SENDMODE = 'mail';
			$conf->global->MAIN_MAIL_SMTP_SERVER = 'localhost';
		}

		// Supervision tools are generic for all domain. No way to target a specific supervision email.

		$msg = 'Error in '.$script_file." ".(empty($argv[1]) ? '' : $argv[1])." ".(empty($argv[2]) ? '' : $argv[2])." ".(empty($argv[3]) ? '' : $argv[3])." (finished at ".dol_print_date(dol_now('gmt'), "%Y%m%d-%H%M%S", 'gmt').")\n\n".$return_var."\n".$return_varmysql;

		include_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
		print 'Send email MAIN_MAIL_SENDMODE='.getDolGlobalString('MAIN_MAIL_SENDMODE').' MAIN_MAIL_SMTP_SERVER='.getDolGlobalString('MAIN_MAIL_SMTP_SERVER').' from='.$from.' to='.$to.' title=[Warning] Error(s) in restoring - '.gethostname().' - '.dol_print_date(dol_now(), 'dayrfc')."\n";
		$cmail = new CMailFile('[Warning] Error(s) in restore process - '.gethostname().' - '.dol_print_date(dol_now(), 'dayrfc'), $to, $from, $msg, array(), array(), array(), '', '', 0, 0, '', '', '', '', $sendcontext);
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

				$arraytags=array('result'=>'ko');
				$statsd->increment('sellyoursaas.restore', 1, $arraytags);
			} catch (Exception $e) {
				// No action
			}
		}
	}

	print "\n";

	exit(1);
}

exit(0);
