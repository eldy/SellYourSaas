<?php
/* Copyright (C) 2004-2019 Laurent Destailleur  <eldy@users.sourceforge.net>
 *
* This program is free software; you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation; either version 3 of the License, or
* (at your option) any later version.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

/**
 *       \file       htdocs/sellyoursaas/backoffice/instance_links.php
 *       \ingroup    societe
 *       \brief      Card of a contact
 */

// Load Dolibarr environment
$res=0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (! $res && ! empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res=@include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
// Try main.inc.php into web root detected using web root caluclated from SCRIPT_FILENAME
$tmp=empty($_SERVER['SCRIPT_FILENAME'])?'':$_SERVER['SCRIPT_FILENAME'];$tmp2=realpath(__FILE__); $i=strlen($tmp)-1; $j=strlen($tmp2)-1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i]==$tmp2[$j]) { $i--; $j--; }
if (! $res && $i > 0 && file_exists(substr($tmp, 0, ($i+1))."/main.inc.php")) $res=@include substr($tmp, 0, ($i+1))."/main.inc.php";
if (! $res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i+1)))."/main.inc.php")) $res=@include dirname(substr($tmp, 0, ($i+1)))."/main.inc.php";
// Try main.inc.php using relative path
if (! $res && file_exists("../../main.inc.php")) $res=@include "../../main.inc.php";
if (! $res && file_exists("../../../main.inc.php")) $res=@include "../../../main.inc.php";
if (! $res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT."/comm/action/class/actioncomm.class.php";
require_once DOL_DOCUMENT_ROOT."/contact/class/contact.class.php";
require_once DOL_DOCUMENT_ROOT."/contrat/class/contrat.class.php";
require_once DOL_DOCUMENT_ROOT."/core/lib/contract.lib.php";
require_once DOL_DOCUMENT_ROOT."/core/lib/company.lib.php";
require_once DOL_DOCUMENT_ROOT."/core/lib/date.lib.php";
require_once DOL_DOCUMENT_ROOT."/core/lib/security2.lib.php";
require_once DOL_DOCUMENT_ROOT."/core/class/html.formcompany.class.php";
dol_include_once("/sellyoursaas/core/lib/dolicloud.lib.php");

$langs->loadLangs(array("admin","companies","users","contracts","other","commercial","sellyoursaas@sellyoursaas"));

$mesg='';

$action		= (GETPOST('action', 'alpha') ? GETPOST('action', 'alpha') : 'view');
$confirm	= GETPOST('confirm', 'alpha');
$backtopage = GETPOST('backtopage', 'alpha');
$id			= GETPOST('id', 'int');
$ref        = GETPOST('ref', 'alpha');
$refold     = GETPOST('refold', 'alpha');

$error=0; $errors=array();

$object = new Contrat($db);

// Initialize technical object to manage hooks of page. Note that conf->hooks_modules contains array of hook context
$hookmanager->initHooks(array('contractcard','globalcard'));

if ($id > 0 || $ref) {
	$result=$object->fetch($id, $ref);
	if ($result < 0) dol_print_error($db, $object->error);
	$id = $object->id;
}

$now = dol_now();

// Security check
$result = restrictedArea($user, 'sellyoursaas', 0, '', '');


/*
 *	Actions
 */

$parameters=array('id'=>$id, 'objcanvas'=>$objcanvas);
$reshook=$hookmanager->executeHooks('doActions', $parameters, $object, $action);    // Note that $action and $object may have been modified by some hooks
if (empty($reshook)) {
	// Cancel
	if (GETPOST('cancel', 'alpha') && ! empty($backtopage)) {
		header("Location: ".$backtopage);
		exit;
	}

	// Add action to create file, etc...
	require 'refresh_action.inc.php';

	if ($action == 'markasspamandclose') {
		$idtoclose = GETPOST('idtoclose', 'int');
		$tmpcontract = new Contrat($db);
		$tmpcontract->fetch($idtoclose);
		$tmpcontract->array_options['spammer'] = 1;
		$tmpcontract->update($user, 1);

		$result = $tmpcontract->closeAll($user, 0, 'Closed by spammer inspector.');
		if ($result > 0) {
			setEventMessages("OK", null, 'mesgs');
		} else {
			setEventMessages($tmpcontract->error, $tmpcontract->errors, 'errors');
		}
	}

	if ($action == 'addspamtracker' || $action == 'removespamtracker') {
		$idtoclose = GETPOST('idtotrack', 'int');
		$tmpcontract = new Contrat($db);
		$tmpcontract->fetch($idtoclose);

		$server = $tmpcontract->ref_customer;
		$hostname_db = $tmpcontract->hostname_db;
		if (empty($hostname_db)) $hostname_db = $tmpcontract->array_options['options_hostname_db'];
		$port_db = $tmpcontract->port_db;
		if (empty($port_db)) $port_db = (! empty($tmpcontract->array_options['options_port_db']) ? $tmpcontract->array_options['options_port_db'] : 3306);
		$username_db = $tmpcontract->username_db;
		if (empty($username_db)) $username_db = $tmpcontract->array_options['options_username_db'];
		$password_db = $object->password_db;
		if (empty($password_db)) $password_db = $tmpcontract->array_options['options_password_db'];
		$database_db = $object->database_db;
		if (empty($database_db)) $database_db = $tmpcontract->array_options['options_database_db'];

		$server = (! empty($hostname_db) ? $hostname_db : $server);

		$newdb=getDoliDBInstance('mysqli', $server, $username_db, $password_db, $database_db, $port_db);

		if ($newdb) {
			$urlmyaccount = $savconf->global->SELLYOURSAAS_ACCOUNT_URL;

			$tmpthirdparty = new Societe($db);
			$ret = $tmpthirdparty->fetch($tmpcontract->fk_soc);
			if ($ret > 0) {
				if (! empty($tmpthirdparty->array_options['options_domain_registration_page'])
					&& $tmpthirdparty->array_options['options_domain_registration_page'] != $conf->global->SELLYOURSAAS_MAIN_DOMAIN_NAME) {
					$constforaltname = $tmpthirdparty->array_options['options_domain_registration_page'];
					$newurlkey = 'SELLYOURSAAS_ACCOUNT_URL-'.$constforaltname;
					if (! empty($conf->global->$newurlkey)) {
						$urlmyaccount = $conf->global->$newurlkey;
					} else {
						$urlmyaccount = preg_replace('/'.$conf->global->SELLYOURSAAS_MAIN_DOMAIN_NAME.'/', $tmpthirdparty->array_options['options_domain_registration_page'], $urlmyaccount);
					}
				}
			}

			include_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
			$stringtosave = '<script type="text/javascript" src="'.$urlmyaccount.'/public/localdata.js"></script>';
			if ($action == 'removespamtracker') $stringtosave = '';
			dolibarr_set_const($newdb, 'MAIN_HOME', $stringtosave);
			//$tmpcontract->array_options['spammer'] = 1;
			//$result= $tmpcontract->update($user, 1);
			if ($result > 0) {
				setEventMessages("Tracker added on login page of instance ".$server, null, 'mesgs');
			} else {
				setEventMessages($tmpcontract->error, $tmpcontract->errors, 'errors');
			}
		} else {
			dol_print_error($newdb);
		}
	}

	if ($action == 'getiplist') {
		header('Content-Type: text/csv');
		header('Content-Disposition: attachment;filename=listofips.txt');

		// Get all instances in chain
		$arraylistofinstances = getListOfInstancesInChain($object);

		$arrayofips=array();

		foreach ($arraylistofinstances as $instance) {
			$arrayofips[] = $instance->array_options['options_deployment_ip'];
		}

		$arrayofips = array_unique($arrayofips);
		sort($arrayofips);

		foreach ($arrayofips as $key => $val) {
			print $val."\n";
		}

		exit;
	}

	$action = 'view';
}


/*
 *	View
 */

$help_url='';
llxHeader('', $langs->trans("DoliCloudInstances"), $help_url);

$form = new Form($db);
$form2 = new Form($db2);
$formcompany = new FormCompany($db);

$countrynotdefined=$langs->trans("ErrorSetACountryFirst").' ('.$langs->trans("SeeAbove").')';

if ($action != 'create') {
	// Show tabs
	$head = contract_prepare_head($object);

	$title = $langs->trans("Contract");
	dol_fiche_head($head, 'upgrade', $title, -1, 'contract');
}

if ($id > 0 && $action != 'edit' && $action != 'create') {
	/*
	 * Fiche en mode visualisation
	 */

	$instance = 'xxxx';
	$type_db = $conf->db->type;

	$object->fetch_thirdparty();

	$hostname_db  = $object->array_options['options_hostname_db'];
	$username_db  = $object->array_options['options_username_db'];
	$password_db  = $object->array_options['options_password_db'];
	$database_db  = $object->array_options['options_database_db'];
	$port_db      = $object->array_options['options_port_db'];
	$hostname_os  = $object->array_options['options_hostname_os'];
	$username_os  = $object->array_options['options_username_os'];
	$password_os  = $object->array_options['options_password_os'];
	$username_web = $object->thirdparty->email;
	$password_web = $object->thirdparty->array_options['options_password'];

	$tmp = explode('.', $object->ref_customer, 2);
	$object->instance = $tmp[0];

	$object->hostname_db  = $hostname_db;
	$object->username_db  = $username_db;
	$object->password_db  = $password_db;
	$object->database_db  = $database_db;
	$object->port_db      = $port_db;
	$object->username_os  = $username_os;
	$object->password_os  = $password_os;
	$object->hostname_os  = $hostname_os;
	$object->username_web = $username_web;
	$object->password_web = $password_web;
	$object->hostname_web = $hostname_os;

	$newdb=getDoliDBInstance($type_db, $hostname_db, $username_db, $password_db, $database_db, ($port_db?$port_db:3306));

	$stringofversion = '';
	$stringoflistofmodules = '';

	if (is_object($newdb) && $newdb->connected) {
		// Get $lastloginadmin, $lastpassadmin, $stringoflistofmodules
		$lastloginadmin = '';
		$lastpassadmin = '';
		$stringoflistofmodules='';

		$fordolibarr = 1;
		if (preg_match('/glpi-network\.cloud/', $object->ref_customer)) {
			$forglpi = 1;
		}

		// Get user/pass of last admin user
		if ($fordolibarr) {
			// TODO Put the definition of sql to get last used admin user into the package.
			$sql="SELECT login, pass FROM llx_user WHERE admin = 1 ORDER BY statut DESC, datelastlogin DESC LIMIT 1";
			if (preg_match('/glpi-network\.cloud/', $object->ref_customer)) {
				$sql="SELECT name as login, password as pass FROM glpi_users WHERE 1 = 1 ORDER BY is_active DESC, last_login DESC LIMIT 1";
			}

			$resql=$newdb->query($sql);
			if ($resql) {
				$obj = $newdb->fetch_object($resql);
				$object->lastlogin_admin = $obj->login;
				$object->lastpass_admin = $obj->pass;
				$lastloginadmin = $object->lastlogin_admin;
				$lastpassadmin = $object->lastpass_admin;
			} else {
				setEventMessages('Success to connect to server, but failed to read last admin/pass user: '.$newdb->lasterror(), null, 'errors');
			}
		}

		// Get user/pass of last admin user
		if ($forglpi) {
			// TODO Put the definition of sql to get last used admin user into the package.
			$sql="SELECT name as login, password as pass FROM glpi_users WHERE 1 = 1 ORDER BY is_active DESC, last_login DESC LIMIT 1";

			$resql=$newdb->query($sql);
			if ($resql) {
				$obj = $newdb->fetch_object($resql);
				$object->lastlogin_admin = $obj->login;
				$object->lastpass_admin = $obj->pass;
				$lastloginadmin = $object->lastlogin_admin;
				$lastpassadmin = $object->lastpass_admin;
			} else {
				setEventMessages('Success to connect to server, but failed to read last admin/pass user: '.$newdb->lasterror(), null, 'errors');
			}
		}

		// Get $stringofversion and $stringoflistofmodules
		// TODO Put the defintion in a sql into package
		if ($fordolibarr) {
			$confinstance = new Conf();
			$confinstance->setValues($newdb);

			// Define $stringofversion
			$stringofversion = 'MAIN_VERSION_LAST_INSTALL='.$confinstance->global->MAIN_VERSION_LAST_INSTALL.' / MAIN_VERSION_LAST_UPGRADE='.$confinstance->global->MAIN_VERSION_LAST_UPGRADE;

			// Define $stringoflistofmodules
			$i=0;
			foreach ($confinstance->global as $key => $val) {
				if (preg_match('/^MAIN_MODULE_[^_]+$/', $key) && ! empty($val)) {
					if ($i > 0) $stringoflistofmodules .= ', ';
					$stringoflistofmodules .= preg_replace('/^MAIN_MODULE_/', '', $key);
					$i++;
				}
			}
		}
	}


	if (is_object($object->db2)) {
		$savdb=$object->db;
		$object->db=$object->db2;	// To have ->db to point to db2 for showrefnav function.  $db = master database
	}


	$object->fetch_thirdparty();

	//$object->email = $object->thirdparty->email;

	// Contract card

	$linkback = '<a href="'.DOL_URL_ROOT.'/contrat/list.php?restore_lastsearch_values=1'.(! empty($socid)?'&socid='.$socid:'').'">'.$langs->trans("BackToList").'</a>';

	$morehtmlref='';

	$morehtmlref.='<div class="refidno">';
	// Ref customer
	$morehtmlref.=$form->editfieldkey("RefCustomer", 'ref_customer', $object->ref_customer, $object, 0, 'string', '', 0, 1);
	$morehtmlref.=$form->editfieldval("RefCustomer", 'ref_customer', $object->ref_customer, $object, 0, 'string', '', null, null, '', 1, 'getFormatedCustomerRef');
	// Ref supplier
	$morehtmlref.='<br>';
	$morehtmlref.=$form->editfieldkey("RefSupplier", 'ref_supplier', $object->ref_supplier, $object, 0, 'string', '', 0, 1);
	$morehtmlref.=$form->editfieldval("RefSupplier", 'ref_supplier', $object->ref_supplier, $object, 0, 'string', '', null, null, '', 1, 'getFormatedSupplierRef');
	// Thirdparty
	$morehtmlref.='<br>'.$langs->trans('ThirdParty') . ' : ' . $object->thirdparty->getNomUrl(1);
	// Project
	if (! empty($conf->projet->enabled)) {
		$langs->load("projects");
		$morehtmlref.='<br>'.$langs->trans('Project') . ' : ';
		if (0) {
			if ($action != 'classify')
				$morehtmlref.='<a class="editfielda" href="' . $_SERVER['PHP_SELF'] . '?action=classify&amp;id=' . $object->id . '">' . img_edit($langs->transnoentitiesnoconv('SetProject')) . '</a> : ';
			if ($action == 'classify') {
				//$morehtmlref.=$form->form_project($_SERVER['PHP_SELF'] . '?id=' . $object->id, $object->socid, $object->fk_project, 'projectid', 0, 0, 1, 1);
				$morehtmlref.='<form method="post" action="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'">';
				$morehtmlref.='<input type="hidden" name="action" value="classin">';
				$morehtmlref.='<input type="hidden" name="token" value="'.newToken().'">';
				$morehtmlref.=$formproject->select_projects($object->thirdparty->id, $object->fk_project, 'projectid', $maxlength, 0, 1, 0, 1, 0, 0, '', 1);
				$morehtmlref.='<input type="submit" class="button valignmiddle" value="'.$langs->trans("Modify").'">';
				$morehtmlref.='</form>';
			} else {
				$morehtmlref.=$form->form_project($_SERVER['PHP_SELF'] . '?id=' . $object->id, $object->thirdparty->id, $object->fk_project, 'none', 0, 0, 0, 1);
			}
		} else {
			if (! empty($object->fk_project)) {
				$proj = new Project($db);
				$proj->fetch($object->fk_project);
				$morehtmlref.='<a href="'.DOL_URL_ROOT.'/projet/card.php?id=' . $object->fk_project . '" title="' . $langs->trans('ShowProject') . '">';
				$morehtmlref.=$proj->ref;
				$morehtmlref.='</a>';
			} else {
				$morehtmlref.='';
			}
		}
	}
	$morehtmlref.='</div>';

	//dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'none', $morehtmlref);

	$nodbprefix=0;

	dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref, '', $nodbprefix, '', '', 1);


	if (is_object($object->db2)) {
		$object->db=$savdb;
	}

	print '<div class="fichecenter">';

	$backupdir=$conf->global->DOLICLOUD_BACKUP_PATH;

	$dirdb=preg_replace('/_([a-zA-Z0-9]+)/', '', $object->database_db);
	$login=$object->username_web;
	$password=$object->password_web;

	$server=$object->ref_customer;

	// Barre d'actions
	/*  if (! $user->societe_id)
	{
		print '<div class="tabsAction">';

		if ($user->rights->sellyoursaas->write)
		{
			print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'&amp;action=upgrade">'.$langs->trans('Upgrade').'</a>';
		}

		print "</div><br>";
	}
	*/

	print '</div>';
}


