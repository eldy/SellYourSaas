<?php
/* Copyright (C) 2011-2018 Laurent Destailleur <eldy@users.sourceforge.net>
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

// $initialaction can be set

// Protection to avoid direct call of template
if (empty($conf) || ! is_object($conf)) {
	print "Error, template page can't be called as URL";
	exit(1);
}

?>
<!-- BEGIN PHP TEMPLATE instances.tpl.php -->
<?php

// SERVER_NAME here is myaccount.mydomain.com (we can exploit only the part mydomain.com)
$domainname = getDomainFromURL($_SERVER["SERVER_NAME"], 1);
$forcesubdomain = GETPOST('forcesubdomain', 'alpha');

$arrayofplans=array();
$arrayofplansfull=array();
$arrayofoptionsfull=array();

// List of available plans/products
$sqlproducts = 'SELECT p.rowid, p.ref, p.label, p.price, p.price_ttc, p.duration, pe.availabelforresellers, pa.restrict_domains';
$sqlproducts.= ' FROM '.MAIN_DB_PREFIX.'product as p, '.MAIN_DB_PREFIX.'product_extrafields as pe';
$sqlproducts.= ' LEFT JOIN '.MAIN_DB_PREFIX.'packages as pa ON pe.package = pa.rowid';
$sqlproducts.= ' WHERE p.tosell = 1 AND p.entity = '.((int) $conf->entity);
$sqlproducts.= " AND pe.availabelforresellers > 0";		// available in dashboard (customers + resellers)
$sqlproducts.= " AND pe.fk_object = p.rowid AND pe.app_or_option = 'app'";
$sqlproducts.= " AND (pa.restrict_domains IS NULL"; // restict_domains can be empty (it's ok)
$sqlproducts.= " OR pa.restrict_domains = '".$db->escape($domainname)."'"; // can be mydomain.com
$sqlproducts.= " OR pa.restrict_domains LIKE '%.".$db->escape($domainname)."'"; // can be with.mydomain.com or the last domain of [mydomain1.com,with.mydomain2.com]
$sqlproducts.= " OR pa.restrict_domains LIKE '%.".$db->escape($domainname).",%'"; // can be the first or the middle domain of [with.mydomain1.com,with.mydomain2.com,mydomain3.com]
$sqlproducts.= " OR pa.restrict_domains LIKE '".$db->escape($domainname).",%'"; // can be the first domain of [mydomain1.com,mydomain2.com]
$sqlproducts.= " OR pa.restrict_domains LIKE '%,".$db->escape($domainname).",%'"; // can be the middle domain of [mydomain1.com,mydomain2.com,mydomain3.com]
$sqlproducts.= " OR pa.restrict_domains LIKE '%,".$db->escape($domainname)."'"; // can be the last domain of [mydomain1.com,mydomain2.com]
$sqlproducts.= ")";
$sqlproducts.= " ORDER BY pe.position ASC";

$resqlproducts = $db->query($sqlproducts);
if ($resqlproducts) {
	$num = $db->num_rows($resqlproducts);

	$tmpprod = new Product($db);
	$tmpprodchild = new Product($db);
	$i=0;
	while ($i < $num) {
		$obj = $db->fetch_object($resqlproducts);
		if ($obj) {
			$tmpprod->fetch($obj->rowid, '', '', '', 1, 1, 1);
			$tmpprod->sousprods = array();
			$tmpprod->get_sousproduits_arbo();
			$tmparray = $tmpprod->get_arbo_each_prod(1, 1);

			$label = $obj->label;

			$priceinstance=array();
			$priceinstance_ttc=array();

			$priceinstance['fix'] = $obj->price;
			$priceinstance_ttc['fix'] = $obj->price_ttc;
			$priceinstance['user'] = 0;
			$priceinstance_ttc['user'] = 0;

			if (count($tmparray) > 0) {
				foreach ($tmparray as $key => $value) {
					$tmpprodchild->fetch($value['id']);
					if (preg_match('/user/i', $tmpprodchild->ref) || preg_match('/user/i', $tmpprodchild->array_options['options_resource_label'])) {
						$priceinstance['user'] += $tmpprodchild->price;
						$priceinstance_ttc['user'] += $tmpprodchild->price_ttc;
					} elseif ($tmpprodchild->array_options['options_app_or_option'] == 'system') {
						// Don't add system services to global price, these are options with calculated quantities
					} else {
						$priceinstance['fix'] += $tmpprodchild->price;
						$priceinstance_ttc['fix'] += $tmpprodchild->price_ttc;
					}
					//var_dump($tmpprodchild->id.' '.$tmpprodchild->array_options['options_app_or_option'].' '.$tmpprodchild->price_ttc.' -> '.$priceuser.' / '.$priceuser_ttc);
				}
			}

			$pricetoshow = price2num($priceinstance['fix'], 'MT');
			if (empty($pricetoshow)) {
				$pricetoshow = 0;
			}
			$arrayofplans[$obj->rowid] = $label;

			// Set $priceforlabel
			$priceforlabel = '('.price($pricetoshow, 1, $langs, 1, 0, -1, $conf->currency);
			$tmpduration = '';
			if ($tmpprod->duration) {
				if ($tmpprod->duration == '1m') {
					$tmpduration.=' / '.$langs->trans("Month");
				} elseif ($tmpprod->duration == '1y') {
					$tmpduration.=' / '.$langs->trans("DurationYear");
				} else {
					$regs = array();
					preg_match('/^([0-9]+)([a-z]{1})$/', $tmpprod->duration, $regs);
					if (! empty($regs[1]) && ! empty($regs[2])) {
						$tmpduration.=' / '.$regs[1].' '.($regs[2] == 'm' ? $langs->trans("Month") : ($regs[2] == 'y' ? $langs->trans("DurationYear") : ''));
					}
				}
			}

			if ($tmpprod->duration) {
				$priceforlabel .= $tmpduration;
			}
			if ($priceinstance['user']) {
				$priceforlabel .= ' + '.price(price2num($priceinstance['user'], 'MT'), 1, $langs, 1, 0, -1, $conf->currency).' / '.$langs->trans("User");
				if ($tmpprod->duration) {
					$priceforlabel .= $tmpduration;
				}
			}
			$priceforlabel .= ')';

			$arrayofplans[$obj->rowid] .= ' '.$priceforlabel;

			$arrayofplansfull[$obj->rowid]['id'] = $obj->rowid;
			$arrayofplansfull[$obj->rowid]['label'] = $arrayofplans[$obj->rowid];
			$arrayofplansfull[$obj->rowid]['data-html'] = $label.' <span class="opacitymedium">'.$priceforlabel.'</span>';
			$arrayofplansfull[$obj->rowid]['restrict_domains'] = $obj->restrict_domains;
		}
		$i++;
	}
} else {
	dol_print_error($db);
}


// List of available options
$sqlproducts = 'SELECT p.rowid, p.ref, p.label, p.price, p.price_ttc, p.duration, pe.availabelforresellers, pa.restrict_domains';
$sqlproducts.= ' FROM '.MAIN_DB_PREFIX.'product as p, '.MAIN_DB_PREFIX.'product_extrafields as pe';
$sqlproducts.= ' LEFT JOIN '.MAIN_DB_PREFIX.'packages as pa ON pe.package = pa.rowid';
$sqlproducts.= ' WHERE p.tosell = 1 AND p.entity = '.((int) $conf->entity);
$sqlproducts.= " AND pe.fk_object = p.rowid AND pe.app_or_option = 'option'";
//$sqlproducts.= " AND pe.availabelforresellers > 0";		// Not used on options
//$sqlproducts.= " AND pe.option_condition = '1'";	// We keep all options, we will filter later
$sqlproducts.= " AND (pa.restrict_domains IS NULL"; // restict_domains can be empty (it's ok)
$sqlproducts.= " OR pa.restrict_domains = '".$db->escape($domainname)."'"; // can be mydomain.com
$sqlproducts.= " OR pa.restrict_domains LIKE '%.".$db->escape($domainname)."'"; // can be with.mydomain.com or the last domain of [mydomain1.com,with.mydomain2.com]
$sqlproducts.= " OR pa.restrict_domains LIKE '%.".$db->escape($domainname).",%'"; // can be the first or the middle domain of [with.mydomain1.com,with.mydomain2.com,mydomain3.com]
$sqlproducts.= " OR pa.restrict_domains LIKE '".$db->escape($domainname).",%'"; // can be the first domain of [mydomain1.com,mydomain2.com]
$sqlproducts.= " OR pa.restrict_domains LIKE '%,".$db->escape($domainname).",%'"; // can be the middle domain of [mydomain1.com,mydomain2.com,mydomain3.com]
$sqlproducts.= " OR pa.restrict_domains LIKE '%,".$db->escape($domainname)."'"; // can be the last domain of [mydomain1.com,mydomain2.com]
$sqlproducts.= ")";
$sqlproducts.= " ORDER BY pe.position ASC";

$resqloptions = $db->query($sqlproducts);
if ($resqloptions) {
	$num = $db->num_rows($resqloptions);

	$i=0;
	while ($i < $num) {
		$obj = $db->fetch_object($resqloptions);
		if ($obj) {
			$tmpprod = new Product($db);
			//$tmpprodchild = new Product($db);
			$tmpprod->fetch($obj->rowid, '', '', '', 1, 1, 0);
			//$tmpprod->sousprods = array();
			//$tmpprod->get_sousproduits_arbo();
			//$tmparray = $tmpprod->get_arbo_each_prod(1, 1);

			$label = $obj->label;

			$priceoption=array();
			$priceoption_ttc=array();

			$priceoption['fix'] = $obj->price;
			$priceoption_ttc['fix'] = $obj->price_ttc;
			$priceoption['user'] = 0;
			$priceoption_ttc['user'] = 0;

			/*
			if (count($tmparray) > 0) {
				foreach ($tmparray as $key => $value) {
					$tmpprodchild->fetch($value['id']);
					if (preg_match('/user/i', $tmpprodchild->ref) || preg_match('/user/i', $tmpprodchild->array_options['options_resource_label'])) {
						$priceoption['user'] += $tmpprodchild->price;
						$priceoption_ttc['user'] += $tmpprodchild->price_ttc;
					} elseif ($tmpprodchild->array_options['options_app_or_option'] == 'system') {
						// Don't add system services to global price, these are options with calculated quantities
					} else {
						$priceoption['fix'] += $tmpprodchild->price;
						$priceoption_ttc['fix'] += $tmpprodchild->price_ttc;
					}
					//var_dump($tmpprodchild->id.' '.$tmpprodchild->array_options['options_app_or_option'].' '.$tmpprodchild->price_ttc.' -> '.$priceuser.' / '.$priceuser_ttc);
				}
			}
			*/

			$pricetoshow = price2num($priceoption['fix'], 'MT');
			if (empty($pricetoshow)) {
				$pricetoshow = 0;
			}
			$labelprice = price($pricetoshow, 1, $langs, 1, 0, -1, $conf->currency);
			$tmpduration = '';
			if ($tmpprod->duration) {
				if ($tmpprod->duration == '1m') {
					$tmpduration.=' / '.$langs->trans("Month");
				} elseif ($tmpprod->duration == '1y') {
					$tmpduration.=' / '.$langs->trans("DurationYear");
				} else {
					$regs = array();
					preg_match('/^([0-9]+)([a-z]{1})$/', $tmpprod->duration, $regs);
					if (! empty($regs[1]) && ! empty($regs[2])) {
						$tmpduration.=' / '.$regs[1].' '.($regs[2] == 'm' ? $langs->trans("Month") : ($regs[2] == 'y' ? $langs->trans("DurationYear") : ''));
					}
				}
			}

			if ($tmpprod->duration) {
				$labelprice.=$tmpduration;
			}
			if ($priceoption['user']) {
				$labelprice.=' + '.price(price2num($priceoption['user'], 'MT'), 1, $langs, 1, 0, -1, $conf->currency).' / '.$langs->trans("User");
				if ($tmpprod->duration) {
					$labelprice.=$tmpduration;
				}
			}

			$arrayofoptionsfull[$obj->rowid]['id'] = $obj->rowid;
			$arrayofoptionsfull[$obj->rowid]['label'] = $label;
			$arrayofoptionsfull[$obj->rowid]['restrict_domains'] = $obj->restrict_domains;
			$arrayofoptionsfull[$obj->rowid]['product'] = $tmpprod;
			$arrayofoptionsfull[$obj->rowid]['labelprice'] =($pricetoshow ? $labelprice : '');
		}
		$i++;
	}
} else {
	dol_print_error($db);
}



