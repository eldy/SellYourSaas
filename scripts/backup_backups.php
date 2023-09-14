<?php
/* Copyright (C) 2012-2019 Laurent Destailleur	<eldy@users.sourceforge.net>
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
 * Make a backup of files (rsync) or database (mysqdump) of a deployed instance.
 * There is no report/tracking done into any database. This must be done by a parent script.
 * This script is run from the deployment servers.
 *
 * Note:
 * ssh keys must be authorized to have testrsync and confirmrsync working.
 * remote access to database must be granted for option 'testdatabase' or 'confirmdatabase'.
 */

if (!defined('NOREQUIREDB')) define('NOREQUIREDB', '1');					// Do not create database handler $db
if (!defined('NOSESSION')) define('NOSESSION', '1');
if (!defined('NOREQUIREVIRTUALURL')) define('NOREQUIREVIRTUALURL', '1');

$sapi_type = php_sapi_name();
$script_file = basename(__FILE__);
$path=dirname(__FILE__).'/';
$scriptdir = dirname(realpath($script_file));
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
$RSYNCDELETE=0;
$HISTODIRTEXT="";
$NOTRANS=0;
$QUICK=0;
$NOSTATS=0;
$FORCERSYNC=0;
$FORCEDUMP=0;

$errstring = "";

$testorconfirm=isset($argv[1])?$argv[1]:'';

$keystocheck = array(2, 3, 4, 5);
foreach ($keystocheck as $keytocheck) {
	if (isset($argv[$keytocheck])) {
		if ($argv[$keytocheck] == '--delete') {
			$RSYNCDELETE=1;
		} elseif ($argv[$keytocheck] == '--notransaction') {
			$NOTRANS=1;
		} elseif ($argv[$keytocheck] == '--quick') {
			$QUICK=1;
		} elseif ($argv[$keytocheck] == '--nostats') {
			$NOSTATS=1;
		} elseif ($argv[$keytocheck] == '--forcersync') {
			$FORCERSYNC=1;
		} elseif ($argv[$keytocheck] == '--forcedump') {
			$FORCEDUMP=1;
		} elseif ($argv[$keytocheck] == 'month') {
			$HISTODIRTEXT='month';
		} elseif ($argv[$keytocheck] == 'week') {
            $HISTODIRTEXT='week';
		} elseif ($argv[$keytocheck] == 'none') {
            $HISTODIRTEXT='';
		}
	}
}

@set_time_limit(0);							// No timeout for this script
define('EVEN_IF_ONLY_LOGIN_ALLOWED', 1);

// Read /etc/sellyoursaas.conf file


$databasehost='localhost';
$databaseport='3306';
$database='';
$databaseuser='sellyoursaas';
$databasepass='';
$dolibarrdir='';
$usecompressformatforarchive='gzip';
$backupignoretables='';
$backupcompressionalgorithms='';	// can be '' or 'zstd'
$backuprsyncdayfrequency=1;	// Default value is an rsync every 1 day.
$backupdumpdayfrequency=1;	// Default value is a sql dump every 1 day.

$DOMAIN = '';
$HISTODIR = '';
$homedir = '/mnt/diskhome/home';
$backupdir = 'mnt/diskbackup/backup';
$remotebackupdir = '/mnt/diskbackup';

#Source
$DIRSOURCE1 = "/home/admin";
$DIRSOURCE2 = "";

#Target
$SERVDESTI = '';
$SERVPORTDESTI = '22';
$USER = '';
$DIRDESTI1 = '';
$DIRDESTI2 = '';

$EMAILFROM = '';
$EMAILTO = '';

$DISTRIB_RELEASE = exec('lsb_release -r -s');
$instanceserver = '';

$fp = @fopen('/etc/sellyoursaas.conf', 'r');
// Add each line to an array
if ($fp) {
	$array = explode("\n", fread($fp, filesize('/etc/sellyoursaas.conf')));
	foreach ($array as $val) {
		$tmpline=explode("=", $val);
		if ($tmpline[0] == 'domain') {
			$DOMAIN = $tmpline[1];
		}
		if ($tmpline[0] == 'homedir') {
			$homedir = $tmpline[1];
		}
		if ($tmpline[0] == 'backupdir') {
			$backupdir = $tmpline[1];
		}
		if ($tmpline[0] == 'remotebackupdir') {
			$remotebackupdir = $tmpline[1];
		}
		if ($tmpline[0] == 'remotebackupserver') {
			$SERVDESTI = $tmpline[1];
		}
		if ($tmpline[0] == 'remotebackupserverport') {
			$SERVPORTDESTI = $tmpline[1];
		}
		if ($tmpline[0] == 'remotebackupuser') {
			$USER = $tmpline[1];
		}
		if ($tmpline[0] == 'remotebackupuser') {
			$USER = $tmpline[1];
		}
		if ($tmpline[0] == 'emailfrom') {
			$EMAILFROM = $tmpline[1];
		}
		if ($tmpline[0] == 'emailsupervision') {
			$EMAILTO = $tmpline[1];
		}
		if ($tmpline[0] == 'instanceserver') {
			$instanceserver = $tmpline[1];
		}
		if ($tmpline[0] == 'dolibarrdir') {
			$dolibarrdir = $tmpline[1];
		}
		if ($tmpline[0] == 'backupcompressionalgorithms') {
			$backupcompressionalgorithms = preg_replace('/[^a-z]/', '', $tmpline[1]);
		}
		if ($tmpline[0] == 'backuprsyncdayfrequency') {
			$backuprsyncdayfrequency = $tmpline[1];
		}
		if ($tmpline[0] == 'backupdumpdayfrequency') {
			$backupdumpdayfrequency = $tmpline[1];
		}
	}
} else {
	print "Failed to open /etc/sellyoursaas.conf file\n";
	exit(-1);
}