if ($id > 0) {
	dol_fiche_end();
}


if (empty($object->nbofusers)) {    // If value not already loaded
	// Try to get data
	if (is_object($newdb) && $newdb->connected) {
		$contract = $object;

		//var_dump($object->lines);
		foreach ($object->lines as $contractline) {
			if (empty($contractline->fk_product)) continue;
			$producttmp = new Product($db);
			$producttmp->fetch($contractline->fk_product, '', '', '', 1, 1, 1);

			// If this is a line for a metric
			if ($producttmp->array_options['options_app_or_option'] == 'system' && $producttmp->array_options['options_resource_formula']
				&& ($producttmp->array_options['options_resource_label'] == 'User' || preg_match('/user/i', $producttmp->ref))) {

				$generatedunixlogin=$contract->array_options['options_username_os'];
				$generatedunixpassword=$contract->array_options['options_password_os'];
				$tmp=explode('.', $object->ref_customer, 2);
				$sldAndSubdomain=$tmp[0];
				$domainname=$tmp[1];
				$generateddbname      =$contract->array_options['options_database_db'];
				$generateddbport      =($contract->array_options['options_port_db']?$contract->array_options['options_port_db']:3306);
				$generateddbusername  =$contract->array_options['options_username_db'];
				$generateddbpassword  =$contract->array_options['options_password_db'];
				$generateddbprefix    =($contract->array_options['options_prefix_db']?$contract->array_options['options_prefix_db']:'llx_');
				$generatedunixhostname=$contract->array_options['options_hostname_os'];
				$generateddbhostname  =$contract->array_options['options_hostname_db'];
				$generateduniquekey   =getRandomPassword(true);

				// Replace __INSTANCEDIR__, __INSTALLHOURS__, __INSTALLMINUTES__, __OSUSERNAME__, __APPUNIQUEKEY__, __APPDOMAIN__, ...
				$substitarray=array(
					/*'__INSTANCEDIR__'=>$targetdir.'/'.$generatedunixlogin.'/'.$generateddbname,*/
					'__INSTANCEDBPREFIX__'=>$generateddbprefix,
					'__DOL_DATA_ROOT__'=>DOL_DATA_ROOT,
					'__INSTALLHOURS__'=>dol_print_date($now, '%H'),
					'__INSTALLMINUTES__'=>dol_print_date($now, '%M'),
					'__OSHOSTNAME__'=>$generatedunixhostname,
					'__OSUSERNAME__'=>$generatedunixlogin,
					'__OSPASSWORD__'=>$generatedunixpassword,
					'__DBHOSTNAME__'=>$generateddbhostname,
					'__DBNAME__'=>$generateddbname,
					'__DBPORT__'=>$generateddbport,
					'__DBUSER__'=>$generateddbusername,
					'__DBPASSWORD__'=>$generateddbpassword,
					/*'__PACKAGEREF__'=> $tmppackage->ref,
					'__PACKAGENAME__'=> $tmppackage->label,
					'__APPUSERNAME__'=>$appusername,
					'__APPEMAIL__'=>$email,
					'__APPPASSWORD__'=>$password,
					'__APPPASSWORD0__'=>$password0,
					'__APPPASSWORDMD5__'=>$passwordmd5,
					'__APPPASSWORDSHA256__'=>$passwordsha256,
					'__APPPASSWORDPASSWORD_HASH__'=>$passwordpassword_hash,
					'__APPPASSWORD0SALTED__'=>$password0salted,
					'__APPPASSWORDMD5SALTED__'=>$passwordmd5salted,
					'__APPPASSWORDSHA256SALTED__'=>$passwordsha256salted,*/
					'__APPUNIQUEKEY__'=>$generateduniquekey,
					'__APPDOMAIN__'=>$sldAndSubdomain.'.'.$domainname,
					'__SELLYOURSAAS_LOGIN_FOR_SUPPORT__'=>$conf->global->SELLYOURSAAS_LOGIN_FOR_SUPPORT
				);

				$tmparray=explode(':', $producttmp->array_options['options_resource_formula'], 2);
				if ($tmparray[0] == 'SQL') {
					$sqlformula = make_substitutions($tmparray[1], $substitarray);

					dol_syslog("Try to connect to instance database (at ".$generateddbhostname.") to execute formula calculation");

					//var_dump($generateddbhostname);	// fqn name dedicated to instance in dns
					//var_dump($serverdeployement);		// just ip of deployement server
					//$dbinstance = @getDoliDBInstance('mysqli', $generateddbhostname, $generateddbusername, $generateddbpassword, $generateddbname, $generateddbport);
					$dbinstance = @getDoliDBInstance('mysqli', $generateddbhostname, $generateddbusername, $generateddbpassword, $generateddbname, $generateddbport);
					if (! $dbinstance || ! $dbinstance->connected) {
						$error++;
						setEventMessages($dbinstance->error, $dbinstance->errors, 'errors');
					} else {
						dol_syslog("Execute sql=".$sqlformula);
						$resql = $dbinstance->query($sqlformula);
						if ($resql) {
							$objsql = $dbinstance->fetch_object($resql);
							if ($objsql) {
								$object->nbofusers += $objsql->nb;
							} else {
								$error++;
								setEventMessages('SQL to get resource return nothing', null, 'errors');
							}
						} else {
							$error++;
							setEventMessages($dbinstance->error, $dbinstance->errors, 'errors');
						}
					}
				} else {
					$error++;
					setEventMessages('No SQL formula found for this metric', null, 'errors');
				}
			}
		}
		/*$sql="SELECT COUNT(login) as nbofusers FROM llx_user WHERE statut <> 0 AND login <> '".$conf->global->SELLYOURSAAS_LOGIN_FOR_SUPPORT."'";
		$resql=$newdb->query($sql);
		if ($resql)
		{
			$obj = $newdb->fetch_object($resql);
			$object->nbofusers	= $obj->nbofusers;
		}
		else
		{
			setEventMessages('Failed to read remote customer instance: '.$newdb->lasterror(),'','warnings');
		}*/
	}
}