print '
	<div class="page-content-wrapper">
			<div class="page-content">


 	<!-- BEGIN PAGE HEADER-->
	<!-- BEGIN PAGE HEAD -->
	<div class="page-head">
	  <!-- BEGIN PAGE TITLE -->
	<div class="page-title">
	  <h1>'.$langs->trans("MyInstances").'</h1>
	</div>
	<!-- END PAGE TITLE -->
	</div>
	<!-- END PAGE HEAD -->
	<!-- END PAGE HEADER-->';


if (count($listofcontractid) == 0) {				// If all contracts were removed
	print '<span class="opacitymedium">'.$langs->trans("None").'</span>';
} else {
	$sellyoursaasutils = new SellYourSaasUtils($db);

	$arrayforsort = array();
	foreach ($listofcontractid as $id => $contract) {
		$position = 20;
		if ($contract->array_options['options_deployment_status'] == 'processing') {
			$position = 1;
		}
		if ($contract->array_options['options_deployment_status'] == 'suspended') {
			$position = 10;
		}	// This is not a status
		if ($contract->array_options['options_deployment_status'] == 'done') {
			$position = 20;
		}
		if ($contract->array_options['options_deployment_status'] == 'undeployed') {
			$position = 100;
		}

		$arrayforsort[$id] = array('position'=>$position, 'id'=>$id, 'contract'=>$contract);
	}
	$arrayforsort = dol_sort_array($arrayforsort, 'position');

	foreach ($arrayforsort as $id => $tmparray) {
		$id = $tmparray['id'];
		$contract = $tmparray['contract'];

		// Set environment for this contract
		$type_db = $conf->db->type;
		$hostname_db  = $contract->array_options['options_hostname_db'];
		$username_db  = $contract->array_options['options_username_db'];
		$password_db  = $contract->array_options['options_password_db'];
		$database_db  = $contract->array_options['options_database_db'];
		$port_db      = (!empty($contract->array_options['options_port_db']) ? $contract->array_options['options_port_db'] : 3306);
		$prefix_db    = (!empty($contract->array_options['options_prefix_db']) ? $contract->array_options['options_prefix_db'] : 'llx_');
		$hostname_os  = $contract->array_options['options_hostname_os'];
		$username_os  = $contract->array_options['options_username_os'];
		$password_os  = $contract->array_options['options_password_os'];
		$username_web = $contract->thirdparty->email;
		$password_web = $contract->thirdparty->array_options['options_password'];
		$iphostwebsite = $contract->array_options['options_deployment_host'];

		$websitemodenabled = 0;
		$lastversiondolibarrinstance = 0;

		$newdb = getDoliDBInstance($type_db, $hostname_db, $username_db, $password_db, $database_db, $port_db);
		$newdb->prefix_db = $prefix_db;

		if ($newdb->connected) {
			$confinstance = new Conf();
			$confinstance->setValues($newdb);

			foreach ($confinstance->global as $key => $val) {
				if (preg_match('/^MAIN_MODULE_WEBSITE+$/', $key) && ! empty($val)) {
					$websitemodenabled ++;
				}
			}
			$lastinstallinstance = isset($confinstance->global->MAIN_VERSION_LAST_INSTALL) ? explode(".", $confinstance->global->MAIN_VERSION_LAST_INSTALL)[0] : "0";
			$lastupgradeinstance = isset($confinstance->global->MAIN_VERSION_LAST_UPGRADE) ? explode(".", $confinstance->global->MAIN_VERSION_LAST_UPGRADE)[0] : "0";
			$lastversiondolibarrinstance = max($lastinstallinstance, $lastupgradeinstance);
		}
		// Set newversion from the setup
		$newversiondolibarr = (getDolGlobalString("SELLYOURSAAS_LAST_STABLE_VERSION_DOLIBARR") ? "(v".getDolGlobalString("SELLYOURSAAS_LAST_STABLE_VERSION_DOLIBARR").")" : "");

		// If not set, we try to guess the $newversion from the name of the directory of the package
		$dataofcontract = sellyoursaasGetExpirationDate($contract, 0);
		$tmpproduct = new Product($db);
		$tmppackage = new Packages($db);

		if ($dataofcontract['appproductid'] > 0) {
			$tmpproduct->fetch($dataofcontract['appproductid']);
			$tmppackage->fetch($tmpproduct->array_options['options_package']);

			// Set $newversion. Note this is not the exact version, just major version found into path name.
			//$tmppackage->srcfile1 = 'ddd_16.0';
			//var_dump($tmppackage->srcfile1);
			$newversiondolibarr = preg_replace('/[^0-9\.]/', '', $tmppackage->srcfile1);
		}

		$planref = $contract->array_options['options_plan'];
		$statuslabel = $contract->array_options['options_deployment_status'];
		$statuslabeltitle = '';
		$instancename = preg_replace('/\..*$/', '', $contract->ref_customer);

		$dbprefix = empty($contract->array_options['options_prefix_db']) ? '' : $contract->array_options['options_prefix_db'];
		if (empty($dbprefix)) {
			$dbprefix = 'llx_';
		}

		// Get info about PLAN of Contract
		$planlabel = $planref;			// By default, but we will take the name of service of type 'app' just after

		// Detect what is the plan for this instance
		$planid = 0;
		$freeperioddays = 0;
		$directaccess = empty($contract->array_options['options_directaccess']) ? 0 : $contract->array_options['options_directaccess'];	// O means use the value of the app service.
		foreach ($contract->lines as $keyline => $line) {
			if ($line->statut == ContratLigne::STATUS_CLOSED && $contract->array_options['options_deployment_status'] != 'undeployed') {
				$statuslabel = 'suspended';
			}

			$tmpproduct = new Product($db);
			if ($line->fk_product > 0) {
				$tmpproduct->fetch($line->fk_product);
				if ($tmpproduct->array_options['options_app_or_option'] == 'app') {
					$planref = $tmpproduct->ref;			// Warning, ref is in language of user
					$planlabel = $tmpproduct->label;		// Warning, label is in language of user
					$planid = $tmpproduct->id;
					$freeperioddays = $tmpproduct->array_options['options_freeperioddays'];
					if (empty($directaccess)) {
						$directaccess = $tmpproduct->array_options['options_directaccess'];
					}
					break;
				}
			}
		}
		$color = "#4DB3A2";
		$displayforinstance = "";
		if ($statuslabel == 'processing') {
			$color = 'orange';
		}
		if ($statuslabel == 'suspended') {
			$color = 'orange';
		}
		if ($statuslabel == 'undeployed') {
			$color = 'grey';
			$displayforinstance='display:none;';
		}
		if (preg_match('/^http/i', $contract->array_options['options_suspendmaintenance_message'])) {
			$color = 'lightgrey';
			$displayforinstance='display:none;';
		}


		// Update resources of instance
		if (in_array($statuslabel, array('suspended', 'done')) && ! in_array($initialaction, array('changeplan')) && !preg_match('/^http/i', $contract->array_options['options_suspendmaintenance_message'])) {
			$comment = 'Refresh contract '.$contract->ref.' after entering dashboard';
			$result = $sellyoursaasutils->sellyoursaasRemoteAction('refreshmetrics', $contract, 'admin', '', '', '0', $comment);
			if ($result <= 0) {
				$error++;

				if ($result == -2) {
					// We overwrite status 'suspended' and status 'done' with 'unreachable' (a status only for screen output)
					$statuslabel = 'unreachable';
					$statuslabeltitle = $sellyoursaasutils->error;
					$color = 'orange';
				} else {
					setEventMessages($langs->trans("ErrorRefreshOfResourceFailed", $contract->ref_customer).' : '.$sellyoursaasutils->error, $sellyoursaasutils->errors, 'warnings');
				}
			}
		}


		print '
				<!-- Card for instance -->
			    <div class="row" id="contractid'.$contract->id.'" data-contractref="'.$contract->ref.'">
			      <div class="col-md-12">

					<div class="portlet light">

				      <div class="portlet-title">
				        <div class="caption">';

		print '<form class="inline-block centpercent" action="'.$_SERVER["PHP_SELF"].'" method="POST">';
		print '<input type="hidden" name="token" value="'.newToken().'">';

		// Instance status
		print '<span class="caption-helper floatright clearboth">';
		//print $langs->trans("Status").' : ';
		print '<!-- status = '.dol_escape_htmltag($statuslabel).' -->';
		print '<span class="bold uppercase badge-myaccount-status" style="background-color:'.$color.'; border-radius: 5px; padding: 10px; color: #fff;"'.($statuslabeltitle ? ' title="'.$statuslabeltitle.'"' : '').'>';
		if (preg_match('/^http/i', $contract->array_options['options_suspendmaintenance_message'])) {
			print $langs->trans("Redirection");
		} elseif ($statuslabel == 'processing') {
			print $langs->trans("DeploymentInProgress");
		} elseif ($statuslabel == 'done') {
			print $langs->trans("Alive");
		} elseif ($statuslabel == 'suspended') {
			print $langs->trans("Suspended").' '.img_warning('default', 'style="color: #fff"', 'pictowarning');
		} elseif ($statuslabel == 'undeployed') {
			print $langs->trans("Undeployed");
		} elseif ($statuslabel == 'unreachable') {
			print $langs->trans("Unreachable").' '.img_warning('default', 'style="color: #fff"', 'pictowarning');
		} else {
			print $statuslabel;
		}
		print '</span></span>';

		// Instance name
		print '<span class="bold uppercase">'.$instancename.'</span>';
		print '<span class="caption-helper"> - '.$planlabel.'</span>	<!-- This is product ref -->';

		print '<br>';

		print '<p style="padding-top: 8px;'.($statuslabel == 'undeployed' ? ' margin-bottom: 0px' : '').'" class="clearboth">';

		// ID
		print '<span class="caption-helper small"><span class="opacitymedium">'.$langs->trans("ID").' : </span><span class="font-green-sharp">'.$contract->ref.'</span></span><br>';

		// URL
		if ($statuslabel != 'undeployed') {
			print '<span class="caption-helper"><span class="opacitymedium">';
			if ($conf->dol_optimize_smallscreen) {
				print $langs->trans("URL");
			} else {
				print $langs->trans("YourURLToGoOnYourAppInstance");
			}
			print ' : </span>';
			print '<a class="font-green-sharp linktoinstance" href="https://'.$contract->ref_customer.'" target="blankinstance">';
			print 'https://'.$contract->ref_customer;
			print img_picto($langs->trans("YourURLToGoOnYourAppInstance"), 'globe', 'class="paddingleft"');
			print '</a>';
			print '</span><br>';
		}

		print '<span class="caption-helper">';
		print "\n";
		if ($contract->array_options['options_deployment_status'] == 'processing') {
			print '<span class="opacitymedium">'.$langs->trans("DateStart").' : </span><span class="bold">'.dol_print_date($contract->array_options['options_deployment_date_start'], 'dayhour').'</span>';
			if (($now - $contract->array_options['options_deployment_date_start']) > 120) {	// More than 2 minutes ago
				print ' - <a href="register_instance.php?reusecontractid='.$contract->id.'">'.$langs->trans("Restart").'</a>'; // Link to redeploy / restart deployment
			}
		} elseif ($contract->array_options['options_deployment_status'] == 'done') {
			print '<span class="opacitymedium">'.$langs->trans("DeploymentDate").' : </span><span class="bold">'.dol_print_date($contract->array_options['options_deployment_date_end'], 'dayhour').'</span>';
		} else {
			print '<span class="opacitymedium">'.$langs->trans("DeploymentDate").' : </span><span class="bold">'.dol_print_date($contract->array_options['options_deployment_date_end'], 'dayhour').'</span>';
			print '<br>';
			print '<span class="opacitymedium">'.$langs->trans("UndeploymentDate").' : </span><span class="bold">'.dol_print_date($contract->array_options['options_undeployment_date'], 'dayhour').'</span>';
		}
		print "\n";
		print '</span><br>';

		// Calculate price on invoicing
		$contract->fetchObjectLinked();

		$foundtemplate=0;
		$datenextinvoice='';
		$pricetoshow = '';
		$priceinvoicedht = 0;
		$atleastonediscount = 0;
		$freqlabel = array('d'=>$langs->trans('Day'), 'm'=>$langs->trans('Month'), 'y'=>$langs->trans('Year'));
		if (isset($contract->linkedObjects['facturerec']) && is_array($contract->linkedObjects['facturerec'])) {
			foreach ($contract->linkedObjects['facturerec'] as $idtemplateinvoice => $templateinvoice) {
				$foundtemplate++;
				if ($templateinvoice->suspended && $contract->array_options['options_deployment_status'] == 'undeployed') {
					$pricetoshow = '';
				} else {
					if ($templateinvoice->unit_frequency == 'm' && $templateinvoice->frequency == 1) {
						$pricetoshow = price($templateinvoice->total_ht, 1, $langs, 0, -1, -1, $conf->currency).' '.$langs->trans("HT").' / '.$langs->trans("Month");
						$priceinvoicedht = $templateinvoice->total_ht;
					} elseif ($templateinvoice->unit_frequency == 'y' && $templateinvoice->frequency == 1) {
						$pricetoshow = price($templateinvoice->total_ht, 1, $langs, 0, -1, -1, $conf->currency).' '.$langs->trans("HT").' / '.$langs->trans("Year");
						$priceinvoicedht = $templateinvoice->total_ht;
					} else {
						$pricetoshow  = $templateinvoice->frequency.' '.$freqlabel[$templateinvoice->unit_frequency];
						$pricetoshow .= ', ';
						$pricetoshow .= price($templateinvoice->total_ht, 1, $langs, 0, -1, -1, $conf->currency).' '.$langs->trans("HT");
						$priceinvoicedht = $templateinvoice->total_ht;
					}
					if ($templateinvoice->suspended && $contract->array_options['options_deployment_status'] != 'done') {
						$pricetoshow = $langs->trans("InvoicingSuspended"); // Replace price
					}
				}
				if ((! $templateinvoice->suspended) && $contract->array_options['options_deployment_status'] == 'done') {
					$datenextinvoice = $templateinvoice->date_when;
				}

				// Loop on each line of the template invoice to see if there is a discount
				if (is_array($templateinvoice->lines)) {
					foreach ($templateinvoice->lines as $tmpline) {
						if ($tmpline->remise_percent > 0) {
							$atleastonediscount++;
						}
					}
				}
			}
		}

		print '
				          </p>';
		print '</form>';
		print '</div>';
		print '</div>';

		// Tab for the instance info
		print '<!-- tabs for instance -->'."\n";
		print '<div class="portlet-body" style="'.$displayforinstance.'">

				        <div class="tabbable-custom nav-justified">
				          <ul class="nav nav-tabs nav-justified centpercent">
				            <li><a id="a_tab_resource_'.$contract->id.'" href="#tab_resource_'.$contract->id.'" data-toggle="tab"'.(! in_array($action, array('updateurlxxx')) ? ' class="active"' : '').'>'.$langs->trans("ResourcesAndOptions").'</a></li>';

		print '<li><a id="a_tab_domain_'.$contract->id.'" href="#tab_domain_'.$contract->id.'" data-toggle="tab"'.($action == 'updateurlxxx' ? ' class="active"' : '').'>'.$langs->trans("Domain").'</a></li>';
		if (in_array($statuslabel, array('done','suspended')) && $directaccess) {
			print '<li><a id="a_tab_ssh_'.$contract->id.'" href="#tab_ssh_'.$contract->id.'" data-toggle="tab">'.$langs->trans("SSH").' / '.$langs->trans("SFTP").'</a></li>';
		}
		if (in_array($statuslabel, array('done','suspended')) && $directaccess) {
			print '<li><a id="a_tab_db_'.$contract->id.'" href="#tab_db_'.$contract->id.'" data-toggle="tab">'.$langs->trans("Database").'</a></li>';
		}
		// Specific if we distribute Dolibarr
		if (getDolGlobalString("SELLYOURSAAS_LAST_STABLE_VERSION_DOLIBARR")) {
			if (in_array($statuslabel, array('done','suspended'))) {
				print '<li><a id="a_tab_upgrade_'.$contract->id.'" href="#tab_upgrade_'.$contract->id.'" data-toggle="tab">'.$langs->trans("Upgrade");
				// Disable warning: Being not up to date is not an error but is a common situation.
				//print ($lastversiondolibarrinstance < $newversiondolibarr ? " ".img_warning() : "")
				print '</a></li>';
			}
		}

		if (in_array($statuslabel, array('done','suspended'))) {
			print '<li><a id="a_tab_danger_'.$contract->id.'" href="#tab_danger_'.$contract->id.'" data-toggle="tab">'.$langs->trans("CancelInstance").'</a></li>';
		}
		print '
				          </ul>

				          <div class="tab-content">

				            <div class="tab-pane active" id="tab_resource_'.$contract->id.'">
								<!-- <p class="opacitymedium" style="padding: 15px; margin-bottom: 5px;">'.$langs->trans("YourResourceAndOptionsDesc").' :</p> -->
					            <div class="areaforresources" style="padding-bottom: 12px;">';

		$arrayoflines = $contract->lines;
		//var_dump($arrayoflines);

		// Loop on each service / option enabled
		foreach ($arrayoflines as $keyline => $line) {
			//if ($line->statut != ContratLigne::STATUS_OPEN) continue;     // We need to show even if closed for the dashboard

			//var_dump($line);
			print '<div class="resource inline-block boxresource">';

			$resourceformula='';
			$tmpproduct = new Product($db);
			if ($line->fk_product > 0) {
				$tmpproduct->fetch($line->fk_product);

				$maxHeight=40;
				$maxWidth=40;
				$alt='';
				$htmlforphoto = $tmpproduct->show_photos('product', $conf->product->dir_output, 1, 1, 1, 0, 0, $maxHeight, $maxWidth, 1, 1, 1);

				if (empty($htmlforphoto) || $htmlforphoto == '<!-- Photo -->' || $htmlforphoto == '<!-- Photo -->'."\n") {
					print '<!--no photo defined -->';
					print '<table width="100%" valign="top" align="center" border="0" cellpadding="2" cellspacing="2"><tr><td width="100%" class="photo">';
					print '<img class="photo photowithmargin" border="0" height="'.$maxHeight.'" src="'.DOL_URL_ROOT.'/public/theme/common/nophoto.png" title="'.dol_escape_htmltag($alt).'">';
					print '</td></tr></table>';
				} else {
					print $htmlforphoto;
				}

				//var_dump($tmpproduct->array_options);
				/*if ($tmpproduct->array_options['options_app_or_option'] == 'app')
				 {
				 print '<span class="opacitymedium small">'.'&nbsp;'.'</span><br>';
				 }
				 if ($tmpproduct->array_options['options_app_or_option'] == 'system')
				 {
				 print '<span class="opacitymedium small">'.'&nbsp;'.'</span><br>';
				 }
				 if ($tmpproduct->array_options['options_app_or_option'] == 'option')
				 {
				 print '<span class="opacitymedium small">'.$langs->trans("Option").'</span><br>';
				 }*/


				// Label
				$labelprod = $tmpproduct->label;
				if (preg_match('/instance/i', $tmpproduct->ref) || preg_match('/instance/i', $tmpproduct->label)) {
					$labelprod = $langs->trans("Application");
				} elseif ($tmpproduct->array_options['options_resource_label'] == 'User' && preg_match('/User/i', $tmpproduct->label)) {
					$labelprod = $langs->trans("Users");
				}

				print '<span class="opacitymedium small labelprod">'.$labelprod.'</span><br>';

				// Qty
				$resourceformula = $tmpproduct->array_options['options_resource_formula'];
				if (preg_match('/SQL:/', $resourceformula)) {
					$resourceformula = preg_match('/__d__/', $dbprefix, $resourceformula);
				}
				if (preg_match('/DISK:/', $resourceformula)) {
					$resourceformula = $resourceformula;
				}

				print '<span class="font-green-sharp counternumber">'.$line->qty.'</span>';
				print '<br>';

				$tmpduration = '';
				if ($tmpproduct->duration) {
					if ($tmpproduct->duration == '1m') {
						$tmpduration.=' / '.$langs->trans("Month");
					} elseif ($tmpproduct->duration == '1y') {
						$tmpduration.=' / '.$langs->trans("DurationYear");
					} else {
						preg_match('/^([0-9]+)([a-z]{1})$/', $tmpproduct->duration, $regs);
						if (! empty($regs[1]) && ! empty($regs[2])) {
							$tmpduration.=' / '.$regs[1].' '.($regs[2] == 'm' ? $langs->trans("Month") : ($regs[2] == 'y' ? $langs->trans("DurationYear") : ''));
						}
					}
				}

				if ($line->price_ht) {
					print '<span class="opacitymedium small">'.price($line->price_ht, 1, $langs, 0, -1, -1, $conf->currency);
					//if ($line->qty > 1 && $labelprodsing) print ' / '.$labelprodsing;
					if ($tmpproduct->array_options['options_resource_label']) {
						print ' / '.$langs->trans($tmpproduct->array_options['options_resource_label']);	// Label of units
					} elseif (preg_match('/users/i', $tmpproduct->ref)) {
						print ' / '.$langs->trans("User");	// backward compatibility
					}
					// TODO
					print $tmpduration;
					print '</span>';
				} else {
					if (!getDolGlobalString('SELLYOURSAAS_HIDE_PRODUCT_PRICE_IF_NULL')) {
						print '<span class="opacitymedium small">'.price($line->price_ht, 1, $langs, 0, -1, -1, $conf->currency);
						// TODO
						print $tmpduration;
						print '</span>';
					} else {
						// TODO
						if (getDolGlobalString('SELLYOURSAAS_TRANSKEY_WHEN_PRODUCT_PRICE_IF_NULL')) {
							print '<span class="opacitymedium small">';
							print $langs->trans(getDolGlobalString('SELLYOURSAAS_TRANSKEY_WHEN_PRODUCT_PRICE_IF_NULL'));
							print '</span>';
						}
					}
				}
			} else { // If there is no product, this is a free product
				print '<!--no photo defined -->';
				print '<table width="100%" valign="top" align="center" border="0" cellpadding="2" cellspacing="2"><tr><td width="100%" class="photo">';
				print '<img class="photo photowithmargin" border="0" height="'.$maxHeight.'" src="'.DOL_URL_ROOT.'/public/theme/common/nophoto.png" title="'.dol_escape_htmltag($alt).'">';
				print '</td></tr></table>';

				// Label
				$labelprod = $line->description;
				/*if (preg_match('/instance/i', $tmpproduct->ref) || preg_match('/instance/i', $tmpproduct->label))
				 {
				 $labelprod = $langs->trans("Application");
				 }
				 elseif (preg_match('/user/i', $tmpproduct->ref) || preg_match('/user/i', $tmpproduct->label))
				 {
				 $labelprod = $langs->trans("Users");
				 }*/

				print '<span class="opacitymedium small">'.$labelprod.'</span><br>';

				print '<span class="font-green-sharp counternumber">'.$line->qty.'</span>';
				print '<br>';

				if ($line->price_ht) {
					$priceforline = $line->price_ht * $line->qty;
					print '<span class="opacitymedium small">'.price($priceforline, 1, $langs, 0, -1, -1, $conf->currency);
					//if (preg_match('/users/i', $line->description)) print ' / '.$langs->trans("User");
					print ' / '.$langs->trans("Month");
					print '</span>';
				} else {
					print '<span class="opacitymedium small">'.price($line->price_ht, 1, $langs, 0, -1, -1, $conf->currency);
					// TODO
					print ' / '.$langs->trans("Month");
					print '</span>';
				}
			}

			print '</div>';
		}

		// Add new option box
		if ($statuslabel != 'processing' && $statuslabel != 'undeployed') {
			print '<a id="switchoptionpanel_'.$id.'" class="switchoptionpanel" href="#">';
			print '<div class="resource inline-block boxresource small">';
			print '<br><br><br>';
			print '<span class="fa fa-plus-circle valignmiddle" style="font-size: 1.5em; padding-bottom: 4px;"></span><br>';
			print $langs->trans("AddMoreOptions").'...';
			print '</div>';
			print '</a>';


			// Add here the Option panel

			print '<div id="optionpanel_'.$id.'" class="optionpanel '.(GETPOST("keylineoption", "int") != "" && GETPOST("keylineoption", "int") == $keyline ? '' : 'hidden').'">';
			print '<br>';
			print '<div class="areaforresources sectionresources">';
			print '<br>';

			// Hard coded option: Custom domain name
			if (getDolGlobalString("SELLYOURSAAS_ENABLE_CUSTOMURL") && getDolGlobalInt("SELLYOURSAAS_PRODUCT_ID_FOR_CUSTOM_URL") > 0
				&& (!getDolGlobalString("SELLYOURSAAS_ENABLE_CUSTOMURL_FOR_THIRDPARTYID") || in_array($mythirdpartyaccount->id, explode(',', getDolGlobalString('SELLYOURSAAS_ENABLE_CUSTOMURL_FOR_THIRDPARTYID'))))) {
				print '<div class="tagtable centpercent divcustomdomain"><div class="tagtr">';

				print '<form method="POST" id="formwebsiteoption" action="'.$_SERVER["PHP_SELF"].'">'."\n";
				print '<input type="hidden" name="token" value="'.newToken().'">';
				print '<input type="hidden" name="action" value="deploycustomurl">';
				print '<input type="hidden" name="contractid" value="'.$contract->id.'">';
				print '<input type="hidden" name="mode" value="'.$mode.'">';
				print '<input type="hidden" name="keylineoption" value="'.$keyline.'">';
				print '<input type="hidden" name="page_y" value="">';

				print '<div class="tagtd valignmiddle paddingleft paddingright">';
				print '<div class="titleoption">'; // title line
				print '<div class="inline-block">';
				print '<img class="photo photowithmargin" border="0" height="'.$maxHeight.'" src="'.DOL_URL_ROOT.'/theme/common/octicons/build/svg/milestone.svg" title="'.dol_escape_htmltag($alt).'">';
				print '</div>';
				print '<div class="inline-block paddingleft marginleftonly paddingright marginrightonly bold">'.$langs->trans("OptionYourCustomDomainName").'</div>';
				print '</div>';	// end title line

				print '<span class="small">';
				print $langs->trans("OptionYourCustomDomainNameDesc", $contract->ref_customer).'</span><br>';
				print '<span class="opacitymedium small hideonsmartphone">'.$langs->trans("OptionYourCustomDomainNamePrerequisites").'<br></span>';

				print '<div class="installcertif margintop hidden" id="customurlparam_'.$id.'">';
				print '<br>';
				print $langs->trans("Step", 1).' : '.$langs->trans("OptionYourCustomDomainNameStep2", $contract->ref_customer).'<br>';
				print '<br>';
				print $langs->trans("Step", 2).' : '.$langs->trans("OptionYourCustomDomainNameStep1", $langs->transnoentitiesnoconv("Enable")).'<br>';
				print '<input type="text" name="domainname" value="" placeholder="myerp.mycompany.com">';
				print '<input type="submit" class="btn btn-primary wordbreak reposition" id="activateoptioncustomurl" name="activateoption" value="'.$langs->trans("Enable").'">';
				print '<br>';
				print '</div>';
				print '</div>';

				print '<div class="tagtd center minwidth100 width100">';
				$tmpproduct = new Product($db);
				$tmpproduct->fetch(getDolGlobalInt("SELLYOURSAAS_PRODUCT_ID_FOR_CUSTOM_URL"));
				$priceoption = $tmpproduct->price;
				$nbmonth = 1;
				// TODO Use same frequency than into the template invoice ?
				print '<span class="font-green-sharp">'.($priceoption * $nbmonth).' '.$conf->currency.' / '.$langs->trans("month").'</span><br>';
				//print '<span class="opacitymedium warning" style="color:orange">'.$langs->trans("NotYetAvailable").'</span><br>';
				print '<input type="button" class="btn btn-primary wordbreak chooseoptioncustomurl" id="chooseoptioncustomurl_'.$id.'" name="chooseoption" value="'.$langs->trans("Install").'">';
				print '</div>';

				print '</form>';

				print '</div></div>';	// end tr, end table

				print '<hr>';
			}

			// Hard coded option: A website
			if (getDolGlobalString('SELLYOURSAAS_ENABLE_DOLIBARR_WEBSITES') && getDolGlobalInt("SELLYOURSAAS_PRODUCT_ID_FOR_WEBSITE_DEPLOYMENT") > 0
				&& (!getDolGlobalString("SELLYOURSAAS_ENABLE_DOLIBARR_WEBSITES_FOR_THIRDPARTYID") || in_array($mythirdpartyaccount->id, explode(',', getDolGlobalString('SELLYOURSAAS_ENABLE_DOLIBARR_WEBSITES_FOR_THIRDPARTYID'))))) {
				print '<!-- Hard coded option for a Dolibarr website -->'."\n";
				print '<div class="tagtable centpercent divdolibarrwebsites"><div class="tagtr">';

				print '<div class="tagtd paddingleft paddingright marginrightonly valignmiddle">';

				print '<div class="titleoption">'; // title line
				print '<div class="inline-block">';
				print '<img class="photo photowithmargin" border="0" height="'.$maxHeight.'" src="'.DOL_URL_ROOT.'/theme/common/octicons/build/svg/globe.svg" title="'.dol_escape_htmltag($alt).'">';
				print '</div>';
				print '<div class="inline-block paddingleft marginleftonly paddingright marginrightonly bold">'.$langs->trans("OptionYourWebsite").'</div>';
				print '</div>';	// end title line

				if (empty($websitemodenabled)) {
					print $langs->trans("OptionYourWebsiteNoEnabled").'<br>';
				} else {
					include_once DOL_DOCUMENT_ROOT."/website/class/website.class.php";
					$websitestatic = new Website($newdb);
					$listofwebsitestoactivate = $websitestatic->fetchAll('', '', 0, 0, '(t.status:=:'.$websitestatic::STATUS_VALIDATED.')');
					//$listofwebsitestoactivate = $websitestatic->fetchAll('', '', 0, 0);

					print '<span class="small">';
					print $langs->trans("OptionYourWebsiteDesc").'<br>';
					print '</span>';
					print '<span class="opacitymedium small hideonsmartphone">'.$langs->trans("OptionYourWebsitePrerequisites").'<br></span><br>';
					print '<div '.(GETPOST("websiteidoption", "int") == "" ? 'class="hidden"' : '').' id="installwebsite_'.$id.'">';
					print $langs->trans("Step", 1).' : '. $langs->trans("OptionYourWebsiteStep1", $langs->transnoentitiesnoconv("Continue")).'<br>';
					print '<form method="POST" id="formwebsiteoption" action="'.$_SERVER["PHP_SELF"].'">'."\n";
					print '<input type="hidden" name="token" value="'.newToken().'">';
					print '<input type="hidden" name="action" value="deploywebsite">';
					print '<input type="hidden" name="contractid" value="'.$contract->id.'">';
					print '<input type="hidden" name="mode" value="'.$mode.'">';
					print '<input type="hidden" name="keylineoption" value="'.$keyline.'">';
					print '<input type="hidden" name="page_y" value="">';

					print '<span class="bold">'.$langs->trans("OptionWebsite").'&nbsp;</span>';
					print '<select style="width:60%" id="websiteidoption" name="websiteidoption">';
					print '<option value="">&nbsp;</option>';
					$contractlines = $contract->lines;
					// Fill the array of websites that are already activated
					$arraywebsitesenabled = array();
					foreach ($contractlines as $line) {
						if ($line->fk_product == getDolGlobalInt("SELLYOURSAAS_PRODUCT_ID_FOR_WEBSITE_DEPLOYMENT")) {
							$desc = $line->description;
							$tmpdesc = explode(", ", $desc);
							$websiteref = explode("WebsiteRef=", $tmpdesc[0])[1];
							$websitecustomurl = explode("WebsiteDomainName=", $tmpdesc[1])[1];
							$arraywebsitesenabled[$websiteref] = $websitecustomurl;
						}
					}

					if (count($listofwebsitestoactivate)) {
						foreach ($listofwebsitestoactivate as $website) {
							$isalreadyactivated = 0;
							if (isset($arraywebsitesenabled[$website->ref])) {
								$isalreadyactivated = 1;
							}
							print '<option value="'.$website->id.'" '.(GETPOST("websiteidoption", "int") == $website->id ? "selected" : "");
							if ($isalreadyactivated) {
								print " disabled";
							}
							if ($website->status != $websitestatic::STATUS_VALIDATED) {
								print " disabled";
							}
							print '>'.$website->ref;
							if ($isalreadyactivated) {
								print ' - '.$arraywebsitesenabled[$website->ref];
							}
							if ($website->status != $websitestatic::STATUS_VALIDATED) {
								print ' - '.$langs->trans("Disabled");
							}
							print '</option>';
						}
					} else {
						print '<option value="" disabled>';
						print $langs->trans("NoWebsiteToMakeOnline");
						print '</option>';
					}
					print '</select>';
					print ajax_combobox("websiteidoption");
					print '<input type="button" class="btn btn-primary wordbreak" id="choosewebsiteidoption" name="chooseoption" value="'.$langs->trans("Continue").'">';
					print '</div>';

					print '<div id="domainnamewebsite" '.(GETPOST("websiteidoption", "int") == "" ? 'class="hidden"' : '').'"><br>';
					print $langs->trans("Step", 2).' : '.$langs->trans("PurshaseDomainName").'&nbsp;';
					print '<br><span class="bold">'.$langs->trans("Domain").'&nbsp;</span>';
					print '<input name="domainnamewebsite" id="domainnamewebsiteinput" value="'.GETPOST("domainnamewebsite", "alpha").'">&nbsp;';
					print '<input type="button" class="btn btn-primary wordbreak" id="choosedomainnamewebsite" name="chooseoption" value="'.$langs->trans("Continue").'">';
					print '</div>';

					print '<div id="choosewebsiteoption" '.(GETPOST("websiteidoption", "int") == "" ? 'class="hidden"' : '').'><br>';
					print $langs->trans("Step", 3).' : '.$langs->trans("AddInstructionToDns", $contract->ref_customer, $contract->ref_customer);
					print '<br><input class="btn green-haze btn-circle margintop marginbottom marginleft marginright reposition" type="submit" name="startwebsitedeploy" value="'.$langs->trans("StartWebsiteDeployment").'">';
					print '</div>';
					print '</form>';
					print '<script>
					</script>';
				}
				print '</div>';

				print '<div class="tagtd right minwidth100 width100">';
				// TODO Use same frequency than into the template invoice ?
				$nbmonth = 1;
				if (!empty($websitemodenabled)) {
					print '<span class="font-green-sharp">'.price(6 * $nbmonth, 0, $langs, 1, -1, -1, $conf->currency).' / '.$langs->trans("DurationMonth").'</span><br>';
					//print '<span class="opacitymedium warning" style="color:orange">'.$langs->trans("NotYetAvailable").'</span><br>';
					print '<input type="button" class="btn btn-primary wordbreak chooseoptionwebsite" id="chooseoptionwebsite_'.$id.'" name="chooseoption" value="'.$langs->trans("ToSetup").'">';
				}
				print '</div>';
				print '</div></div>';	// end tr, end table

				print '<hr>';
			}


			// TODO Add option from options services into databases

			foreach ($arrayofoptionsfull as $key => $val) {
				$tmpproduct = $val['product'];

				$conditionok = 0;
				if (isset($tmpproduct->array_options['options_option_condition']) && $tmpproduct->array_options['options_option_condition'] != '') {
					$conditionok = 1;
					// There is a condition to show the option, we check it
				}

				if (!$conditionok) {
					continue;
				}

				print '<div class="tagtable centpercent divdolibarroptionfromservices"><div class="tagtr">';
				print '<div class="tagtd paddingleft paddingright marginrightonly valignmiddle">';

				$htmlforphoto = $tmpproduct->show_photos('product', $conf->product->dir_output, 1, 1, -1, 0, 0, $maxHeight, $maxWidth, 1, 1, 1);

				if (empty($htmlforphoto) || $htmlforphoto == '<!-- Photo -->' || $htmlforphoto == '<!-- Photo -->'."\n") {
					print '<!--no photo defined -->';
					print '<table class="valignmiddle center" border="0" cellpadding="2" cellspacing="2"><tr><td class="centpercent photo">';
					print '<img class="photo photowithmargin" border="0" height="'.$maxHeight.'" src="'.DOL_URL_ROOT.'/public/theme/common/nophoto.png" title="'.dol_escape_htmltag($alt).'">';
					print '</td></tr></table>';
				} else {
					print $htmlforphoto;
				}

				$label = $tmpprod->label;
				$desc = $tmpprod->description;
				$producturl = $tmpproduct->url;
				if (!empty($tmpproduct->multilangs[$langs->defaultlang])) {
					$label = $tmpproduct->multilangs[$langs->defaultlang]['label'];
					$description = $tmpproduct->multilangs[$langs->defaultlang]['description'];
				} elseif (!empty($tmpproduct->multilangs['en_US'])) {
					$label = $tmpproduct->multilangs['en_US']['label'];
					$description = $tmpproduct->multilangs['en_US']['description'];
				}

				print '<div class="inline-block paddingleft marginleftonly bold">';
				print $label;
				print '</div>';
				print '<br>';

				print '<div class="valignmiddle">';
				if ($description) {
					print '<span class="small">';
					print $description.'<br>';
					print '</span>';
				}
				if ($producturl) {
					print '<a href="'.$producturl.'" target="_blank" rel="noopener">'.$langs->trans("MoreInformation").'...</a><br>';
				}
				// TODO Scan if module is enabled, if no, show a message to do it. If yes, show list of available websites
				print '</div>';
				print '</div>';

				print '<div class="tagtd right valignmiddle minwidth100 paddingleft paddingright">';
				if ($arrayofoptionsfull[$key]['labelprice']) {
					print $arrayofoptionsfull[$key]['labelprice'].'<br>';
				}
				// Button to subscribe
				if (!empty($tmpproduct->array_options['options_package'])) {
					// If there is a package, sho wlink to subscribe
				} else {
					// If no package
					if ($producturl) {
						print '<a class="btn btn-primary wordbreak" href="'.$producturl.'" target="_blank" rel="noopener">'.$langs->trans("IWantToTest").'...</a><br>';
					}
					//print '<span class="opacitymedium">'.$langs->trans("NotYetAvailable").'</span>';
				}
				print '</div>';
				print '</div></div>';

				print '<hr>';
			}

			print '<div class="tagtable centpercent divdolibarrmoreoptions"><div class="tagtr">';
			print '<div class="tagtd width50 paddingleft paddingright marginrightonly valignmiddle">';
			print '<br>';
			print '<span class="opacitymedium">'.$langs->trans("SoonMoreOptionsHere").'...</span><br>';
			print '<br>';
			print '</div></div></div>';

			print '</div>';
			print '</div>';
		}

		print '<br><br>';


		// Show the current Plan (with link to change it)
		print '<span class="caption-helper"><span class="opacitymedium">'.$langs->trans("YourSubscriptionPlan").' : </span>';
		if (1 == 2 && $initialaction == 'changeplan' && $planid > 0 && $id == GETPOST('id', 'int')) {
			print '<input type="hidden" name="mode" value="instances"/>';
			print '<input type="hidden" name="action" value="updateplan" />';
			print '<input type="hidden" name="contractid" value="'.$contract->id.'" />';

			// SERVER_NAME here is myaccount.mydomain.com (we can exploit only the part mydomain.com)
			$domainname = getDomainFromURL($_SERVER["SERVER_NAME"], 1);

			// List of available plans/products
			$arrayofplanstoswitch=array();
			$sqlproducts = 'SELECT p.rowid, p.ref, p.label FROM '.MAIN_DB_PREFIX.'product as p, '.MAIN_DB_PREFIX.'product_extrafields as pe';
			$sqlproducts.= ' LEFT JOIN '.MAIN_DB_PREFIX.'packages as pa ON pe.package = pa.rowid';
			$sqlproducts.= ' WHERE p.tosell = 1 AND p.entity = '.$conf->entity;
			$sqlproducts.= " AND pe.fk_object = p.rowid AND pe.app_or_option = 'app'";
			$sqlproducts.= " AND pe.availabelforresellers > 0";		// available in dashboard (customers + resellers)
			$sqlproducts.= " AND p.ref NOT LIKE '%DolibarrV1%'";
			$sqlproducts.= " AND (pa.restrict_domains IS NULL"; // restict_domains can be empty (it's ok)
			$sqlproducts.= " OR pa.restrict_domains = '".$db->escape($domainname)."'"; // can be mydomain.com
			$sqlproducts.= " OR pa.restrict_domains LIKE '%.".$db->escape($domainname)."'"; // can be with.mydomain.com or the last domain of [mydomain1.com,with.mydomain2.com]
			$sqlproducts.= " OR pa.restrict_domains LIKE '%.".$db->escape($domainname).",%'"; // can be the first or the middle domain of [with.mydomain1.com,with.mydomain2.com,mydomain3.com]
			$sqlproducts.= " OR pa.restrict_domains LIKE '".$db->escape($domainname).",%'"; // can be the first domain of [mydomain1.com,mydomain2.com]
			$sqlproducts.= " OR pa.restrict_domains LIKE '%,".$db->escape($domainname).",%'"; // can be the middle domain of [mydomain1.com,mydomain2.com,mydomain3.com]
			$sqlproducts.= " OR pa.restrict_domains LIKE '%,".$db->escape($domainname)."'"; // can be the last domain of [mydomain1.com,mydomain2.com]
			$sqlproducts.= ")";
			$sqlproducts.= " AND (p.rowid = ".((int) $planid)." OR 1 = 1)";		// TODO Restrict on plans compatible with current plan...
			$sqlproducts.= " ORDER BY pe.position ASC";
			$resqlproducts = $db->query($sqlproducts);
			if ($resqlproducts) {
				$num = $db->num_rows($resqlproducts);
				$i=0;
				while ($i < $num) {
					$obj = $db->fetch_object($resqlproducts);
					if ($obj) {
						$arrayofplanstoswitch[$obj->rowid]=$obj->label;
					}
					$i++;
				}
			}
			print $form->selectarray('planid', $arrayofplanstoswitch, $planid, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
			print '<input type="submit" class="btn btn-warning default change-plan-link" name="changeplan" value="'.$langs->trans("ChangePlan").'">';
		} else {
			print '<span class="bold">'.$planlabel.'</span>';
			if ($statuslabel != 'undeployed') {
				if ($foundtemplate == 0 || ($priceinvoicedht == $contract->total_ht && !$atleastonediscount)) {
					print ' - <a href="'.$_SERVER["PHP_SELF"].'?mode=instances&action=changeplan&id='.$contract->id.'#contractid'.$contract->id.'">'.$langs->trans("ChangePlan").'</a>';
				}
			}
		}
		print '</span>';
		print '<br>';

		// Billing
		if ($statuslabel != 'undeployed') {
			$freemodeinstance = ((empty($mythirdpartyaccount->array_options['options_checkboxnonprofitorga']) || $mythirdpartyaccount->array_options['options_checkboxnonprofitorga'] == 'nonprofit') && getDolGlobalInt("SELLYOURSAAS_ENABLE_FREE_PAYMENT_MODE"));

			print '<!-- Billing information of contract -->'."\n";
			print '<span class="caption-helper spanbilling"><span class="opacitymedium">'.($freemodeinstance ? ($foundtemplate == 0 ? $langs->trans("Confirmation") : $langs->trans("Billing")) : $langs->trans("Billing")).' : </span>';
			if ($foundtemplate > 1) {
				$sellyoursaasemail = getDolGlobalString('SELLYOURSAAS_MAIN_EMAIL');
				if (! empty($mythirdpartyaccount->array_options['options_domain_registration_page'])
					&& $mythirdpartyaccount->array_options['options_domain_registration_page'] != $conf->global->SELLYOURSAAS_MAIN_DOMAIN_NAME) {
					$newnamekey = 'SELLYOURSAAS_MAIN_EMAIL_FORDOMAIN-'.$mythirdpartyaccount->array_options['options_domain_registration_page'];
					if (getDolGlobalString($newnamekey)) {
						$sellyoursaasemail = getDolGlobalString($newnamekey);
					}
				}

				print '<span style="color:orange">'.$langs->trans("WarningFoundMoreThanOneInvoicingTemplate", $sellyoursaasemail).'</span>';
			} else {
				// Invoice amount line
				if ($foundtemplate != 0 && ($priceinvoicedht != $contract->total_ht || $atleastonediscount)) {
					if ($pricetoshow != '') {
						print $langs->trans("FlatOrDiscountedPrice").' = ';
					}
				}
				print '<span class="bold">'.($freemodeinstance ? $langs->trans("FreePrice") : $pricetoshow).'</span>';

				// Discount and next invoice line
				if ($foundtemplate == 0) {	// foundtemplate means there is at least one template invoice (so contract is a paying or validated contract)
					if ($contract->array_options['options_date_endfreeperiod'] < $now) {
						$color='orange';
					}

					print ' <span style="color:'.$color.'">';
					if ($contract->array_options['options_date_endfreeperiod'] > 0) {
						print $langs->trans("TrialUntil", dol_print_date($contract->array_options['options_date_endfreeperiod'], 'day'));
					} else {
						print $langs->trans("Trial");
					}
					print '</span>';
					if ($contract->array_options['options_date_endfreeperiod'] < $now) {
						if ($statuslabel == 'suspended') {
							print ' - <span style="color: orange">'.$langs->trans("Suspended").'</span>';
						}
						//else print ' - <span style="color: orange">'.$langs->trans("SuspendWillBeDoneSoon").'</span>';
					}
					if ($freemodeinstance) {
						print ' - <a href="'.$_SERVER["PHP_SELF"].'?mode=instances&action=validatefreemode&contractid='.$contract->id.'#contractid'.$contract->id.'">'.$langs->trans("ConfirmInstanceValidation").'</a>';
					} elseif ($statuslabel == 'suspended') {
						if (empty($atleastonepaymentmode)) {
							if ($contract->total_ht > 0) {
								print ' - <a href="'.$_SERVER["PHP_SELF"].'?mode=registerpaymentmode&backtourl='.urlencode($_SERVER["PHP_SELF"].'?mode='.$mode).'">'.$langs->trans("AddAPaymentModeToRestoreInstance").'</a>';
							}
						} else {
							if ($contract->total_ht > 0) {
								print ' - <a href="'.$_SERVER["PHP_SELF"].'?mode=registerpaymentmode&backtourl='.urlencode($_SERVER["PHP_SELF"].'?mode='.$mode).'">'.$langs->trans("FixPaymentModeToRestoreInstance").'</a>';
							}
						}
					} else {
						if (empty($atleastonepaymentmode)) {
							if ($contract->total_ht > 0) {
								print ' - <a href="'.$_SERVER["PHP_SELF"].'?mode=registerpaymentmode&backtourl='.urlencode($_SERVER["PHP_SELF"].'?mode='.$mode).'">'.$langs->trans("AddAPaymentMode").'</a>';
							}
						} else {
							// If at least one payment mode already recorded
							if (sellyoursaasIsPaymentKo($contract)) {
								print ' - '.$langs->trans("ActivePaymentError");
							} else {
								print ' - '.$langs->trans("APaymentModeWasRecorded");

								// Discount code entered
								if ($contract->array_options['options_discountcode']) {
									print '<br><span class="opacitymedium">'.$langs->trans("DiscountCode").'</span> : <span class="bold">';
									print $contract->array_options['options_discountcode'];
									print '</span>';
								}
							}
						}
					}
				} elseif ($datenextinvoice) {
					// Discount code entered
					if ($templateinvoice->array_options['options_discountcode']) {
						print '<br><span class="opacitymedium">'.$langs->trans("DiscountCode").'</span> : <span class="bold">';
						print $templateinvoice->array_options['options_discountcode'];
						print '</span>';
					}
					// Date of next invoice
					print '<br><span class="opacitymedium">'.$langs->trans("NextInvoice").'</span> : <span class="bold">'.dol_print_date($datenextinvoice, 'day').'</span>';
				}
			}
			print '</span>';
		}

		print '
								  </div>
				              </div>

							<!-- tab domain -->
				            <div class="tab-pane" id="tab_domain_'.$contract->id.'">
								<p class="opacitymedium" style="padding: 15px">'.$langs->trans("URLDesc").'...</p>
								<form class="form-group" action="'.$_SERVER["PHP_SELF"].'" method="POST" role="form">
                                    <input type="hidden" name="token" value="'.newToken().'">
									<input type="hidden" name="mode" value="instances"/>
									<input type="hidden" name="action" value="updateurl" />
									<input type="hidden" name="contractid" value="'.$contract->id.'" />
									<input type="hidden" name="tab" value="domain_'.$contract->id.'" />

									<div class="form-body">
    				                  <div class="form-group col-md-12 row">
					                <label class="col-md-5 control-label">'.$langs->trans("TheURLDomainOfYourInstance").'</label>
									<div class="col-md-5">
										<div class="widthcentpercentminusx inline-block nowraponall">
										<input type="text" class="urlofinstance minwidth400" disabled="disabled" value="https://'.$contract->ref_customer.'">
										'.showValueWithClipboardCPButton('https://'.$contract->ref_customer, 0, 'none').'
										</div>
									</div>
								';

		if (! empty($contract->array_options['options_custom_url'])) {
			$arrayofcustom = explode(' ', $contract->array_options['options_custom_url']);
			foreach ($arrayofcustom as $customurl) {	// Loop on each custom url
				print '
										<label class="col-md-5 control-label">'.$langs->trans("YourCustomUrl").'</label>
										<div class="col-md-5">
											<div class="widthcentpercentminusx inline-block nowraponall">
											<input type="text" class="urlofinstancecustom minwidth400" disabled="disabled" value="https://'.$customurl.'">
										'.showValueWithClipboardCPButton('https://'.$customurl, 0, 'none').'
											</div>
										</div>
									';
			}
		}

		//print '<input type="submit" class="btn btn-warning default change-domain-link" name="changedomain" value="'.$langs->trans("ChangeDomain").'">';
		print '
									</div></div>

							  	</form>
				            </div>

							<!-- tab ssh/sftp -->
				            <div class="tab-pane" id="tab_ssh_'.$contract->id.'">
				                <p class="opacitymedium" style="padding: 15px">'.$langs->trans("SSHFTPDesc");
		if ($directaccess == 1 || ($directaccess == 2 && empty($foundtemplate)) || ($directaccess == 3 && !empty($foundtemplate))) {
			// Show message "To connect, you will need the following information:"
			print '<br>'.$langs->trans("SSHFTPDesc2").' :';
		}
		print '</p>';

		if ($directaccess == 1 || ($directaccess == 2 && empty($foundtemplate)) || ($directaccess == 3 && !empty($foundtemplate))) {
			$ssh_server_port = (!empty($contract->array_options['options_port_os']) ? $contract->array_options['options_port_os'] : getDolGlobalInt('SELLYOURSAAS_SSH_SERVER_PORT', 22));
			print '
    				                <form class="form-horizontal" role="form">
                                    <input type="hidden" name="token" value="'.newToken().'">

    				                <div class="form-body">
    				                  <div class="form-group col-md-12 row">
    				                    <label class="col-md-2 control-label">'.$langs->trans("Hostname").'</label>
    				                    <div class="col-md-4">
											<div class="widthcentpercentminusx inline-block nowraponall">
    				                      <input type="text" id="hostname_os_'.$contract->id.'" disabled="disabled" class="form-control input-medium" value="'.$contract->array_options['options_hostname_os'].'">
										'.showValueWithClipboardCPButton($contract->array_options['options_hostname_os'], 0, 'none').'
											</div>
    				                    </div>
    				                    <label class="col-md-2 control-label">'.$langs->trans("Port").'</label>
    				                    <div class="col-md-4 widthcentpercentminusxx inline-block nowraponall">
											<div class="widthcentpercentminusx inline-block nowraponall">
    				                      <input type="text" id="port_ssh_'.$contract->id.'" disabled="disabled" class="form-control input-medium" value="'.$ssh_server_port.'">
										'.showValueWithClipboardCPButton($ssh_server_port, 0, 'none').'
											</div>
    				                    </div>
    				                  </div>
    				                  <div class="form-group col-md-12 row">
    				                    <label class="col-md-2 control-label">'.$langs->trans("SFTP Username").'</label>
    				                    <div class="col-md-4">
											<div class="widthcentpercentminusx inline-block nowraponall">
    				                      <input type="text" id="username_os_'.$contract->id.'" disabled="disabled" class="form-control input-medium" value="'.$contract->array_options['options_username_os'].'">
										'.showValueWithClipboardCPButton($contract->array_options['options_username_os'], 0, 'none').'
											</div>
    				                    </div>
    				                    <label class="col-md-2 control-label">'.$langs->trans("Password").'</label>
    				                    <div class="col-md-4">
											<div class="widthcentpercentminusx inline-block nowraponall">
	   				                      <input type="text" id="password_os_'.$contract->id.'" disabled="disabled" class="form-control input-medium" value="'.$contract->array_options['options_password_os'].'">
										'.showValueWithClipboardCPButton($contract->array_options['options_password_os'], 0, 'none').'
											</div>
    				                    </div>
    				                  </div>
    				                </div>

    				                </form>
                                    ';
			print'					<br><div class="note note-warning">
									'.$langs->trans("CommunicateIdentifiersToOnlyTrusted").'
									</div>
									';
		} elseif ($directaccess == 4) {
			print '<!-- directaccess = '.$directaccess.' foundtemplate = '.$foundtemplate.' -->';
			print '<p class="opacitymedium" style="padding: 15px">'.$langs->trans("PleaseOpenATicketToRequestYourCredential").'</p>';
		} else {
			print '<!-- directaccess = '.$directaccess.' foundtemplate = '.$foundtemplate.' -->';
			if ($directaccess == 3 && empty($foundtemplate)) {
				print '<p class="opacitymedium" style="padding: 15px">'.img_warning('default', '', 'pictowarning pictofixedwidth').$langs->trans("SorryFeatureNotAvailableDuringTestPeriod", $langs->transnoentitiesnoconv("MyBilling")).'...</p>';
			} else {
				print '<p class="opacitymedium" style="padding: 15px">'.$langs->trans("SorryFeatureNotAvailableInYourPlan").'</p>';
			}
		}

		print '
				              </div> <!-- END TAB SSH PANE -->

							  <!-- tab db -->
				              <div class="tab-pane" id="tab_db_'.$contract->id.'">
				                <p class="opacitymedium" style="padding: 15px">'.$langs->trans("DBDesc");
		if ($directaccess == 1 || ($directaccess == 2 && empty($foundtemplate)) || ($directaccess == 3 && !empty($foundtemplate))) {
			// Show message "To connect, you will need the following information:"
			print '<br>'.$langs->trans("DBDesc2").' :';
		}
		print '</p>
                                ';

		if ($directaccess == 1 || ($directaccess == 2 && empty($foundtemplate)) || ($directaccess == 3 && ! empty($foundtemplate))) {
			print '
    				                <form class="form-horizontal" role="form">
                                    <input type="hidden" name="token" value="'.newToken().'">

    				                <div class="form-body">
    				                  <div class="form-group col-md-12 row">
    				                    <label class="col-md-3 control-label">'.$langs->trans("Hostname").'</label>
    				                    <div class="col-md-3">
											<div class="widthcentpercentminusx inline-block nowraponall">
    				                      <input type="text" id="hostname_db_'.$contract->id.'" disabled="disabled" class="form-control input-medium" value="'.$contract->array_options['options_hostname_db'].'">
										'.showValueWithClipboardCPButton($contract->array_options['options_hostname_db'], 0, 'none').'
											</div>
    				                    </div>
    				                    <label class="col-md-3 control-label">'.$langs->trans("Port").'</label>
    				                    <div class="col-md-3">
											<div class="widthcentpercentminusx inline-block nowraponall">
    				                      <input type="text" id="port_db_'.$contract->id.'" disabled="disabled" class="form-control input-medium" value="'.$contract->array_options['options_port_db'].'">
										'.showValueWithClipboardCPButton($contract->array_options['options_port_db'], 0, 'none').'
											</div>
    				                    </div>
    				                  </div>
    				                  <div class="form-group col-md-12 row">
    				                    <label class="col-md-3 control-label">'.$langs->trans("DatabaseName").'</label>
    				                    <div class="col-md-3">
											<div class="widthcentpercentminusx inline-block nowraponall">
    				                      <input type="text" id="name_db_'.$contract->id.'" disabled="disabled" class="form-control input-medium" value="'.$contract->array_options['options_database_db'].'">
										'.showValueWithClipboardCPButton($contract->array_options['options_database_db'], 0, 'none').'
											</div>
    				                    </div>
    				                  </div>
    				                  <div class="form-group col-md-12 row">
    				                    <label class="col-md-3 control-label">'.$langs->trans("DatabaseLogin").'</label>
    				                    <div class="col-md-3">
											<div class="widthcentpercentminusx inline-block nowraponall">
    				                      <input type="text" id="username_db_'.$contract->id.'" disabled="disabled" class="form-control input-medium" value="'.$contract->array_options['options_username_db'].'">
										'.showValueWithClipboardCPButton($contract->array_options['options_username_db'], 0, 'none').'
											</div>
    				                    </div>
    				                    <label class="col-md-3 control-label">'.$langs->trans("Password").'</label>
    				                    <div class="col-md-3">
											<div class="widthcentpercentminusx inline-block nowraponall">
    				                      <input type="text" id="other_db_'.$contract->id.'" disabled="disabled" class="form-control input-medium" value="'.$contract->array_options['options_password_db'].'">
										'.showValueWithClipboardCPButton($contract->array_options['options_password_db'], 0, 'none').'
											</div>
    				                    </div>
    				                  </div>';

			if (! empty($contract->array_options['options_username_ro_db'])) {
				print '
	    				                  <div class="form-group col-md-12 row">
	    				                    <label class="col-md-3 control-label">'.$langs->trans("DatabaseLoginReadOnly").'</label>
	    				                    <div class="col-md-3">
												<div class="widthcentpercentminusx inline-block nowraponall">
	    				                      <input type="text" id="username_ro_db_'.$contract->id.'" disabled="disabled" class="form-control input-medium" value="'.$contract->array_options['options_username_ro_db'].'">
										'.showValueWithClipboardCPButton($contract->array_options['options_username_ro_db'], 0, 'none').'
												</div>
	    				                    </div>
	    				                    <label class="col-md-3 control-label">'.$langs->trans("PasswordReadOnly").'</label>
	    				                    <div class="col-md-3">
												<div class="widthcentpercentminusx inline-block nowraponall">
	    				                      <input type="text" id="other_ro_db_'.$contract->id.'" disabled="disabled" class="form-control input-medium" value="'.$contract->array_options['options_password_ro_db'].'">
										'.showValueWithClipboardCPButton($contract->array_options['options_password_ro_db'], 0, 'none').'
												</div>
	    				                    </div>
	    				                  </div>';
			}

			print '
    				                </div>

    				                </form>
                                    ';
			print'					<br><div class="note note-warning">
									'.$langs->trans("CommunicateIdentifiersToOnlyTrusted").'
									</div>
									';
		} elseif ($directaccess == 4) {
			print '<!-- directaccess = '.$directaccess.' foundtemplate = '.$foundtemplate.' -->';
			print '<p class="opacitymedium" style="padding: 15px">'.$langs->trans("PleaseOpenATicketToRequestYourCredential").'</p>';
		} else {
			print '<!-- directaccess = '.$directaccess.' foundtemplate = '.$foundtemplate.' -->';
			if ($directaccess == 3 && empty($foundtemplate)) {
				print '<p class="opacitymedium" style="padding: 15px">'.img_warning('default', '', 'pictowarning pictofixedwidth').$langs->trans("SorryFeatureNotAvailableDuringTestPeriod", $langs->transnoentitiesnoconv("MyBilling")).'...</p>';
			} else {
				print '<p class="opacitymedium" style="padding: 15px">'.$langs->trans("SorryFeatureNotAvailableInYourPlan").'</p>';
			}
		}

		print '              </div> <!-- END TAB DB PANE -->';

		$ispaid = sellyoursaasIsPaidInstance($contract);
		if (! $ispaid) {	// non paid instances
			$priority = 'low';
		} else {
			if ($ispaid) {	// paid with level Premium
				if ($tmpproduct->array_options['options_typesupport'] == 'premium') {
					$priority = 'high';
				} else {	// paid with level Basic
					$priority = 'medium';
				}
			}
		}
		print '				<!-- tab upgrade -->
							<div class="tab-pane" id="tab_upgrade_'.$contract->id.'">';
		if ($lastversiondolibarrinstance < $newversiondolibarr) {
		print '				<form class="form-upgrade" action="'.$_SERVER["PHP_SELF"].'" method="POST">
							<input type="hidden" name="token" value="'.newToken().'">
							<input type="hidden" name="mode" value="autoupgrade">
							<input type="hidden" name="backtopagesupport" value="'.$_SERVER["PHP_SELF"].'?mode=instances">
							<input type="hidden" name="instanceselect" value="'.$priority.'_'.$contract->id.'">
							<p class="opacitymediumbis" style="padding: 15px">
								'.$langs->trans("NewerVersionAvailable", $lastversiondolibarrinstance, $newversiondolibarr).'
							</p>
							<p class="center" style="padding-bottom: 15px">
								<input type="submit" class="btn" name="undeploy" value="'.$langs->trans("Upgrade").'">
							</p>
							</form>';
		}else {
			print '			<p class="opacitymediumbis" style="padding: 15px">
								'.$langs->trans("AlreadyToLastVersionUpgrade", $newversiondolibarr).'
							</p>
			';
		}

		print '				</div>
							<!-- END tab upgrade -->
';
print '						<!-- tab destroy -->
				            <div class="tab-pane" id="tab_danger_'.$contract->id.'">

							<form class="form-group" action="'.$_SERVER["PHP_SELF"].'" method="POST">
                            <input type="hidden" name="token" value="'.newToken().'">

				              <div class="">
								';
		$hasopeninvoices = sellyoursaasHasOpenInvoices($contract);
		if ($hasopeninvoices) {
			print '<span class="opacitymedium">'.$langs->trans("CantCloseBecauseOfOpenInvoices").'</span><br><br>';
		} else {
			print '
					                <p class="opacitymediumbis" style="padding: 15px">
					                    '.$langs->trans("PleaseBeSure", $contract->ref_customer).'
					                </p>
									<p class="center" style="padding-bottom: 15px">
										<input type="text" required="required" class="urlofinstancetodestroy" name="urlofinstancetodestroy" value="'.GETPOST('urlofinstancetodestroy', 'alpha').'" placeholder="'.$langs->trans("NameOfInstanceToDestroy").'" autofocus>
									</p>';
		}

		$actiontoundeploy = 'undeploy';
		if (getDolGlobalInt('SELLYOURSAAS_ASK_DESTROY_REASON')) {
			$actiontoundeploy = 'confirmundeploy';
		}

		print '
								<p class="center">
									<input type="hidden" name="mode" value="instances"/>
									<input type="hidden" name="action" value="'.$actiontoundeploy.'" />
									<input type="hidden" name="contractid" value="'.$contract->id.'" />
									<input type="hidden" name="tab" value="danger_'.$contract->id.'" />
									<input type="submit" '.($hasopeninvoices ? ' disabled="disabled"' : '').' class="btn btn-danger'.($hasopeninvoices ? ' disabled' : '').'" name="undeploy" value="'.$langs->trans("UndeployInstance").'">
								</p>
				              </div>

							</form>

				            </div> <!-- END TAB PANE -->

				          </div> <!-- END TAB CONTENT -->
				        </div> <!-- END TABABLE CUSTOM-->

				      </div><!-- END PORTLET-BODY -->


					</div> <!-- END PORTLET -->

			      </div> <!-- END COL -->

			    </div> <!-- END ROW -->';
	}		// End loop contract
}
if ($action == "confirmundeploy") {
	$formquestion = array(
		array(
			"type"=>"other",
			"value"=> (empty($errortoshowinconfirm) ? '<div class="opacitymedium margintop">'.$langs->trans('FillUndeployFormDestruction').'</div>' : '<div class="error paddingall marginbottom">'.$errortoshowinconfirm.'</div>')
		),
		array(
			"type"=>"hidden",
			"name"=>"contractid",
			"value"=>GETPOST('contractid', 'int')
		),
		array(
			"type"=>"hidden",
			"name"=>"urlofinstancetodestroy",
			"value"=>GETPOST('urlofinstancetodestroy')
		),
		array(
			"type"=>"hidden",
			"name"=>"tab",
			"value"=>GETPOST('tab')
		)
	);

	// Radio part can be changed by a list of labels and a foreach
	$formquestion[] = array(
		"type"=>"radio",
		"values"=>array(
			"TechnicalIssue"=>$langs->trans("TechnicalIssue"),
		),
		"name"=>"reasonundeploy",
		"morecss"=>"hideothertag",
		"moreattr"=>"required"
	);
	$formquestion[] = array(
		"type"=>"radio",
		"values"=>array(
			"FonctionalProblem"=>$langs->trans("FonctionalProblem"),
		),
		"name"=>"reasonundeploy",
		"morecss"=>"hideothertag"
	);
	$formquestion[] = array(
		"type"=>"radio",
		"values"=>array(
			"Price"=>$langs->trans("PriceProblem")
		),
		"name"=>"reasonundeploy",
		"morecss"=>"hideothertag"
	);
	$formquestion[] = array(
		"type"=>"radio",
		"values"=>array(
			"ChangeSoftware"=>$langs->trans("ChangeSoftware")
		),
		"name"=>"reasonundeploy",
		"morecss"=>"hideothertag"
	);
	$formquestion[] = array(
		"type"=>"radio",
		"values"=>array(
			"SwitchToOnPremise"=>$langs->trans("SwitchHosting"),
		),
		"name"=>"reasonundeploy",
		"morecss"=>"hideothertag"
	);

	$formquestion[] = array(
		"type"=>"radio",
		"values"=>array(
			"other"=>$langs->trans("Other"),
		),
		"name"=>"reasonundeploy",
		"morecss"=>"hideothertag"
	);
	$formquestion[] = array(
		"type"=>"onecolumn",
		"value"=>"<br>"
	);
	$formquestion[] = array(
		"type"=>"hidden",
		"name"=>"dummyhiddenfield",
		"value"=>"",
		"moreattr"=>"autofocus"
	);
	$formquestion[] = array(
		"type"=>"textarea",
		"label"=>$langs->trans("AddACommentDestruction"),
		"name"=>"commentundeploy",
		"moreattr"=>'style="width:100%;border:solid 1px grey;"',
	);

	print $form->formconfirm($_SERVER["PHP_SELF"]."?mode=instances", $langs->trans('UndeployInstance'), "", "undeploy", $formquestion, '', 1, "510", "700", 0, 'ConfirmDestruction', 'Cancel');
	print '
	<script type="text/javascript" language="javascript">
    	jQuery(document).ready(function() {
			/* Code to toggle the show of form */
    		jQuery("input[name=\'reasonundeploy\']").click(function() {
				console.log("We select a reason of uninstallation");
				jQuery("#commentundeploy").focus();
			});
		});
	</script>';

	print '<style>
	.margintoponly{
		margin-top:0px
	}
	</style>';
}

	// Section to add/create a new instance
	print '
    	<!-- Add a new instance -->
    	<div class="portlet-body" style=""><br>
    	';

	print '<a href="#addanotherinstance" id="addanotherinstance" class="valignmiddle">';
	print '<span class="fa fa-plus-circle valignmiddle" style="font-size: 1.5em; padding-right: 4px;"></span><span class="valignmiddle text-plus-circle">'.$langs->trans("AddAnotherInstance").'...</span><br>';
	print '</a>';

	print '<script type="text/javascript" language="javascript">
        function applyDomainConstraints( domain )
        {
            domain = domain.replace(/ /g, "");
            domain = domain.normalize("NFD").replace(/[\u0300-\u036f]/g, "");
            // allow  "a-z", "A-Z", "0-9" and "-"
            domain = domain.replace(/[^\w\-]/g, "");
            domain = domain.replace(/\_/g, "");
            domain = domain.replace(/^[^a-z0-9\-]+/ig, "");		// We accept the - at start during input to avoid to have it to be removed automatically during typing
            domain = domain.replace(/[^a-z0-9\-]+$/ig, "");		// We accept the - at end during input to avoid to have it to be removed automatically during typing
            domain = domain.toLowerCase();
            if (!isNaN(domain)) {
              return ""
            }
            while ( domain.length > 1 && !isNaN( domain.charAt(0))  ){
              domain=domain.substr(1)
            }
			if (domain.length > 29) {
			  domain = domain.substring(0, 28);
			}
            return domain
        }

    	jQuery(document).ready(function() {

			/* Code to toggle the show of form */
    		jQuery("#addanotherinstance").click(function() {
    			console.log("Click on addanotherinstance");
    			jQuery("#formaddanotherinstance").toggle();
    		});

			/* Code to toggle the show of the option section */
			jQuery(".switchoptionpanel").click(function() {
				console.log("We click on toggle to see more options");
				var id = parseInt($(this).attr("id").match(/[0-9]+$/g));
				if (jQuery("#optionpanel_" + id + ":hidden").length){
					jQuery("#optionpanel_" + id).removeClass("hidden");
				} else {
					jQuery("#optionpanel_" + id).addClass("hidden");
				}
				return false;
			});

			$(".chooseoptioncustomurl").click(function() {
				console.log("We click on button Activate custom urls");
				var id = parseInt($(this).attr("id").match(/[0-9]+$/g));
				if ($("#customurlparam_" + id + ":hidden").length){
					$("#customurlparam_" + id).removeClass("hidden");
				} else {
					$("#customurlparam_" + id).addClass("hidden");
				}
				return false;
			});

			$(".chooseoptionwebsite").on("click",function(){
				console.log("We click on button Activate website");
				var id = parseInt($(this).attr("id").match(/[0-9]+$/g));
				if ($("#installwebsite_" + id + ":hidden").length){
					$("#installwebsite_" + id).removeClass("hidden");
				} else {
					$("#installwebsite_" + id).addClass("hidden");
				}
				return false;
			})
			$("#choosewebsiteidoption").on("click", function(){
				if($("#websiteidoption").val() != ""){
					console.log("We click on button Continue website Step1");
					$("#domainnamewebsite").removeClass("hidden");
				}
			})
			$("#choosedomainnamewebsite").on("click", function(){
				if($("#domainnamewebsiteinput").val() != ""){
					console.log("We click on button Continue website Step2");
					$("#choosewebsiteoption").removeClass("hidden");
					$("#choosewebsiteoption").prop("disabled", false);
				}
			})

            /* Apply constraints if sldAndSubdomain field is change */
            jQuery("#formaddanotherinstance").on("change keyup", "#sldAndSubdomain", function() {
                console.log("Update sldAndSubdomain field in instances.tpl.php");
        	    $(this).val( applyDomainConstraints( $(this).val() ) );
            });

			/* Sow hourglass */
            jQuery("#formaddanotherinstance").submit(function() {
                console.log("We clicked on submit on instance.tpl.php")

                jQuery(document.body).css({ \'cursor\': \'wait\' });
                jQuery("div#waitMask").show();
                jQuery("#waitMask").css("opacity"); // must read it first
                jQuery("#waitMask").css("opacity", "0.6");

				return true;	/* Use return false to show the hourglass without submitting the page (for debug) */
            });
    	});
	</script>
	';

	print '<br>';

	print '<!-- Form to add an instance -->'."\n";
	print '<form id="formaddanotherinstance" class="form-group reposition" style="'.(GETPOST('addanotherinstance', 'int') ? '' : 'display: none;').'" action="register_instance.php" method="POST">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="deployall" />';
if (GETPOSTISSET('forcesubdomain')) {
	print '<input type="hidden" name="forcesubdomain" value="'.GETPOST('forcesubdomain', 'alpha').'">';
}
	print '<input type="hidden" name="fromsocid" value="0" />';
	print '<input type="hidden" name="reusesocid" value="'.((int) $socid).'" />';
	print '
	<!-- Add fields to send local user information -->
	<input type="hidden" name="tz" id="tz" value="">
	<input type="hidden" name="tz_string" id="tz_string" value="">
	<input type="hidden" name="dst_observed" id="dst_observed" value="">
	<input type="hidden" name="dst_first" id="dst_first" value="">
	<input type="hidden" name="dst_second" id="dst_second" value="">
	<!-- Add fields to send other param on browsing environment -->
	<input type="hidden" name="screenwidth" id="screenwidth" value="">
	<input type="hidden" name="screenheight" id="screenheight" value="">
	';
	print '<!-- thirdpartyidinsession = '.dol_escape_htmltag($_SESSION['dol_loginsellyoursaas']).' -->';

	print '<div class="row">
    	<div class="col-md-12">

    	<div class="portlet light">';

	//var_dump($arrayofplans);
	//natcasesort($arrayofplans);

	$MAXINSTANCESPERACCOUNT = ((empty($mythirdpartyaccount->array_options['options_maxnbofinstances']) && $mythirdpartyaccount->array_options['options_maxnbofinstances'] != '0') ? (!getDolGlobalString('SELLYOURSAAS_MAX_INSTANCE_PER_ACCOUNT') ? 4 : $conf->global->SELLYOURSAAS_MAX_INSTANCE_PER_ACCOUNT) : $mythirdpartyaccount->array_options['options_maxnbofinstances']);

if ($MAXINSTANCESPERACCOUNT && count($listofcontractidopen) < $MAXINSTANCESPERACCOUNT) {
	if (getDolGlobalInt('SELLYOURSAAS_DISABLE_NEW_INSTANCES') && !in_array(getUserRemoteIP(), explode(',', getDolGlobalString('SELLYOURSAAS_DISABLE_NEW_INSTANCES_EXCEPT_IP')))) {
		print '<!-- RegistrationSuspendedForTheMomentPleaseTryLater -->'."\n";
		print '<div class="alert alert-warning" style="margin-bottom: 0px">';
		if (getDolGlobalString('SELLYOURSAAS_DISABLE_NEW_INSTANCES_MESSAGE')) {
			print getDolGlobalString('SELLYOURSAAS_DISABLE_NEW_INSTANCES_MESSAGE');
			print 'Note: '.getUserRemoteIP().' '.getDolGlobalString('SELLYOURSAAS_DISABLE_NEW_INSTANCES_EXCEPT_IP');
		} else {
			print $langs->trans("RegistrationSuspendedForTheMomentPleaseTryLater");
		}
		print '</div>';
	} else {
		print '<div class="group">';

		print '<div class="horizontal-fld centpercent marginbottomonly">';
		print '<!-- selection of plan -->'."\n";
		print '<strong>'.$langs->trans("YourSubscriptionPlan").'</strong> ';
		print $form->selectarray('service', $arrayofplansfull, $planid, 0, 0, 0, '', 0, 0, 0, '', 'width500 minwidth500');
		print '<br>';
		print '</div>';
		//print ajax_combobox('service');

		print '

			        			<div class="horizontal-fld clearboth margintoponly">
			        			<div class="control-group required">
			        			<label class="control-label" for="password" trans="1">'.$langs->trans("Password").'</label><input name="password" type="password" minlength="8" maxlength="128"'.(GETPOST('addanotherinstance', 'int') ? ' autofocus' : '').' required autocomplete="new-password" spellcheck="false" autocapitalize="off" />
			        			</div>
			        			</div>
			        			<div class="horizontal-fld margintoponly">
			        			<div class="control-group required">
			        			<label class="control-label" for="password2" trans="1">'.$langs->trans("PasswordRetype").'</label><input name="password2" type="password" minlength="8" maxlength="128" required autocomplete="new-password" spellcheck="false" autocapitalize="off" />
			        			</div>
			        			</div>
			        			</div> <!-- end group -->';

		print '
								<!-- Selection of domain to create instance -->
			        			<section id="selectDomain" style="margin-top: 20px;">
			        			<div class="fld select-domain required">
			        			<label trans="1">'.$langs->trans("ChooseANameForYourApplication").'</label>
			        			<div class="linked-flds">
			        			<span class="opacitymedium">https://</span>
			        			<input class="sldAndSubdomain" type="text" name="sldAndSubdomain" id="sldAndSubdomain" value="'.dol_escape_htmltag(GETPOST('sldAndSubdomain')).'" maxlength="29" required />
			        			<select name="tldid" id="tldid">';
		// SERVER_NAME here is myaccount.mydomain.com (we can exploit only the part mydomain.com)
		$domainname = getDomainFromURL($_SERVER["SERVER_NAME"], 1);

		$tldid=GETPOST('tldid', 'alpha');

		$domainstosuggest = array();   // This is list of all sub domains to show into combo list. Can be: with1.mydomain.com,with2.mydomain.com:ondomain1.com+ondomain2.com,...
		$domainstosuggestcountryfilter = array();
		if (!getDolGlobalString('SELLYOURSAAS_OBJECT_DEPLOYMENT_SERVER_MIGRATION')) {
			$listofdomain = explode(',', getDolGlobalString('SELLYOURSAAS_SUB_DOMAIN_NAMES'));
		} else {
			$staticdeploymentserver = new Deploymentserver($db);
			$listofdomain = $staticdeploymentserver->fetchAllDomains('', '', 1000, 0, '', 'AND');
		}
		foreach ($listofdomain as $val) {
			$newval=$val;
			$reg = array();
			$tmpdomains = array();
			if (preg_match('/:(.+)$/', $newval, $reg)) {      // If this domain must be shown only if domain match
				$tmpnewval = explode(':', $newval);
				$newval = $tmpnewval[0];        // the part before the : that we use to compare the forcesubdomain parameter.
				if (!empty($tmpnewval[1]) && $tmpnewval[1] == 'closed') {
					if ($newval != GETPOST('forcesubdomain', 'alpha') || !in_array(getUserRemoteIP(), explode(',', getDolGlobalString('SELLYOURSAAS_DISABLE_NEW_INSTANCES_EXCEPT_IP')))) {
						continue;
					}
				}

				$domainqualified = false;
				$tmpdomains = explode('+', $reg[1]);
				foreach ($tmpdomains as $tmpdomain) {
					if ($tmpdomain == $domainname || $newval == GETPOST('forcesubdomain', 'alpha')) {
						$domainqualified = true;
						break;
					}
				}
				if (! $domainqualified) {
					print '<!-- '.$newval.' disabled. Allowed only if main domain of registration page is '.$reg[1].' -->';
					continue;
				}
			}
			// $newval is subdomain (with.mysaasdomainname.com for example)

			// Restriction defined on package
			// Managed later with the "optionvisible..." css
			if (getDolGlobalString('SELLYOURSAAS_OBJECT_DEPLOYMENT_SERVER_MIGRATION')) {
				$deploymentserver = new Deploymentserver($db);
				$deploymentserver->fetch(0, $newval);

				if (!empty($deploymentserver->servercountries)) {
					$servercountries = explode(',', $deploymentserver->servercountries);
					$ipuser = getUserRemoteIP();
					$countryuser = dolGetCountryCodeFromIp($ipuser);
					if (GETPOST('country')) {	// Can force a country instead of default autodetected value
						$countryuser = GETPOST('country');
					}
					if (empty($countryuser)) {
						$countryuser='US';
					}
					$countryuser = strtolower($countryuser);

					if (in_array($countryuser, $servercountries)) {
						if (! preg_match('/^\./', $newval)) {
							$newval='.'.$newval;
						}
						$domainstosuggestcountryfilter[] = $newval; // Servers with user country
					} else {
						print '<!-- '.$newval.' disabled. Server country range '.$deploymentserver->servercountries.' does not contain '.$countryuser.' -->';
						continue;
					}
				} else {
					if (! preg_match('/^\./', $newval)) {
						$newval='.'.$newval;
					}
					$domainstosuggest[] = $newval;
				}
			} else {
				if (! preg_match('/^\./', $newval)) {
					$newval='.'.$newval;
				}
				$domainstosuggest[] = $newval;
			}
		}
		if (!empty($domainstosuggestcountryfilter)) {
			foreach ($domainstosuggest as $key => $value) {
				print '<!-- '.$value.' disabled. Matching server found with user location -->';
			}
			$domainstosuggest = $domainstosuggestcountryfilter;
		}

		// Defined a preselected domain
		$randomselect = '';
		$randomindex = 0;
		if (empty($tldid) && ! GETPOSTISSET('tldid') && ! GETPOSTISSET('forcesubdomain') && count($domainstosuggest) >= 1) {
			$maxforrandom = (count($domainstosuggest) - 1);
			$randomindex = mt_rand(0, $maxforrandom);
			$randomselect = $domainstosuggest[$randomindex];
		}
		// Force selection with no way to change value if SELLYOURSAAS_FORCE_RANDOM_SELECTION is set
		if (getDolGlobalString('SELLYOURSAAS_FORCE_RANDOM_SELECTION') && !empty($randomselect)) {
			$domainstosuggest = array();
			$domainstosuggest[] = $randomselect;
		}
		foreach ($domainstosuggest as $val) {
			print '<option class="optionfordomain';
			foreach ($tmpdomains as $tmpdomain) {	// list of restrictions for the deployment server $newval
				print ' optionvisibleondomain-'.preg_replace('/[^a-z0-9]/i', '', $tmpdomain);
			}
			print '" value="'.$val.'"'.(($tldid == $val || ($val == '.'.GETPOST('forcesubdomain', 'alpha')) || $val == $randomselect) ? ' selected="selected"' : '').'>';
			print $val;
			print '</option>';
		}

		print '</select>
			        			<br class="unfloat" />
			        			</div>
			        			</div>
			        			</section>'."\n";

		// Add code to make constraints on deployment servers
		print '<!-- JS Code to force plan -->';
		print '<script type="text/javascript" language="javascript">
						function disable_combo_if_not(s) {
							console.log("Disable combo choice except if s="+s);
							$("#tldid > option").each(function() {
								if (this.value.endsWith(s)) {
									console.log("We enable the option "+this.value);
									$(this).removeAttr("disabled");
									$(this).attr("selected", "selected");
								} else {
									console.log("We disable the option "+this.value);
									$(this).attr("disabled", "disabled");
									$(this).removeAttr("selected");
								}
							});
						}

			    		jQuery(document).ready(function() {
							jQuery("#service").change(function () {
								var pid = jQuery("#service option:selected").val();
								console.log("We select product id = "+pid);
							';
		foreach ($arrayofplansfull as $key => $plan) {
			if (!empty($plan['restrict_domains'])) {
				$restrict_domains = explode(",", $plan['restrict_domains']);
				print "/* Code if we select pid = ".$key." so plan = ".$plan['label']." with restrict_domains = ".$plan['restrict_domains']." */\n";
				foreach ($restrict_domains as $domain) {
					print " if (pid == ".$key.") { disable_combo_if_not('".trim($domain)."'); }\n";
					if ($domain == $domainname) {
						break;	// We keep only the first domain in list as the domain to keep possible for deployment
					}
				}
			} else {
				print '	/* No restriction for pid = '.$key.', currentdomain is '.$domainname.' */'."\n";
			}
		}

		print '
							});
							jQuery("#service").trigger("change");
						});'."\n";

		foreach ($arrayofplansfull as $key => $plan) {
			print '/* pid='.$key.' => '.$plan['label'].' - '.$plan['id'].' - '.$plan['restrict_domains'].' */'."\n";
		}
		print '</script>';

		if (GETPOST('admin', 'alpha')) {
			print '<div class="horizontal-fld clearboth margintoponly">';
			print '<input type="checkbox" name="disablecustomeremail" /> '.$langs->trans("DisableEmailToCustomer");
			print '</div>';
		}

		print '<br><input type="submit" class="btn btn-warning default change-plan-link" name="changeplan" value="'.$langs->trans("Create").'">';
	}
} else {
	// Max number of instances reached
	print '<!-- Max number of instances reached -->';

	$sellyoursaasemail = getDolGlobalString('SELLYOURSAAS_MAIN_EMAIL');
	if (! empty($mythirdpartyaccount->array_options['options_domain_registration_page'])
		&& $mythirdpartyaccount->array_options['options_domain_registration_page'] != $conf->global->SELLYOURSAAS_MAIN_DOMAIN_NAME) {
		$newnamekey = 'SELLYOURSAAS_MAIN_EMAIL_FORDOMAIN-'.$mythirdpartyaccount->array_options['options_domain_registration_page'];
		if (getDolGlobalString($newnamekey)) {
			$sellyoursaasemail = getDolGlobalString($newnamekey);
		}
	}

	print '<div class="warning">'.$langs->trans("MaxNumberOfInstanceReached", $MAXINSTANCESPERACCOUNT, $sellyoursaasemail).'</div>';
}

	print '</div></div></div>';

	print '</form>';

	print '</div>';	// end Add a new instance


	print '
    	    </div>
    		</div>
    	';

if (GETPOST('tab', 'alpha')) {
	print '<script type="text/javascript" language="javascript">
    		jQuery(document).ready(function() {
    			console.log("Click on '.GETPOST('tab', 'alpha').'");
    			jQuery("#a_tab_'.GETPOST('tab', 'alpha').'").click();
    		});
    		</script>';
}
?>
<!-- END PHP TEMPLATE instances.tpl.php -->