if (empty($dolibarrdir)) {
	print "Failed to find 'dolibarrdir' entry into /etc/sellyoursaas.conf file\n";
	exit(-1);
}
if (empty($backuprsyncdayfrequency)) {
	print "Bad value for 'backuprsyncdayfrequency'. Must contains the number of days between each rsync.\n";
	exit(-1);
}
if (empty($backupdumpdayfrequency)) {
	print "Bad value for 'backupdumpdayfrequency'. Must contains the number of days between each sql dump.\n";
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
include_once DOL_DOCUMENT_ROOT."/core/class/utils.class.php";
dol_include_once("/sellyoursaas/core/lib/dolicloud.lib.php");


$HISTODIR = dol_print_date(dol_now(), '%d');
if ($HISTODIRTEXT == "week") {
	$HISTODIR = dol_print_date(dol_now(), 'W');
} else if ($HISTODIRTEXT == "none") {
	$HISTODIR = "";
}

$DIRSOURCE2 = $backupdir;

$DIRDESTI1 = $remotebackupdir.'home_'.gethostname();
$DIRDESTI2 = $remotebackupdir.'backup_'.gethostname();

if (empty($EMAILFROM)) {
	$EMAILFROM = 'noreply@'.$DOMAIN;
}
if (empty($EMAILTO)) {
	$EMAILTO = 'supervision@'.$DOMAIN;
}

$OPTIONS = "-4 --prune-empty-dirs --stats -rlt --chmod=u=rwX";
if ($DISTRIB_RELEASE == "20.04" || $DISTRIB_RELEASE == "22.04") {
	$OPTIONS = $OPTIONS;
} else {
	$OPTIONS = $OPTIONS." --noatime";
}

if ($RSYNCDELETE == 1) {
	$OPTIONS = $OPTIONS." --delete --delete-excluded";
}

if (empty($USER)) {
	$USER="admin";
}
$TESTN = "";
if ($testorconfirm != "confirm") {
	$TESTN = "-n";
}

if (empty($SERVDESTI)) {
	print "Can't find name of remote backup server (remotebackupserver=) in /etc/sellyoursaas.conf\n";
	print "Usage: ".$argv[0]." (test|confirm) [osuX]\n";
	exit(-1);
}

if (empty($DOMAIN)) {
	print "Value for domain seems to not be set into /etc/sellyoursaas.conf\n";
	print "Usage: ".$argv[0]." (test|confirm) [osuX]\n";
	exit(-1);
}

/*
 * Main 
 */

if (empty($instanceserver)) {
	print "This server seems to not be a server for deployment of instances (this should be defined in sellyoursaas.conf file).\n";
	print "Press ENTER to continue or CTL+C to cancel...";
	$input = trim(fgets(STDIN));
}

/*$dbmaster=getDoliDBInstance('mysqli', $databasehost, $databaseuser, $databasepass, $database, $databaseport);
if ($dbmaster->error) {
	dol_print_error($dbmaster, "host=".$databasehost.", port=".$databaseport.", user=".$databaseuser.", databasename=".$database.", ".$dbmaster->error);
	exit(-1);
}
if ($dbmaster) {
	$conf->setValues($dbmaster);
}
if (empty($db)) {
	$db = $dbmaster;
}
 
$utils = new Utils($db);*/

$ret1 = array();
$ret2 = array();
$totalinstancessaved=0;
$totalinstancesfailed=0;
// The following line is to have an empty dir to clear the last incremental directories
if (!dol_is_dir($homedir."/emptydir")) {
	mkdir($homedir."/emptydir");
}

$SERVERDESTIARRAY = explode(',', $SERVDESTI);
// Loop on each target server
foreach ($SERVERDESTIARRAY as $servername) {
	$ret1[$servername] = 0;
	$ret2[$servername] = 0;
}
var_dump($SERVERDESTIARRAY);
// Loop on each target server to make backup of SOURCE1
$command = '';
foreach ($SERVERDESTIARRAY as $servername) {
	print dol_print_date(dol_now(), '%Y-%m-%d %H:%M:%S').' Do rsync of '.$DIRSOURCE1.' to remote '.$USER.'@'.$servername.':'.$DIRDESTI1."...\n";
	$RSYNC_RSH = "ssh -p ".$servername;

	if (empty($HISTODIR)) {
		$command = "rsync ".$TESTN." -x --exclude-from=".$scriptdir."/backup_backups.exclude ".$OPTIONS.$DIRSOURCE1."/* ".$USER."@".$servername.":".$DIRDESTI1;
	} else {
		$command = "rsync ".$TESTN." -x --exclude-from=".$scriptdir."/backup_backups.exclude ".$OPTIONS." --backup --backup-dir=".$DIRDESTI1."/backupold_".$HISTODIR." ".$DIRSOURCE1."/* ".$USER."@".$servername.":".$DIRDESTI1;
	}
	print dol_print_date(dol_now(), '%Y-%m-%d %H:%M:%S')." ".$command."\n";
	exec($command, $output, $return_var);
	if ($return_var != 0) {
		$ret1[$servername] = $ret1[$servername] + 1;
		print "ERROR Failed to make rsync for ".$DIRSOURCE1." to ".$servername." ret=".$ret1[$servername]." \n";
    	print "Command was: ".$command."\n";
    	$errstring .="\n".dol_print_date(dol_now(),"%Y-%m-%d %H:%M:%S")." Dir ".$DIRSOURCE1." to ".$servername.". ret=".$ret1[$servername].". Command was: ".$command."\n";
	}
	sleep(2);
}

if (!empty($instanceserver)) {
	print "\n";
	print dol_print_date(dol_now(),"%Y-%m-%d %H:%M:%S")." Do rsync of customer directories ".$DIRSOURCE2."/osu to remote ".$SERVDESTI."...\n";

	$nbdu = 0;
	$alphaarray = array('a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9');

	foreach ($alphaarray as $key => $i) {
		print dol_print_date(dol_now(),"%Y-%m-%d %H:%M:%S")." ----- Process directory $backupdir/osu$i \n";
		$arraydirlist = dol_dir_list($DIRSOURCE2."/osu".$i."*");
		$nbofdir = count($arraydirlist);
		if ($nbofdir > 0) {
			if (isset($argv[3])) {
				if ($argv[3] != "--delete" && $argv[3] == "osu".$i) {
					print "Ignored (param 3 is ".$argv[3].").";
					continue;
				}
			}
		}

		foreach ($SERVERDESTIARRAY as $servername) {
			$RSYNC_RSH = "ssh -p ".$servername;
			if (empty($HISTODIR)) {
				$command = "rsync ".$TESTN." -x --exclude-from=".$scriptdir."/backup_backups.exclude ".$OPTIONS.$DIRSOURCE2."/osu".$i."* ".$USER."@".$servername.":".$DIRDESTI2;
			} else {
				$command = "rsync ".$TESTN." -x --exclude-from=".$scriptdir."/backup_backups.exclude ".$OPTIONS." --backup --backup-dir=".$DIRDESTI2."/backupold_".$HISTODIR." ".$DIRSOURCE2."/osu".$i."* ".$USER."@".$servername.":".$DIRDESTI2;
			}
			print dol_print_date(dol_now(), '%Y-%m-%d %H:%M:%S')." ".$command."\n";
			exec($command, $output, $return_var);

			if ($return_var != 0) {
				$ret2[$servername] = $ret2[$servername] + 1;
				print "ERROR Failed to make rsync for ".$DIRSOURCE2." to ".$servername." ret=".$ret2[$servername]." \n";
				print "Command was: ".$command."\n";
				$totalinstancesfailed += $nbofdir;
				$errstring .="\n".dol_print_date(dol_now(),"%Y-%m-%d %H:%M:%S")." Dir ".$DIRSOURCE2." to ".$servername.". ret=".$ret2[$servername].". Command was: ".$command."\n";
			} else {
				$totalinstancessaved+= $nbofdir;
				print "\n".dol_print_date(dol_now(), '%Y-%m-%d %H:%M:%S')." Scan dir named".$DIRSOURCE2."/osu".$i."*\n";
				foreach ($arraydirlist as $key => $osudir) {
					$osudirbase = basename($osudir);
					if ($nbdu < 50) {
						if (dol_is_dir($homedir."/".$osudirbase."/")) {
							$DELAYUPDATEDUC=-15;
							print "\n".dol_print_date(dol_now(), '%Y-%m-%d %H:%M:%S')."Search if a recent duc file exists with find ".$homedir."/".$osudirbase."/.duc.db -mtime ".$DELAYUPDATEDUC." 2>/dev/null | wc -l";
							//COMMAND find l:270
						} else {
							print "\n".dol_print_date(dol_now(), '%Y-%m-%d %H:%M:%S')."Dir ".$homedir."/".$osudirbase."/ does not exists, we cancel duc for ".$homedir."/".$osudirbase."/";
						}
					} else {
						print "\n".dol_print_date(dol_now(), '%Y-%m-%d %H:%M:%S')." Max nb of update to do reached (".$nbdu."), we cancel duc for ".$homedir."/".$osudirbase."/";
					}
				}
			}
		}
	}
}