// Some data of instance

print '<div class="fichecenter">';

print '<table class="noborder centpercent tableforfield">';

// Nb of users
print '<tr><td width="20%">'.$langs->trans("NbOfUsers").'</td><td><font size="+2">'.round($object->nbofusers).'</font></td>';
print '<td></td><td>';
if (! $object->user_id && $user->rights->sellyoursaas->write) {
	print ' <a href="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'&action=refresh&token='.newToken().'">'.img_picto($langs->trans("Refresh"), 'refresh').'</a>';
}
print '</td>';
print '</tr>';

// Authorized key file
print '<tr>';
print '<td>'.$langs->trans("Authorized_keyInstalled").'</td><td>'.($object->array_options['options_fileauthorizekey']?$langs->trans("Yes").' - '.dol_print_date($object->array_options['options_fileauthorizekey'], '%Y-%m-%d %H:%M:%S', 'tzuser'):$langs->trans("No"));
print ' &nbsp; (<a href="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'&action=addauthorizedkey&token='.newToken().'">'.$langs->trans("Create").'</a>)';
print ($object->array_options['options_fileauthorizekey']?' &nbsp; (<a href="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'&action=delauthorizedkey&token='.newToken().'">'.$langs->trans("Delete").'</a>)':'');
print '</td>';
print '<td></td><td></td>';
print '</tr>';

// Install.lock file
print '<tr>';
print '<td>'.$langs->trans("LockfileInstalled").'</td><td>'.($object->array_options['options_filelock']?$langs->trans("Yes").' - '.dol_print_date($object->array_options['options_filelock'], '%Y-%m-%d %H:%M:%S', 'tzuser'):$langs->trans("No"));
print ' &nbsp; (<a href="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'&action=addinstalllock&token='.newToken().'">'.$langs->trans("Create").'</a>)';
print ($object->array_options['options_filelock']?' &nbsp; (<a href="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'&action=delinstalllock&token='.newToken().'">'.$langs->trans("Delete").'</a>)':'');
print '</td>';
print '<td></td><td></td>';
print '</tr>';


// Version

print '<tr>';
print '<td>'.$langs->trans("Version").'</td>';
print '<td colspan="3">'.$stringofversion.'</td>';
print '</tr>';

// Modules
print '<tr>';
print '<td>'.$langs->trans("Modules").'</td>';
print '<td colspan="3">'.$stringoflistofmodules.'</td>';
print '</tr>';

print "</table><br>";

print "</div>";	//  End fiche=center

print '<br>';


print getListOfLinks($object, $lastloginadmin, $lastpassadmin);


//if (! empty($object->array_options['options_cookieregister_previous_instance']))
//{
	// Get all instances in chain
	$arraylistofinstances = getListOfInstancesInChain($object);

	print '<br>';
	print_barre_liste($langs->trans("ChainOfRegistrations"), '', '', '', '', '', '', '', 0);

	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder" width="100%">';

	print '<tr>';
	print '<td>'.$langs->trans("Instance").'</td>';
	print '<td>'.$langs->trans("RefCustomer").'</td>';
	print '<td>'.$langs->trans("RegistrationCounter").'</td>';
	print '<td>'.$langs->trans("IP").'</td>';
	print '<td>'.$langs->trans("DeploymentIPVPNProba").'</td>';
	print '<td>'.$langs->trans("Date").'</td>';
	print '<td>'.$langs->trans("Status").'</td>';
	print '<td></td>';
	print '</tr>';

	$arrayofips=array();

foreach ($arraylistofinstances as $instance) {
	$arrayofips[] = $instance->array_options['options_deployment_ip'];

	// Nb of users
	print '<tr>';
	print '<td>'.$instance->getNomUrl(1).'</td>';
	print '<td>'.$instance->getFormatedCustomerRef($instance->ref_customer).'</td>';
	print '<td>'.$instance->array_options['options_cookieregister_counter'].'</td>';
	print '<td>'.$instance->array_options['options_deployment_ip'].'</td>';
	print '<td>'.$instance->array_options['options_deployment_vpn_proba'].'</td>';
	print '<td>'.dol_print_date($instance->array_options['options_deployment_date_start'], 'dayhour').'</td>';
	print '<td>'.$instance->getLibStatut(7).'</td>';
	print '<td align="right">';
	if ($user->rights->sellyoursaas->write) {
		print ' <a class="reposition" href="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'&action=markasspamandclose&token='.newToken().'&idtoclose='.$instance->id.'">'.$langs->trans("MarkAsSpamAndClose").'</a>';
		if (!empty($conf->global->SELLYOURSAAS_ADD_SPAMER_JS_SCANNER)) {
			print ' &nbsp; ';
			print ' <a class="reposition" href="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'&action=addspamtracker&token='.newtoken().'&idtotrack='.$instance->id.'">'.$langs->trans("AddAntiSpamTracker").'</a>';
			print ' &nbsp; ';
			print ' <a class="reposition" href="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'&action=removespamtracker&token='.newToken().'&idtotrack='.$instance->id.'">'.$langs->trans("RemoveAntiSpamTracker").'</a>';
		}
	}
	print '</td>';
	print '</tr>';
}

	print '<tr class="liste_total">';
	print '<td></td>';
	print '<td></td>';
	print '<td></td>';
	print '<td>';
	print '<a class="reposition" href="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'&action=getiplist">'.$langs->trans("GetFileOfIps").'</a>';
	print '</td>';
	print '<td></td>';
	print '<td></td>';
	print '<td></td>';
	print '<td></td>';
	print '</tr>';

	print '</table>';
	print '</div>';
//}


llxFooter();

$db->close();
