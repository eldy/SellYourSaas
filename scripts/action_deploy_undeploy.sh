#!/bin/bash
#
# To use this script with remote ssh (not required when using the remote agent):
# Create a symbolic link to this file .../create_deploy_undeploy.sh into /usr/bin
# Grant adequate permissions (550 mean root and group www-data can read and execute, nobody can write)
# sudo chown root:www-data /usr/bin/create_deploy_undeploy.sh
# sudo chmod 550 /usr/bin/create_deploy_undeploy.sh
# And allow apache to sudo on this script by doing visudo to add line:
#www-data        ALL=(ALL) NOPASSWD: /usr/bin/create_deploy_undeploy.sh
#
# deployall    create user/dir + dns + files + config + apache virtual host + cron + database creation + cli
# deploy       create dns + files + config + apache virtual host + cron + database creation + cli
# deployoption create files + cli
# undeployall  remove user and instance
# undeploy     remove only instance (must be easy to restore) - rest can be done later with clean.sh

export now=`date +'%Y-%m-%d %H:%M:%S'`
export nowlog=`date +'%Y%m%d-%H%M%S'`

echo
echo
echo "####################################### ${0} ${1}"
echo "${0} ${@}"
echo "# user id --------> $(id -u)"
echo "# now ------------> $now"
echo "# PID ------------> ${$}"
echo "# PWD ------------> $PWD" 
echo "# arguments ------> ${@}"
echo "# parent path ----> ${0%/*}"
echo "# realname name --> $(basename $(realpath ${0}))"
echo "# realname dir ---> $(dirname $(realpath ${0}))"

export PID=${$}
export scriptdir=$(dirname $(realpath ${0}))

# possibility to change the directory of vhostfile templates
templatesdir=`grep '^templatesdir=' /etc/sellyoursaas.conf | cut -d '=' -f 2`
if [[ "x$templatesdir" != "x" ]]; then
	export vhostfile="$templatesdir/vhostHttps-sellyoursaas.template"
	export vhostfilesuspended="$templatesdir/vhostHttps-sellyoursaas-suspended.template"
	export vhostfilemaintenance="$templatesdir/vhostHttps-sellyoursaas-maintenance.template"
	export fpmpoolfiletemplate="$templatesdir/osuxxx.template"
else
	export vhostfile="$scriptdir/templates/vhostHttps-sellyoursaas.template"
	export vhostfilesuspended="$scriptdir/templates/vhostHttps-sellyoursaas-suspended.template"
	export vhostfilemaintenance="$scriptdir/templates/vhostHttps-sellyoursaas-maintenance.template"
	export fpmpoolfiletemplate="$scriptdir/templates/osuxxx.template"
fi



if [ "$(id -u)" != "0" ]; then
	echo "This script must be run as root" 1>&2
	exit 100
fi

if [ "x$1" == "x" ]; then
	echo "Missing parameter 1 - mode (deploy|deployall|undeploy|undeployall)" 1>&2
	exit 1
fi
if [ "x$2" == "x" ]; then
	echo "Missing parameter 2 - osusername" 1>&2
	exit 2
fi
if [ "x$3" == "x" ]; then
	echo "Missing parameter 3 - ospassword" 1>&2
	exit 3
fi
if [ "x$4" == "x" ]; then
	echo "Missing parameter 4 - instancename" 1>&2
	exit 4
fi
if [ "x$5" == "x" ]; then
	echo "Missing parameter 5 - domainname" 1>&2
	exit 5
fi
if [ "x$6" == "x" ]; then
	echo "Missing parameter 6 - dbname" 1>&2
	exit 6
fi
if [ "x$7" == "x" ]; then
	echo "Missing parameter 7 - dbport" 1>&2
	exit 7
fi
if [ "x$8" == "x" ]; then
	echo "Missing parameter 8 - dbusername" 1>&2
	exit 8
fi
if [ "x$9" == "x" ]; then
	echo "Missing parameter 9 - dbpassword" 1>&2
	exit 9
fi
if [ "x${22}" == "x" ]; then
	echo "Missing parameter 22 - EMAILFROM" 1>&2
	exit 22
fi
if [ "x${23}" == "x" ]; then
	echo "Missing parameter 23 - REMOTEIP" 1>&2
	exit 23
fi


export mode=$1
export osusername=$2
export ospassword=$3
export instancename=$4
export domainname=$5

export dbname=$6
export dbport=$7
export dbusername=$8
export dbpassword=$9

export fileforconfig1=${10//£/ }
export targetfileforconfig1=${11//£/ }
export dirwithdumpfile=${12//£/ }
export dirwithsources1=${13}
export targetdirwithsources1=${14}
export dirwithsources2=${15}
export targetdirwithsources2=${16}
export dirwithsources3=${17}
export targetdirwithsources3=${18}
export cronfile=${19}
export cliafter=${20}
export targetdir=${21}
export EMAILTO=${22}
export REMOTEIP=${23}
export SELLYOURSAAS_ACCOUNT_URL=${24}
export instancenameold=${25}
export domainnameold=${26}
export customurl=${27//£/ }
if [ "x$customurl" == "x-" ]; then
	customurl=""
fi
export contractlineid=${28}
export EMAILFROM=${29}
# CERTIFFORCUSTOMDOMAIN. Example: withY.mysaasdomain.com, myowndomain.com 
export CERTIFFORCUSTOMDOMAIN=${30}
if [ "x$CERTIFFORCUSTOMDOMAIN" == "x-" ]; then
	CERTIFFORCUSTOMDOMAIN=""
fi
export archivedir=${31}
export SSLON=${32}
export apachereload=${33}
export ALLOWOVERRIDE=${34//£/ }
if [ "x$ALLOWOVERRIDE" == "x-" ]; then
	ALLOWOVERRIDE=""
fi
export VIRTUALHOSTHEAD=${35//£/ }
if [ "x$VIRTUALHOSTHEAD" == "x-" ]; then
	VIRTUALHOSTHEAD=""
fi
export ispaidinstance=${36}
export SELLYOURSAAS_LOGIN_FOR_SUPPORT=${37}
export directaccess=${38}
export sshaccesstype=${39}
export INCLUDEFROMCONTRACT=${40//£/ }
if [ "x$INCLUDEFROMCONTRACT" == "x-" ]; then
	INCLUDEFROMCONTRACT=""
fi

export CUSTOMDOMAIN=${46}



export ErrorLog='#ErrorLog'

export instancedir=$targetdir/$osusername/$dbname
export fqn=$instancename.$domainname
export fqnold=$instancenameold.$domainnameold
export CRONHEAD=${VIRTUALHOSTHEAD/php_value date.timezone /TZ=}

# possibility to change the ssl certificates name
export webSSLCertificateCRT=`grep '^websslcertificatecrt=' /etc/sellyoursaas.conf | cut -d '=' -f 2`
if [[ "x$webSSLCertificateCRT" == "x" ]]; then
	export webSSLCertificateCRT=with.sellyoursaas.com.crt
fi
export webSSLCertificateKEY=`grep '^websslcertificatekey=' /etc/sellyoursaas.conf | cut -d '=' -f 2`
if [[ "x$webSSLCertificateKEY" == "x" ]]; then
	export webSSLCertificateKEY=with.sellyoursaas.com.key
fi
export webSSLCertificateIntermediate=`grep '^websslcertificateintermediate=' /etc/sellyoursaas.conf | cut -d '=' -f 2`
if [[ "x$webSSLCertificateIntermediate" == "x" ]]; then
	export webSSLCertificateIntermediate=with.sellyoursaas.com-intermediate.crt
fi

export usecompressformatforarchive=`grep '^usecompressformatforarchive=' /etc/sellyoursaas.conf | cut -d '=' -f 2`

# possibility to change the path of sellyoursass directory
olddoldataroot=`grep '^olddoldataroot=' /etc/sellyoursaas.conf | cut -d '=' -f 2`
newdoldataroot=`grep '^newdoldataroot=' /etc/sellyoursaas.conf | cut -d '=' -f 2`
if [[ "x$olddoldataroot" != "x" && "x$newdoldataroot" != "x" ]]; then
	fileforconfig1=${fileforconfig1/$olddoldataroot/$newdoldataroot}
	dirwithdumpfile=${dirwithdumpfile/$olddoldataroot/$newdoldataroot}
	dirwithsources1=${dirwithsources1/$olddoldataroot/$newdoldataroot}
	dirwithsources2=${dirwithsources2/$olddoldataroot/$newdoldataroot}
	dirwithsources3=${dirwithsources3/$olddoldataroot/$newdoldataroot}
	cronfile=${cronfile/$olddoldataroot/$newdoldataroot}
	cliafter=${cliafter/$olddoldataroot/$newdoldataroot}
fi

# For debug
echo `date +'%Y-%m-%d %H:%M:%S'`" input params for $0:"
echo "mode = $mode"
echo "osusername = $osusername"
echo "ospassword = XXXXXX"
echo "instancename = $instancename"
echo "domainname = $domainname"
echo "dbname = $dbname"
echo "dbport = $dbport"
echo "dbusername = $dbusername"
echo "dbpassword = XXXXXX"
echo "fileforconfig1 = $fileforconfig1"
echo "targetfileforconfig1 = $targetfileforconfig1"
echo "dirwithdumpfile = $dirwithdumpfile"
echo "dirwithsources1 = $dirwithsources1"
echo "targetdirwithsources1 = $targetdirwithsources1"
echo "dirwithsources2 = $dirwithsources2"
echo "targetdirwithsources2 = $targetdirwithsources2"
echo "dirwithsources3 = $dirwithsources3"
echo "targetdirwithsources3 = $targetdirwithsources3"
echo "cronfile = $cronfile"
echo "cliafter = $cliafter"
echo "targetdir = $targetdir"
echo "EMAILTO = $EMAILTO"
echo "REMOTEIP = $REMOTEIP"
echo "SELLYOURSAAS_ACCOUNT_URL = $SELLYOURSAAS_ACCOUNT_URL" 
echo "instancenameold = $instancenameold" 
echo "domainnameold = $domainnameold" 
echo "customurl = $customurl" 
echo "contractlineid = $contractlineid" 
echo "EMAILFROM = $EMAILFROM"
echo "CERTIFFORCUSTOMDOMAIN = $CERTIFFORCUSTOMDOMAIN"
echo "archivedir = $archivedir"
echo "SSLON = $SSLON"
echo "apachereload = $apachereload"
# ALLOWOVERRIDE come from package
echo "ALLOWOVERRIDE = $ALLOWOVERRIDE"
# VIRTUALHOSTHEAD come from contract
echo "VIRTUALHOSTHEAD = $VIRTUALHOSTHEAD"
echo "ispaidinstance = $ispaidinstance"
echo "SELLYOURSAAS_LOGIN_FOR_SUPPORT = $SELLYOURSAAS_LOGIN_FOR_SUPPORT"
echo "directaccess = $directaccess"
echo "sshaccesstype = $sshaccesstype"
echo "ErrorLog = $ErrorLog"

echo `date +'%Y-%m-%d %H:%M:%S'`" calculated params:"
echo "templatesdir (from /etc/sellyoursaas.conf) = $templatesdir"
echo "instancedir (from /etc/sellyoursaas.conf) = $instancedir"
echo "webSSLCertificateCRT = $webSSLCertificateCRT"
echo "webSSLCertificateKEY = $webSSLCertificateKEY"
echo "webSSLCertificateIntermediate = $webSSLCertificateIntermediate"
echo "vhostfile = $vhostfile"
echo "fqn = $fqn"
echo "fqnold = $fqnold"
echo "CRONHEAD = $CRONHEAD"


MYSQL=`which mysql`
MYSQLDUMP=`which mysqldump`

echo "Search database server name and port for deployment server in /etc/sellyoursaas.conf"
dbserverhost=`grep '^databasehostdeployment=' /etc/sellyoursaas.conf | cut -d '=' -f 2`
if [[ "x$dbserverhost" == "x" ]]; then
	dbserverhost="localhost"
fi 
dbserverport=`grep '^databaseportdeployment=' /etc/sellyoursaas.conf | cut -d '=' -f 2`
if [[ "x$dbserverport" == "x" ]]; then
	dbserverport="3306"
fi

echo "Search admin database credential for deployement server in /etc/sellyoursaas.conf"
dbadminuser=`grep '^databaseuserdeployment=' /etc/sellyoursaas.conf | cut -d '=' -f 2`
if [[ "x$dbadminuser" == "x" ]]; then
	dbadminuser="sellyoursaas"
fi 
dbadminpass=`grep '^databasepassdeployment=' /etc/sellyoursaas.conf | cut -d '=' -f 2`
if [[ "x$dbadminpass" == "x" ]]; then
	dbadminpass=`grep '^databasepass=' /etc/sellyoursaas.conf | cut -d '=' -f 2`
	if [[ "x$dbadminpass" == "x" ]]; then
		echo Failed to get password for mysql admin user 
		exit 10
	fi
fi 
dbforcesetpassword=`grep '^dbforcesetpassword=' /etc/sellyoursaas.conf | cut -d '=' -f 2`
if [[ "x$dbforcesetpassword" == "x" ]]; then
	dbforcesetpassword="0"
fi
dnsserver=`grep '^dnsserver=' /etc/sellyoursaas.conf | cut -d '=' -f 2`
if [[ "x$dnsserver" == "x" ]]; then
	echo Failed to get dns server parameters 
	exit 11
fi

if [[ ! -d $archivedir ]]; then
	echo Failed to find archive directory $archivedir
	echo "Failed to $mode instance $instancename.$domainname with: Failed to find archive directory $archivedir" | mail -aFrom:$EMAILFROM -s "[Alert] Pb in deploy/undeploy" $EMAILTO
	exit 12
fi

archivetestinstances=`grep '^archivetestinstances=' /etc/sellyoursaas.conf | cut -d '=' -f 2`
archivepaidinstances=1

testorconfirm="confirm"



# Create user and directory

if [[ "$mode" == "deployall" ]]; then

	echo `date +'%Y-%m-%d %H:%M:%S'`" ***** Create user $osusername with home into $targetdir/$osusername"
	
	id -u $osusername
	notfound=$?
	echo notfound=$notfound
	
	if [[ $notfound == 0 ]]
	then
		echo "$osusername seems to already exists"
	else
		echo "perl -e'print crypt(\"'XXXXXX'\", "saltsalt")'"
		export passcrypted=`perl -e'print crypt("'$ospassword'", "saltsalt")'`
		echo "useradd -m -d $targetdir/$osusername -p 'YYYYYY' -s '/bin/secureBash' $osusername"
		useradd -m -d $targetdir/$osusername -p "$passcrypted" -s '/bin/secureBash' $osusername 
		if [[ "$?x" != "0x" ]]; then
			echo Error failed to create user $osusername 
			echo "Failed to deployall instance $instancename.$domainname with: useradd -m -d $targetdir/$osusername -p $ospassword -s '/bin/secureBash' $osusername" | mail -aFrom:$EMAILFROM -s "[Alert] Pb in deployment" $EMAILTO
			exit 13
		fi
		chmod -R go-rwx $targetdir/$osusername
	fi

	if [[ -d $targetdir/$osusername ]]
	then
		echo "$targetdir/$osusername exists. good."
	else
		mkdir $targetdir/$osusername
		chmod -R go-rwx $targetdir/$osusername
	fi
	
	if [[ "$sshaccesstype" > "0" ]]; then
		if [[ ! -f "/etc/jailkit/jk_init.ini" ]]; then
			echo "Error failed to find jailkit package in your system"
		else
			chrootdir=`grep '^chrootdir=' /etc/sellyoursaas.conf | cut -d '=' -f 2`
			if [[ "x$chrootdir" == "x" ]]; then
				echo "Error your jailkit chroot directory is not defined in sellyoursaas.conf"
			else
				if [[ ! -d "$chrootdir" ]]; then
					echo "Create $chrootdir directory"
					mkdir $chrootdir
				fi
				
				privatejailtemplatename=`grep '^privatejailtemplatename=' /etc/sellyoursaas.conf | cut -d '=' -f 2`
				commonjailtemplatename=`grep '^commonjailtemplatename=' /etc/sellyoursaas.conf | cut -d '=' -f 2`
				
				echo `date +'%Y-%m-%d %H:%M:%S'`" ***** Create jailkit chroot directory for user $osusername"
				echo "chrootdir = $chrootdir"
				echo "privatejailtemplatename = $privatejailtemplatename"
				echo "commonjailtemplatename = $commonjailtemplatename"
				
				# Common users jail
				if [[ "$sshaccesstype" == "1" ]]; then
					if [[ "x$commonjailtemplatename" == "x" ]]; then
						echo "Error your jailkit common template name is not defined in sellyoursaas.conf"
					else
						if [[ ! -d "$chrootdir/$commonjailtemplatename" ]]; then
							echo "Common jail directory $chrootdir/$commonjailtemplatename not exists, try to create it"
							if [[ -f "$templatesdir/$commonjailtemplatename.tar.zst" ]]; then
									echo "tar -I zstd -xf $templatesdir/$commonjailtemplatename.tar.zst --directory $chrootdir/"
									tar -I zstd -xf $templatesdir/$commonjailtemplatename.tar.zst --directory $chrootdir/
							else
								if [[ -f "$templatesdir/$commonjailtemplatename.tgz" ]]; then
									echo "tar -xzf $templatesdir/$commonjailtemplatename.tgz --directory $chrootdir/"
									tar -xzf $templatesdir/$commonjailtemplatename.tgz --directory $chrootdir/
								else
									echo "Failed to get jailkit common template $templatesdir/$commonjailtemplatename.[tgz|tar.zst]"
									exit 14
								fi
							fi
						fi
						if [[ ! -d "$chrootdir/$commonjailtemplatename$targetdir/$osusername" ]]; then
							echo "mkdir -p $chrootdir/$commonjailtemplatename$targetdir/$osusername"
							mkdir -p $chrootdir/$commonjailtemplatename$targetdir/$osusername
						fi
						echo "jk_jailuser -s /bin/bash -n -j $chrootdir/$commonjailtemplatename/ $osusername"
						jk_jailuser -s /bin/bash -n -j $chrootdir/$commonjailtemplatename/ $osusername
						# check if already mounted
						if mountpoint -q $chrootdir/$commonjailtemplatename$targetdir/$osusername
						then
							echo "$chrootdir/$commonjailtemplatename$targetdir/$osusername is already mounted"
						else
							echo "mount $targetdir/$osusername $chrootdir/$commonjailtemplatename$targetdir/$osusername -o bind"
							mount $targetdir/$osusername $chrootdir/$commonjailtemplatename$targetdir/$osusername -o bind
						fi
						# check if already declared in /etc/fstab
						if grep -q "$chrootdir/$commonjailtemplatename$targetdir/$osusername" /etc/fstab
						then
							echo "$chrootdir/$commonjailtemplatename$targetdir/$osusername is already declared in /etc/fstab"
						else
							echo "$targetdir/$osusername $chrootdir/$commonjailtemplatename$targetdir/$osusername bind defaults,bind 0 >> /etc/fstab"
							echo "$targetdir/$osusername $chrootdir/$commonjailtemplatename$targetdir/$osusername bind defaults,bind 0" >> /etc/fstab
						fi
					fi
				else
					# Private users jail
					if [[ "$sshaccesstype" == "2" ]]; then
						if [[ ! -d "$chrootdir/$osusername" ]]; then
							if [[ "x$privatejailtemplatename" != "x" && -f "$templatesdir/$privatejailtemplatename.tar.zst" ]]; then
								echo "tar -I zstd -xf $templatesdir/$privatejailtemplatename.tar.zst --directory $chrootdir/"
								tar -I zstd -xf $templatesdir/$privatejailtemplatename.tar.zst --directory $chrootdir/
								echo "mv $chrootdir/$privatejailtemplatename $chrootdir/$osusername"
								mv $chrootdir/$privatejailtemplatename $chrootdir/$osusername
							else
								if [[ "x$privatejailtemplatename" != "x" && -f "$templatesdir/$privatejailtemplatename.tgz" ]]; then
									echo "tar -xzf $templatesdir/$privatejailtemplatename.tgz --directory $chrootdir/"
									tar -xzf $templatesdir/$privatejailtemplatename.tgz --directory $chrootdir/
									echo "mv $chrootdir/$privatejailtemplatename $chrootdir/$osusername"
									mv $chrootdir/$privatejailtemplatename $chrootdir/$osusername
								else
									echo "jk_init -c /etc/jailkit/jk_init.ini $chrootdir/$osusername extendedshell limitedshell groups sftp rsync editors git php mysqlclient"
									jk_init -c /etc/jailkit/jk_init.ini $chrootdir/$osusername extendedshell limitedshell groups sftp rsync editors git php mysqlclient >/dev/null 2>&1
								fi
							fi
							echo "mkdir -p $chrootdir/$osusername$targetdir/$osusername"
							mkdir -p $chrootdir/$osusername$targetdir/$osusername
						fi
						echo "jk_jailuser -s /bin/bash -n -j $chrootdir/$osusername/ $osusername"
						jk_jailuser -s /bin/bash -n -j $chrootdir/$osusername/ $osusername
						# check if already mounted
						if mountpoint -q $chrootdir/$osusername$targetdir/$osusername
						then
							echo "$chrootdir/$osusername$targetdir/$osusername is already mounted"
						else
							echo "mount $targetdir/$osusername $chrootdir/$osusername$targetdir/$osusername -o bind"
							mount $targetdir/$osusername $chrootdir/$osusername$targetdir/$osusername -o bind
						fi
						# check if already declared in /etc/fstab
						if grep -q "$chrootdir/$osusername$targetdir/$osusername" /etc/fstab
						then
							echo "$chrootdir/$osusername$targetdir/$osusername is already declared in /etc/fstab"
						else
							echo "$targetdir/$osusername $chrootdir/$osusername$targetdir/$osusername bind defaults,bind 0 >> /etc/fstab"
							echo "$targetdir/$osusername $chrootdir/$osusername$targetdir/$osusername bind defaults,bind 0" >> /etc/fstab
						fi
					fi
				fi
			fi
		fi
	fi
fi

if [[ "$mode" == "undeploy" || "$mode" == "undeployall" ]]; then

	echo rm -f $targetdir/$osusername/$dbname/*.log
	rm -f $targetdir/$osusername/$dbname/*.log >/dev/null 2>&1 
	echo rm -f $targetdir/$osusername/$dbname/*.log.*
	rm -f $targetdir/$osusername/$dbname/*.log.* >/dev/null 2>&1 
	
	if [[ "$sshaccesstype" > "0" ]]; then
		
		if [[ ! -f "/etc/jailkit/jk_init.ini" ]]; then
			echo "Error failed to find jailkit package in your system"
		else
			chrootdir=`grep '^chrootdir=' /etc/sellyoursaas.conf | cut -d '=' -f 2`
			if [[ "x$chrootdir" == "x" ]]; then
				Error your jailkit chroot directory is not defined in sellyoursaas.conf
			else
				if [[ -d "$chrootdir" ]]; then
				
					commonjailtemplatename=`grep '^commonjailtemplatename=' /etc/sellyoursaas.conf | cut -d '=' -f 2`
					
					echo `date +'%Y-%m-%d %H:%M:%S'`" ***** Remove jailkit chroot directory for user $osusername"
					echo "chrootdir = $chrootdir"
					echo "commonjailtemplatename = $commonjailtemplatename"
					
					# Common users jail
					if [[ "$sshaccesstype" == "1" ]]; then
						if [[ "x$commonjailtemplatename" == "x" ]]; then
							echo "Error your jailkit common template name is not defined in sellyoursaas.conf"
						else
							if [[ -d "$chrootdir/$commonjailtemplatename" ]]; then
								echo "umount $chrootdir/$commonjailtemplatename$targetdir/$osusername"
								umount $chrootdir/$commonjailtemplatename$targetdir/$osusername
								echo "rm -Rf $chrootdir/$commonjailtemplatename$targetdir/$osusername"
								rm -Rf $chrootdir/$commonjailtemplatename$targetdir/$osusername
								echo 'sed -i "/$osusername/d" $chrootdir/$commonjailtemplatename/etc/passwd'
								sed -i "/$osusername/d" $chrootdir/$commonjailtemplatename/etc/passwd
								echo 'sed -i "/$osusername/d" $chrootdir/$commonjailtemplatename/etc/group'
								sed -i "/$osusername/d" $chrootdir/$commonjailtemplatename/etc/group
							else
								echo "Failed to find common jail $chrootdir/$commonjailtemplatename"
							fi
						fi
					else
						# Private users jail
						if [[ "$sshaccesstype" == "2" ]]; then
							if [[ -d "$chrootdir/$osusername" ]]; then
								echo "umount $chrootdir/$osusername$targetdir/$osusername"
								umount $chrootdir/$osusername$targetdir/$osusername
								echo "rm -Rf $chrootdir/$osusername"
								rm -Rf $chrootdir/$osusername
							else
								echo "Failed to find private jail $chrootdir/$osusername"
							fi
						fi
					fi
					echo 'sed -i "/$osusername/d" /etc/fstab'
					sed -i "/$osusername/d" /etc/fstab
					# to prevent error "user osuxxxxx is currently used by process xxxx"
					echo "killall -u $osusername; sleep 2"
					killall -u $osusername; sleep 2
					echo "usermod -d $targetdir/$osusername --shell /bin/false $osusername"
					usermod -d $targetdir/$osusername --shell /bin/false $osusername
				fi
			fi
		fi
	fi

fi



# Create/Remove DNS entry

if [[ "$dnsserver" == "1" ]]; then

	if [[ "$mode" == "deploy" || "$mode" == "deployall" ]]; then
	
		export ZONE="$domainname.hosts" 
		
		#$ttl 1d
		#$ORIGIN with.dolicloud.com.
		#@               IN     SOA   ns1with.dolicloud.com. admin.dolicloud.com. (
		#                2017051526       ; serial
		#                600              ; refresh = 10 minutes
		#                300              ; update retry = 5 minutes
		#                604800           ; expiry = 3 weeks + 12 hours
		#                660              ; negative ttl
		#                )
		#                NS              ns1with.dolicloud.com.
		#                NS              ns2with.dolicloud.com.
		#                IN      TXT     "v=spf1 mx ~all".
		#
		#@               IN      A       79.137.96.15
		#
		#
		#$ORIGIN with.dolicloud.com.
		#
		#; other sub-domain records
	
		echo `date +'%Y-%m-%d %H:%M:%S'`" ***** Add DNS entry for $instancename in $domainname - Test with cat /etc/bind/${ZONE} | grep '^$instancename ' 2>&1"
	
		cat /etc/bind/${ZONE} | grep "^$instancename " 2>&1
		notfound=$?
		echo notfound=$notfound
	
		if [[ $notfound == 0 ]]; then
			echo "entry $instancename already found into host /etc/bind/${ZONE}"
		else
			echo "cat /etc/bind/${ZONE} | grep -v '^$instancename ' > /tmp/${ZONE}.$PID"
			cat /etc/bind/${ZONE} | grep -v "^$instancename " > /tmp/${ZONE}.$PID
	
			echo `date +'%Y-%m-%d %H:%M:%S'`" ***** Add $instancename A $REMOTEIP into tmp host file"
			echo $instancename A $REMOTEIP >> /tmp/${ZONE}.$PID  
	
			# we're looking line containing this comment
			export DATE=`date +%y%m%d%H`
			export NEEDLE="serial"
			curr=$(/bin/grep -e "${NEEDLE}$" /tmp/${ZONE}.$PID | /bin/sed -n "s/^\s*\([0-9]*\)\s*;\s*${NEEDLE}\s*/\1/p")
			# replace if current date is shorter (possibly using different format)
			echo "/bin/grep -e \"${NEEDLE}$\" /tmp/${ZONE}.$PID | /bin/sed -n \"s/^\s*\([0-9]*\)\s*;\s*${NEEDLE}\s*/\1/p\""
			echo "Current bind counter during $mode is $curr"
			if [ "x$curr" == "x" ]; then
				echo Error when editing the DNS file during a deployment. Failed to find bind counter in file /tmp/${ZONE}.$PID. Sending email to $EMAILTO
				echo "Failed to deployall instance $instancename.$domainname with: Error when editing the DNS file. Failed to find bind counter in file /tmp/${ZONE}.$PID" | mail -aFrom:$EMAILFROM -s "[Alert] Pb in deployment" $EMAILTO
				exit 15
			fi
			if [ ${#curr} -lt ${#DATE} ]; then
			  serial="${DATE}00"
			else
			  prefix=${curr::-2}
			  if [ "$DATE" -eq "$prefix" ]; then # same day
			    num=${curr: -2} # last two digits from serial number
			    num=$((10#$num + 1)) # force decimal representation, increment
			    serial="${DATE}$(printf '%02d' $num )" # format for 2 digits
			  else
			    serial="${DATE}00" # just update date
			  fi
			fi
			echo Replace serial in /tmp/${ZONE}.$PID with ${serial}
			/bin/sed -i -e "s/^\(\s*\)[0-9]\{0,\}\(\s*;\s*${NEEDLE}\)$/\1${serial}\2/" /tmp/${ZONE}.$PID
			
			echo `date +'%Y-%m-%d %H:%M:%S'`" Test temporary file with named-checkzone $domainname /tmp/${ZONE}.$PID"
			
			named-checkzone $domainname /tmp/${ZONE}.$PID
			if [[ "$?x" != "0x" ]]; then
				echo Error when editing the DNS file during a deployment. File /tmp/${ZONE}.$PID is not valid. Sending email to $EMAILFROM
				echo "Failed to deployall instance $instancename.$domainname with: Error when editing the DNS file. File /tmp/${ZONE}.$PID is not valid" | mail -aFrom:$EMAILFROM -s "[Alert] Pb in deployment" $EMAILTO 
				exit 16
			fi
			
			echo `date +'%Y-%m-%d %H:%M:%S'`" **** Archive file with cp /etc/bind/${ZONE} /etc/bind/archives/${ZONE}-$nowlog"
			cp /etc/bind/${ZONE} /etc/bind/archives/${ZONE}-$nowlog
			
			echo `date +'%Y-%m-%d %H:%M:%S'`" **** Move new host file"
			mv -fu /tmp/${ZONE}.$PID /etc/bind/${ZONE}
			
			echo `date +'%Y-%m-%d %H:%M:%S'`" **** Reload dns with rndc reload $domainname"
			rndc reload $domainname
			#/etc/init.d/bind9 reload
			
			echo `date +'%Y-%m-%d %H:%M:%S'`" **** nslookup $fqn 127.0.0.1"
			nslookup $fqn 127.0.0.1
			if [[ "$?x" != "0x" ]]; then
				echo Error after reloading DNS. nslookup of $fqn fails on first try. We wait a little bit to make another try.
				sleep 3
				nslookup $fqn 127.0.0.1
				if [[ "$?x" != "0x" ]]; then
					echo Error after reloading DNS. nslookup of $fqn fails on second try too.
					echo "Failed to deployall instance $instancename.$domainname with: Error after reloading DNS. nslookup of $fqn fails of 2 tries." | mail -aFrom:$EMAILFROM -s "[Alert] Pb in deployment" $EMAILTO 
					exit 17
				fi
			fi 
		fi
	fi
	
	if [[ "$mode" == "undeploy" || "$mode" == "undeployall" ]]; then
	
		export ZONE="$domainname.hosts" 
	
		echo `date +'%Y-%m-%d %H:%M:%S'`" ***** Remove DNS entry for $instancename in $domainname - Test with cat /etc/bind/${ZONE} | grep '^$instancename '"
	
		cat /etc/bind/${ZONE} | grep "^$instancename " 2>&1
		notfound=$?
		echo notfound=$notfound
	
		if [[ $notfound == 1 ]]; then
			echo `date +'%Y-%m-%d %H:%M:%S'`" entry $instancename already not found into host /etc/bind/${ZONE}"
		else
			echo "cat /etc/bind/${ZONE} | grep -v '^$instancename ' > /tmp/${ZONE}.$PID"
			cat /etc/bind/${ZONE} | grep -v "^$instancename " > /tmp/${ZONE}.$PID
	
			#echo `date +'%Y-%m-%d %H:%M:%S'`" ***** Add $instancename A $REMOTEIP into tmp host file"
			#echo $instancename A $REMOTEIP >> /tmp/${ZONE}.$PID  
	
			# we're looking line containing this comment
			export DATE=`date +%y%m%d%H`
			export NEEDLE="serial"
			curr=$(/bin/grep -e "${NEEDLE}$" /tmp/${ZONE}.$PID | /bin/sed -n "s/^\s*\([0-9]*\)\s*;\s*${NEEDLE}\s*/\1/p")
			# replace if current date is shorter (possibly using different format)
			echo "/bin/grep -e \"${NEEDLE}$\" /tmp/${ZONE}.$PID | /bin/sed -n \"s/^\s*\([0-9]*\)\s*;\s*${NEEDLE}\s*/\1/p\""
			echo "Current bind counter during $mode is $curr"
			if [ ${#curr} -lt ${#DATE} ]; then
			  serial="${DATE}00"
			else
			  prefix=${curr::-2}
			  if [ "$DATE" -eq "$prefix" ]; then # same day
			    num=${curr: -2} # last two digits from serial number
			    num=$((10#$num + 1)) # force decimal representation, increment
			    serial="${DATE}$(printf '%02d' $num )" # format for 2 digits
			  else
			    serial="${DATE}00" # just update date
			  fi
			fi
			echo Replace serial in /tmp/${ZONE}.$PID with ${serial}
			/bin/sed -i -e "s/^\(\s*\)[0-9]\{0,\}\(\s*;\s*${NEEDLE}\)$/\1${serial}\2/" /tmp/${ZONE}.$PID
			
			echo `date +'%Y-%m-%d %H:%M:%S'`" Test temporary file with named-checkzone $domainname /tmp/${ZONE}.$PID"
			
			named-checkzone $domainname /tmp/${ZONE}.$PID
			if [[ "$?x" != "0x" ]]; then
				echo Error when editing the DNS file un undeployment. File /tmp/${ZONE}.$PID is not valid 
				echo "Failed to deployall instance $instancename.$domainname with: Error when editing the DNS file. File /tmp/${ZONE}.$PID is not valid" | mail -aFrom:$EMAILFROM -s "[Alert] Pb in deployment" $EMAILTO
				exit 18
			fi
			
			echo `date +'%Y-%m-%d %H:%M:%S'`" **** Archive file with cp /etc/bind/${ZONE} /etc/bind/archives/${ZONE}-$nowlog"
			cp /etc/bind/${ZONE} /etc/bind/archives/${ZONE}-$nowlog
			
			echo `date +'%Y-%m-%d %H:%M:%S'`" **** Move new host file with mv -fu /tmp/${ZONE}.$PID /etc/bind/${ZONE}"
			mv -fu /tmp/${ZONE}.$PID /etc/bind/${ZONE}
			
			echo `date +'%Y-%m-%d %H:%M:%S'`" **** Reload dns with rndc reload $domainname"
			rndc reload $domainname
			#/etc/init.d/bind9 reload
			
			#echo `date +'%Y-%m-%d %H:%M:%S'`" **** nslookup $fqn 127.0.0.1"
			#nslookup $fqn 127.0.0.1
			#if [[ "$?x" != "0x" ]]; then
			#	echo Error after reloading DNS. nslookup of $fqn fails. 
			#	echo "Failed to deployall instance $instancename.$domainname with: Error after reloading DNS. nslookup of $fqn fails. " | mail -aFrom:$EMAILFROM -s "[Alert] Pb in deployment" $EMAILTO
			#	exit 1
			#fi 
		fi
	
	fi

fi


# Deploy files

if [[ "$mode" == "deploy" || "$mode" == "deployall" || "$mode" == "deployoption" ]]; then

	echo `date +'%Y-%m-%d %H:%M:%S'`" ***** Deploy files"
	
	echo "Create dir for instance = $targetdir/$osusername/$dbname"
	mkdir -p $targetdir/$osusername/$dbname
	
	echo `date +'%Y-%m-%d %H:%M:%S'`" Check dirwithsources1=$dirwithsources1 targetdirwithsources1=$targetdirwithsources1"
	if [ -d $dirwithsources1 ]; then
		if [[ "x$targetdirwithsources1" != "x" ]]; then
			mkdir -p $targetdirwithsources1
			if [[ -f $dirwithsources1.tar.zst ]]; then
				echo "Remote zst cache found. We use it with: tar -I zstd -xf $dirwithsources1.tar.zst --directory $targetdirwithsources1/"
				tar -I zstd -xf $dirwithsources1.tar.zst --directory $targetdirwithsources1/
			else
				if [ -f $dirwithsources1.tgz ]; then
					echo "Remote tgz cache found. We use it with: tar -xzf $dirwithsources1.tgz --directory $targetdirwithsources1/"
					tar -xzf $dirwithsources1.tgz --directory $targetdirwithsources1/
				else
					datesource=`date -r $dirwithsources1 +"%Y%m%d"`
					if [ -f "/tmp/cache$dirwithsources1.tgz" ]; then
						# compare date of file with date of source dir
						datecache=`date -r /tmp/cache$dirwithsources1.tgz +"%Y%m%d"`
					else 
						datecache=0
					fi
					echo "datesource=$datesource datecache=$datecache"

					if [ ! -f "/tmp/cache$dirwithsources1.tgz" -o $datesource -gt $datecache ]; then
						echo "Remote cache does not exists. Local cache does not exists or is too old, we recreate local cache"
						mkdir -p "/tmp/cache$dirwithsources1"
						#echo "cp -r $dirwithsources1/. /tmp/cache$dirwithsources1"
						cd $dirwithsources1/.
						echo "tar c -I gzip --exclude-vcs --exclude-from=$scriptdir/git_update_sources.exclude -f /tmp/cache$dirwithsources1.tgz ."
						#cp -r $dirwithsources1/. $targetdirwithsources1
						tar c -I gzip --exclude-vcs --exclude-from=$scriptdir/git_update_sources.exclude -f /tmp/cache$dirwithsources1.tgz .
					fi 

					if [ ! -f "/tmp/cache$dirwithsources1.tgz" ]; then
						# If cache does not exists. Should not happen
                        echo "Warning: Both remote and local cache does not exists. Should not happen."
                        echo "cp -r  $dirwithsources1/. $targetdirwithsources1"
                        cp -r  $dirwithsources1/. $targetdirwithsources1
					else 
						# If cache exists.
                        echo "Local cache found. We uncompress it."
                        echo "tar -xzf /tmp/cache$dirwithsources1.tgz --directory $targetdirwithsources1/"
                        tar -xzf /tmp/cache$dirwithsources1.tgz --directory $targetdirwithsources1/
                    fi
          		fi
			fi
		fi
	fi
	echo `date +'%Y-%m-%d %H:%M:%S'`" Check dirwithsources2=$dirwithsources2 targetdirwithsources2=$targetdirwithsources2"
	if [ -d $dirwithsources2 ]; then
		if [[ "x$targetdirwithsources2" != "x" ]]; then
			mkdir -p $targetdirwithsources2
			if [[ -f $dirwithsources2.tar.zst ]]; then
				echo "Remote zst cache found. We use it with: tar -I zstd -xf $dirwithsources2.tar.zst --directory $targetdirwithsources2/"
				tar -I zstd -xf $dirwithsources2.tar.zst --directory $targetdirwithsources2/
			else
				if [ -f $dirwithsources2.tgz ]; then
					echo "Remote tgz cache found. We use it with: tar -xzf $dirwithsources2.tgz --directory $targetdirwithsources2/"
					tar -xzf $dirwithsources2.tgz --directory $targetdirwithsources2/
				else
					datesource=`date -r $dirwithsources2 +"%Y%m%d"`
					if [ -f "/tmp/cache$dirwithsources2.tgz" ]; then
						# compare date of file with date of source dir
						datecache=`date -r /tmp/cache$dirwithsources2.tgz +"%Y%m%d"`
					else 
						datecache=0
					fi
					echo "datesource=$datesource datecache=$datecache"

					if [ ! -f "/tmp/cache$dirwithsources2.tgz" -o $datesource -gt $datecache ]; then
						echo "Remote cache does not exists. Local cache does not exists or is too old, we recreate local cache"
						mkdir -p "/tmp/cache$dirwithsources2"
						#echo "cp -r $dirwithsources2/. /tmp/cache$dirwithsources2"
						cd $dirwithsources2/.
						echo "tar c -I gzip --exclude-vcs --exclude-from=$scriptdir/git_update_sources.exclude -f /tmp/cache$dirwithsources2.tgz ."
						#cp -r $dirwithsources2/. $targetdirwithsources2
						tar c -I gzip --exclude-vcs --exclude-from=$scriptdir/git_update_sources.exclude -f /tmp/cache$dirwithsources2.tgz .
					fi 

					if [ ! -f "/tmp/cache$dirwithsources2.tgz" ]; then
						# If cache does not exists. Should not happen
                        echo "Warning: Both remote and local cache does not exists. Should not happen."
                        echo "cp -r  $dirwithsources2/. $targetdirwithsources2"
                        cp -r  $dirwithsources2/. $targetdirwithsources2
					else 
						# If cache exists.
                        echo "Local cache found. We uncompress it."
                        echo "tar -xzf /tmp/cache$dirwithsources2.tgz --directory $targetdirwithsources2/"
                        tar -xzf /tmp/cache$dirwithsources2.tgz --directory $targetdirwithsources2/
                    fi
				fi
			fi
		fi
	fi
	echo `date +'%Y-%m-%d %H:%M:%S'`" Check dirwithsources3=$dirwithsources3 targetdirwithsources3=$targetdirwithsources3"
	if [ -d $dirwithsources3 ]; then
		if [[ "x$targetdirwithsources3" != "x" ]]; then
			mkdir -p $targetdirwithsources3
			if [[ -f $dirwithsources3.tar.zst ]]; then
				echo "Remote zst cache found. We use it with: tar -I zstd -xf $dirwithsources3.tar.zst --directory $targetdirwithsources3/"
				tar -I zstd -xzf $dirwithsources3.tar.zst --directory $targetdirwithsources3/
			else
				if [ -f $dirwithsources3.tgz ]; then
					echo "Remote tgz cache found. We use it with: tar -xzf $dirwithsources3.tgz --directory $targetdirwithsources3/"
					tar -xzf $dirwithsources3.tgz --directory $targetdirwithsources3/
				else
					datesource=`date -r $dirwithsources3 +"%Y%m%d"`
					if [ -f "/tmp/cache$dirwithsources3.tgz" ]; then
						# compare date of file with date of source dir
						datecache=`date -r /tmp/cache$dirwithsources3.tgz +"%Y%m%d"`
					else 
						datecache=0
					fi
					echo "datesource=$datesource datecache=$datecache"

					if [ ! -f "/tmp/cache$dirwithsources3.tgz" -o $datesource -gt $datecache ]; then
						echo "Remote cache does not exists. Local cache does not exists or is too old, we recreate local cache"
						mkdir -p "/tmp/cache$dirwithsources3"
						#echo "cp -r $dirwithsources3/. /tmp/cache$dirwithsources3"
						cd $dirwithsources3/.
						echo "tar c -I gzip --exclude-vcs --exclude-from=$scriptdir/git_update_sources.exclude -f /tmp/cache$dirwithsources3.tgz ."
						#cp -r $dirwithsources3/. $targetdirwithsources3
						tar c -I gzip --exclude-vcs --exclude-from=$scriptdir/git_update_sources.exclude -f /tmp/cache$dirwithsources3.tgz .
					fi 

					if [ ! -f "/tmp/cache$dirwithsources3.tgz" ]; then
						# If cache does not exists. Should not happen
                        echo "Warning: Both remote and local cache does not exists. Should not happen."
                        echo "cp -r  $dirwithsources3/. $targetdirwithsources3"
                        cp -r  $dirwithsources3/. $targetdirwithsources3
					else 
						# If cache exists.
                        echo "Local cache found. We uncompress it."
                        echo "tar -xzf /tmp/cache$dirwithsources3.tgz --directory $targetdirwithsources3/"
                        tar -xzf /tmp/cache$dirwithsources3.tgz --directory $targetdirwithsources3/
                    fi
				fi
			fi
		fi
	fi

	echo `date +'%Y-%m-%d %H:%M:%S'`" Force permissions and owner on $targetdir/$osusername/$dbname"
	echo `date +'%Y-%m-%d %H:%M:%S'`" chown -R $osusername.$osusername $targetdir/$osusername/$dbname"
	chown $osusername.$osusername $targetdir/$osusername
	chown -R $osusername.$osusername $targetdir/$osusername/$dbname
	echo `date +'%Y-%m-%d %H:%M:%S'`" chmod -R go-rwxs $targetdir/$osusername/$dbname"
	chmod -R go-rwxs $targetdir/$osusername/$dbname
fi


# Undeploy config file

if [[ "$mode" == "undeploy" || "$mode" == "undeployall" ]]; then

	echo `date +'%Y-%m-%d %H:%M:%S'`" ***** Undeploy config file $targetfileforconfig1"

	if [[ -s $targetfileforconfig1 ]]; then
		echo rm -f $targetfileforconfig1.undeployed 2>/dev/null
		echo mv $targetfileforconfig1 $targetfileforconfig1.undeployed
		if [[ $testorconfirm == "confirm" ]]
		then
			rm -f $targetfileforconfig1.undeployed 2>/dev/null
			mv $targetfileforconfig1 $targetfileforconfig1.undeployed
		fi
	else
		echo File $targetfileforconfig1 was already removed/archived
	fi		
fi


# Undeploy/Archive files

if [[ "$mode" == "undeploy" || "$mode" == "undeployall" ]]; then

	echo `date +'%Y-%m-%d %H:%M:%S'`" ***** Undeploy files that are into $targetdir/$osusername/$dbname ispaidinstance = $ispaidinstance archivedir = $archivedir"
			
	# If the dir where instance was deployed still exists, we move it manually
	if [ -d $targetdir/$osusername/$dbname ]; then
		echo The dir $targetdir/$osusername/$dbname still exists, we archive it
		if [ -d $archivedir/$osusername/$dbname ]; then				# Should not happen
			echo The target archive directory $archivedir/$osusername/$dbname already exists, so we overwrite files into existing archive
			echo cp -pr $targetdir/$osusername/$dbname $archivedir/$osusername
			cp -pr $targetdir/$osusername/$dbname $archivedir/$osusername
			
			if [[ $testorconfirm == "confirm" ]]
			then
				rm -fr $targetdir/$osusername/$dbname
			fi
		else														# This is the common case of archiving after an undeploy
			echo `date +'%Y-%m-%d %H:%M:%S'`
			if [[ $testorconfirm == "confirm" ]]
			then
				mkdir $archivedir/$osusername
				mkdir $archivedir/$osusername/$dbname
				
				
				if [[ "x$ispaidinstance" == "x1" ]]; then
					if [[ "x$archivepaidinstances" == "x0" ]]; then
						if [[ -x /usr/bin/zstd && "x$usecompressformatforarchive" == "xzstd" ]]; then
							echo "Archive of test instances are disabled. We discard the tar c -I zstd --exclude-vcs -f $archivedir/$osusername/$osusername.tar.zst $targetdir/$osusername/$dbname"
						else
							echo "Archive of test instances are disabled. We discard the tar cz --exclude-vcs -f $archivedir/$osusername/$osusername.tar.gz $targetdir/$osusername/$dbname"
						fi
					else 
						if [[ -x /usr/bin/zstd && "x$usecompressformatforarchive" == "xzstd" ]]; then
							echo tar c -I zstd --exclude-vcs -f $archivedir/$osusername/$osusername.tar.zst $targetdir/$osusername/$dbname
							tar c -I zstd --exclude-vcs -f $archivedir/$osusername/$osusername.tar.zst $targetdir/$osusername/$dbname
						else
							echo tar cz --exclude-vcs -f $archivedir/$osusername/$osusername.tar.gz $targetdir/$osusername/$dbname
							tar cz --exclude-vcs -f $archivedir/$osusername/$osusername.tar.gz $targetdir/$osusername/$dbname
						fi
					fi
				else
					if [[ "x$archivetestinstances" == "x0" ]]; then
						if [[ -x /usr/bin/zstd && "x$usecompressformatforarchive" == "xzstd" ]]; then
							echo "Archive of test instances are disabled. We discard the tar c -I zstd --exclude-vcs -f $archivedir/$osusername/$osusername.tar.zst $targetdir/$osusername/$dbname"
						else
							echo "Archive of test instances are disabled. We discard the tar cz --exclude-vcs -f $archivedir/$osusername/$osusername.tar.gz $targetdir/$osusername/$dbname"
						fi
					else
						if [[ -x /usr/bin/zstd && "x$usecompressformatforarchive" == "xzstd" ]]; then
							echo tar c -I zstd --exclude-vcs -f $archivedir/$osusername/$osusername.tar.zst $targetdir/$osusername/$dbname
							tar c -I zstd --exclude-vcs -f $archivedir/$osusername/$osusername.tar.zst $targetdir/$osusername/$dbname
						else
							echo tar cz --exclude-vcs -f $archivedir/$osusername/$osusername.tar.gz $targetdir/$osusername/$dbname
							tar cz --exclude-vcs -f $archivedir/$osusername/$osusername.tar.gz $targetdir/$osusername/$dbname
						fi
					fi
				fi

				echo `date +'%Y-%m-%d %H:%M:%S'`
				echo rm -fr $targetdir/$osusername/$dbname
				rm -fr $targetdir/$osusername/$dbname
				echo `date +'%Y-%m-%d %H:%M:%S'`
				echo chown -R root $archivedir/$osusername
				chown -R root $archivedir/$osusername
				echo chmod -R o-rwx $archivedir/$osusername
				chmod -R o-rwx $archivedir/$osusername
			fi
		fi
	else
		echo The dir $targetdir/$osusername/$dbname seems already removed/archived
	fi

	# Note, we have archived the dir for instance but the dir for user and the user is still here. Will be removed by clean.sh or at end if mode = undeployall
fi


# Deploy config file

if [[ "$mode" == "deploy" || "$mode" == "deployall" ]]; then
	
	echo `date +'%Y-%m-%d %H:%M:%S'`" ***** Deploy config file"
	
	if [[ $targetfileforconfig1 == "-" ]]
	then
		echo No config file to deploy for this service
	else
		mkdir -p `dirname $targetfileforconfig1`
		
		if [[ -s $targetfileforconfig1 ]]; then
			cat $targetfileforconfig1 | grep "$dbname" 2>&1
			notfound=$?
			echo notfound=$notfound
			if [[ $notfound == 1 ]]; then
				echo File $targetfileforconfig1 already exists but content does not include database param. We recreate file.
				echo "rm $targetfileforconfig1"
				if [[ $testorconfirm == "confirm" ]]
				then
					rm -f $targetfileforconfig1
				fi
				echo "cp $fileforconfig1 $targetfileforconfig1"
				if [[ $testorconfirm == "confirm" ]]
				then
					cp $fileforconfig1 $targetfileforconfig1
				fi
			else
				echo File $targetfileforconfig1 already exists and content includes database parameters. We change nothing.
			fi
		else
			echo "cp $fileforconfig1 $targetfileforconfig1"
			if [[ $testorconfirm == "confirm" ]]
			then
				cp $fileforconfig1 $targetfileforconfig1
			fi
		fi
		chown -R $osusername.$osusername $targetfileforconfig1
		chmod -R go-rwx $targetfileforconfig1
		chmod -R g-s $targetfileforconfig1
		chmod -R a-wx $targetfileforconfig1
	fi
fi



# Create/Disable Apache virtual host

if [[ "$mode" == "deploy" || "$mode" == "deployall" ]]; then

	export apacheconf="/etc/apache2/sellyoursaas-available/$fqn.conf"
	echo `date +'%Y-%m-%d %H:%M:%S'`" ***** Create apache conf $apacheconf from $vhostfile"
	
	if [[ -s $apacheconf ]]
	then
		echo "Apache conf $apacheconf already exists, we delete it since it may be a file from an old instance with same name"
		rm -f $apacheconf
	fi

	echo "cat $vhostfile | sed -e 's/__webAppDomain__/$instancename.$domainname/g' | \
			  sed -e 's/__webAppAliases__/$instancename.$domainname/g' | \
			  sed -e 's/__webAppLogName__/$instancename/g' | \
              sed -e 's/__webSSLCertificateCRT__/$webSSLCertificateCRT/g' | \
              sed -e 's/__webSSLCertificateKEY__/$webSSLCertificateKEY/g' | \
              sed -e 's/__webSSLCertificateIntermediate__/$webSSLCertificateIntermediate/g' | \
			  sed -e 's/__webAdminEmail__/$EMAILFROM/g' | \
			  sed -e 's/__osUsername__/$osusername/g' | \
			  sed -e 's/__osGroupname__/$osusername/g' | \
			  sed -e 's;__osUserPath__;$targetdir/$osusername/$dbname;g' | \
			  sed -e 's;__VirtualHostHead__;$VIRTUALHOSTHEAD;g' | \
			  sed -e 's;__AllowOverride__;$ALLOWOVERRIDE;g' | \
			  sed -e 's;__IncludeFromContract__;$INCLUDEFROMCONTRACT;g' | \
			  sed -e 's;__SELLYOURSAAS_LOGIN_FOR_SUPPORT__;$SELLYOURSAAS_LOGIN_FOR_SUPPORT;g' | \
			  sed -e 's;#ErrorLog;$ErrorLog;g' | \
			  sed -e 's;__webMyAccount__;$SELLYOURSAAS_ACCOUNT_URL;g' | \
			  sed -e 's;__webAppPath__;$instancedir;g' > $apacheconf"
	cat $vhostfile | sed -e "s/__webAppDomain__/$instancename.$domainname/g" | \
			  sed -e "s/__webAppAliases__/$instancename.$domainname/g" | \
			  sed -e "s/__webAppLogName__/$instancename/g" | \
              sed -e "s/__webSSLCertificateCRT__/$webSSLCertificateCRT/g" | \
              sed -e "s/__webSSLCertificateKEY__/$webSSLCertificateKEY/g" | \
              sed -e "s/__webSSLCertificateIntermediate__/$webSSLCertificateIntermediate/g" | \
			  sed -e "s/__webAdminEmail__/$EMAILFROM/g" | \
			  sed -e "s/__osUsername__/$osusername/g" | \
			  sed -e "s/__osGroupname__/$osusername/g" | \
			  sed -e "s;__osUserPath__;$targetdir/$osusername/$dbname;g" | \
			  sed -e "s;__VirtualHostHead__;$VIRTUALHOSTHEAD;g" | \
			  sed -e "s;__AllowOverride__;$ALLOWOVERRIDE;g" | \
			  sed -e "s;__IncludeFromContract__;$INCLUDEFROMCONTRACT;g" | \
			  sed -e "s;__SELLYOURSAAS_LOGIN_FOR_SUPPORT__;$SELLYOURSAAS_LOGIN_FOR_SUPPORT;g" | \
			  sed -e "s;#ErrorLog;$ErrorLog;g" | \
			  sed -e "s;__webMyAccount__;$SELLYOURSAAS_ACCOUNT_URL;g" | \
			  sed -e "s;__webAppPath__;$instancedir;g" > $apacheconf


	# Enable conf with ln
	echo Enable conf with ln -fs /etc/apache2/sellyoursaas-available/$fqn.conf /etc/apache2/sellyoursaas-online 
	ln -fs /etc/apache2/sellyoursaas-available/$fqn.conf /etc/apache2/sellyoursaas-online
	
	# Remove and recreate customurl
	rm -f /etc/apache2/sellyoursaas-available/$fqn.custom.conf
	rm -f /etc/apache2/sellyoursaas-online/$fqn.custom.conf
	if [[ "x$customurl" != "x" ]]; then
	
		echo `date +'%Y-%m-%d %H:%M:%S'`" ***** Create apache conf $apacheconf from $vhostfile"

		export pathforcertifmaster="/home/admin/wwwroot/dolibarr_documents/sellyoursaas/crt"
		export pathforcertiflocal="/home/admin/wwwroot/dolibarr_documents/sellyoursaas_local/crt"

		# Delete old custom conf file
		export apacheconf="/etc/apache2/sellyoursaas-available/$fqn.custom.conf"
		if [[ -s $apacheconf ]]
		then
			echo `date +'%Y-%m-%d %H:%M:%S'`" Apache conf $apacheconf already exists, we delete it since it may be a file from an old instance with same name"
			rm -f $apacheconf
		fi

		echo `date +'%Y-%m-%d %H:%M:%S'`" Check that SSL files for $fqn.custom exists and create link to generic certificate files if not"
		if [[ "x$CERTIFFORCUSTOMDOMAIN" != "x" ]]; then
			mkdir -p $pathforcertiflocal

			# If a name for a custom CERTIF stored on master was forced, we use this one as SSL certiticate
			export webCustomSSLCertificateCRT=$CERTIFFORCUSTOMDOMAIN.crt
			export webCustomSSLCertificateKEY=$CERTIFFORCUSTOMDOMAIN.key
			export webCustomSSLCertificateIntermediate=$CERTIFFORCUSTOMDOMAIN-intermediate.crt
		
			if [[ ! -e $pathforcertiflocal/$webCustomSSLCertificateCRT ]]; then
				# If file or link does not exist
				echo `date +'%Y-%m-%d %H:%M:%S'`" cp -pn $pathforcertifmaster/$webCustomSSLCertificateCRT to $pathforcertiflocal/$webCustomSSLCertificateCRT"
				cp -pn $pathforcertifmaster/$webCustomSSLCertificateCRT $pathforcertiflocal/$webCustomSSLCertificateCRT
				# It is better to link to a bad certificate than linking to non existing file, so
				if [[ ! -e $pathforcertiflocal/$webCustomSSLCertificateCRT ]]; then
					echo "Previous cp not valid, so we create it from /etc/apache2/$webSSLCertificateCRT"
					echo "ln -fs /etc/apache2/$webSSLCertificateCRT $pathforcertiflocal/$webCustomSSLCertificateCRT"
					ln -fs /etc/apache2/$webSSLCertificateCRT $pathforcertiflocal/$webCustomSSLCertificateCRT
				fi
			fi
			if [[ ! -e $pathforcertiflocal/$webCustomSSLCertificateKEY ]]; then
				# If file or link does not exist
				echo `date +'%Y-%m-%d %H:%M:%S'`" cp -pn $pathforcertifmaster/$webCustomSSLCertificateKEY to $pathforcertiflocal/$webCustomSSLCertificateKEY"
				cp -pn $pathforcertifmaster/$webCustomSSLCertificateKEY $pathforcertiflocal/$webCustomSSLCertificateKEY
				# It is better to link to a bad certificate than linking to non existing file, so
				if [[ ! -e $pathforcertiflocal/$webCustomSSLCertificateKEY ]]; then
					echo "Previous cp not valid, so we create it from /etc/apache2/$webSSLCertificateKEY"
					echo "ln -fs /etc/apache2/$webSSLCertificateKEY $pathforcertiflocal/$webCustomSSLCertificateKEY"
					ln -fs /etc/apache2/$webSSLCertificateKEY $pathforcertiflocal/$webCustomSSLCertificateKEY
				fi
			fi
			if [[ ! -e $pathforcertiflocal/$webCustomSSLCertificateIntermediate ]]; then
				# If file or link does not exist
				echo `date +'%Y-%m-%d %H:%M:%S'`" cp -pn $pathforcertifmaster/$webCustomSSLCertificateIntermediate to $pathforcertiflocal/$webCustomSSLCertificateIntermediate"
				cp -pn $pathforcertifmaster/$webCustomSSLCertificateIntermediate $pathforcertiflocal/$webCustomSSLCertificateIntermediate
				# It is better to link to a bad certificate than linking to non existing file, so
				if [[ ! -e $pathforcertiflocal/$webCustomSSLCertificateIntermediate ]]; then
					echo "Previous cp not valid, so we recreate it from /etc/apache2/$webSSLCertificateIntermediate"
					echo "ln -fs /etc/apache2/$webSSLCertificateIntermediate $pathforcertiflocal/$webCustomSSLCertificateIntermediate"
					ln -fs /etc/apache2/$webSSLCertificateIntermediate $pathforcertiflocal/$webCustomSSLCertificateIntermediate
				fi
			fi
			
			echo `date +'%Y-%m-%d %H:%M:%S'`" chown -R admin.www-data $pathforcertiflocal/"
			chown -R admin.www-data $pathforcertiflocal/

			# Create also the link in /etc/apache2 for the case we use old virtual host using this file
			if [ -e $pathforcertiflocal/$webCustomSSLCertificateCRT -a ! -e /etc/apache2/$webCustomSSLCertificateCRT ]; then
				echo `date +'%Y-%m-%d %H:%M:%S'`" ln -fs $pathforcertiflocal/$webCustomSSLCertificateCRT /etc/apache2/$webCustomSSLCertificateCRT"
				ln -fs $pathforcertiflocal/$webCustomSSLCertificateCRT /etc/apache2/$webCustomSSLCertificateCRT
			fi
			if [ -e $pathforcertiflocal/$webCustomSSLCertificateKEY -a ! -e /etc/apache2/$webCustomSSLCertificateKEY ]; then
				echo `date +'%Y-%m-%d %H:%M:%S'`" ln -fs $pathforcertiflocal/$webCustomSSLCertificateKEY /etc/apache2/$webCustomSSLCertificateKEY"
				ln -fs $pathforcertiflocal/$webCustomSSLCertificateKEY /etc/apache2/$webCustomSSLCertificateKEY
			fi
			if [ -e $pathforcertiflocal/$webCustomSSLCertificateIntermediate -a ! -e /etc/apache2/$webCustomSSLCertificateIntermediate ]; then
				echo `date +'%Y-%m-%d %H:%M:%S'`" ln -fs $pathforcertiflocal/$webCustomSSLCertificateIntermediate /etc/apache2/$webCustomSSLCertificateIntermediate"
				ln -fs $pathforcertiflocal/$webCustomSSLCertificateIntermediate /etc/apache2/$webCustomSSLCertificateIntermediate
			fi
		else 
			# No $CERTIFFORCUSTOMDOMAIN forced (no cert file was created initially), so we will generate one
			export domainnameorcustomurl=`echo $customurl | cut -d "." -f 1`
			# We must create it using letsencrypt if not yet created
			#if [[ ! -e /home/admin/wwwroot/dolibarr_documents/sellyoursaas_local/crt/$fqn.crt ]]; then
					# Generate the letsencrypt certificate
					
					# certbot certonly --webroot -w $instancedir -d $customurl 
					# create links					

					# If links does not exists, we disable SSL
					#SSLON="Off"
			#fi
			
			export webCustomSSLCertificateCRT=$webSSLCertificateCRT
			export webCustomSSLCertificateKEY=$webSSLCertificateKEY
			export webCustomSSLCertificateIntermediate=$webSSLCertificateIntermediate
			export CERTIFFORCUSTOMDOMAIN="with.sellyoursaas.com"
		fi

		# If the certificate file is not found, we disable SSL
		if [[ ! -e /etc/apache2/$webCustomSSLCertificateCRT ]]; then
			SSLON="Off"
		else
			SSLON="On"
		fi

		echo "cat $vhostfile | sed -e 's/__webAppDomain__/$customurl/g' | \
				  sed -e 's/__webAppAliases__/$customurl/g' | \
				  sed -e 's/__webAppLogName__/$instancename/g' | \
                  sed -e 's/__webSSLCertificateCRT__/$webCustomSSLCertificateCRT/g' | \
                  sed -e 's/__webSSLCertificateKEY__/$webCustomSSLCertificateKEY/g' | \
                  sed -e 's/__webSSLCertificateIntermediate__/$webCustomSSLCertificateIntermediate/g' | \
				  sed -e 's/__webAdminEmail__/$EMAILFROM/g' | \
				  sed -e 's/__osUsername__/$osusername/g' | \
				  sed -e 's/__osGroupname__/$osusername/g' | \
				  sed -e 's;__osUserPath__;$targetdir/$osusername/$dbname;g' | \
				  sed -e 's;__VirtualHostHead__;$VIRTUALHOSTHEAD;g' | \
				  sed -e 's;__AllowOverride__;$ALLOWOVERRIDE;g' | \
				  sed -e 's;__IncludeFromContract__;$INCLUDEFROMCONTRACT;g' | \
				  sed -e 's;__SELLYOURSAAS_LOGIN_FOR_SUPPORT__;$SELLYOURSAAS_LOGIN_FOR_SUPPORT;g' | \
				  sed -e 's;#ErrorLog;$ErrorLog;g' | \
				  sed -e 's;__webMyAccount__;$SELLYOURSAAS_ACCOUNT_URL;g' | \
				  sed -e 's;__webAppPath__;$instancedir;g' | \
				  sed -e 's/with\.sellyoursaas\.com/$CERTIFFORCUSTOMDOMAIN/g' > $apacheconf"
		cat $vhostfile | sed -e "s/__webAppDomain__/$customurl/g" | \
				  sed -e "s/__webAppAliases__/$customurl/g" | \
				  sed -e "s/__webAppLogName__/$instancename/g" | \
                  sed -e "s/__webSSLCertificateCRT__/$webCustomSSLCertificateCRT/g" | \
                  sed -e "s/__webSSLCertificateKEY__/$webCustomSSLCertificateKEY/g" | \
                  sed -e "s/__webSSLCertificateIntermediate__/$webCustomSSLCertificateIntermediate/g" | \
				  sed -e "s/__webAdminEmail__/$EMAILFROM/g" | \
				  sed -e "s/__osUsername__/$osusername/g" | \
				  sed -e "s/__osGroupname__/$osusername/g" | \
				  sed -e "s;__osUserPath__;$targetdir/$osusername/$dbname;g" | \
				  sed -e "s;__VirtualHostHead__;$VIRTUALHOSTHEAD;g" | \
				  sed -e "s;__AllowOverride__;$ALLOWOVERRIDE;g" | \
				  sed -e "s;__IncludeFromContract__;$INCLUDEFROMCONTRACT;g" | \
				  sed -e "s;__SELLYOURSAAS_LOGIN_FOR_SUPPORT__;$SELLYOURSAAS_LOGIN_FOR_SUPPORT;g" | \
				  sed -e "s;#ErrorLog;$ErrorLog;g" | \
				  sed -e "s;__webMyAccount__;$SELLYOURSAAS_ACCOUNT_URL;g" | \
				  sed -e "s;__webAppPath__;$instancedir;g" | \
				  sed -e "s/with\.sellyoursaas\.com/$CERTIFFORCUSTOMDOMAIN/g" > $apacheconf


		echo Enable conf with ln -fs /etc/apache2/sellyoursaas-available/$fqn.custom.conf /etc/apache2/sellyoursaas-online 
		ln -fs /etc/apache2/sellyoursaas-available/$fqn.custom.conf /etc/apache2/sellyoursaas-online
	fi
	
	
	# Deploy also the php fpm pool file from the scripts/templates/osuxxx.conf
	# A link will also be created into /etc/php/x.x/fpm/pool.d/$fqn.conf to this fpm pool file $fqn.conf
	export phpfpmconf="/etc/apache2/sellyoursaas-fpm-pool.d/$fqn.conf"
	if [ -d /etc/apache2/sellyoursaas-fpm-pool.d ]; then
		echo `date +'%Y-%m-%d %H:%M:%S'`" ***** Create php fpm conf $phpfpmconf from $fpmpoolfiletemplate"
		if [[ -s $phpfpmconf ]]
		then
			echo "Apache conf $phpfpmconf already exists, we delete it since it may be a file from an old instance with same name"
			rm -f $phpfpmconf
		fi
	
		echo "cat $fpmpoolfiletemplate | sed -e 's/__webAppDomain__/$instancename.$domainname/g' | \
				  sed -e 's/__webAppAliases__/$instancename.$domainname/g' | \
				  sed -e 's/__webAppLogName__/$instancename/g' | \
	              sed -e 's/__webSSLCertificateCRT__/$webSSLCertificateCRT/g' | \
	              sed -e 's/__webSSLCertificateKEY__/$webSSLCertificateKEY/g' | \
	              sed -e 's/__webSSLCertificateIntermediate__/$webSSLCertificateIntermediate/g' | \
				  sed -e 's/__webAdminEmail__/$EMAILFROM/g' | \
				  sed -e 's/__osUsername__/$osusername/g' | \
				  sed -e 's/__osGroupname__/$osusername/g' | \
				  sed -e 's;__osUserPath__;$targetdir/$osusername/$dbname;g' | \
				  sed -e 's;__VirtualHostHead__;$VIRTUALHOSTHEAD;g' | \
				  sed -e 's;__AllowOverride__;$ALLOWOVERRIDE;g' | \
				  sed -e 's;__IncludeFromContract__;$INCLUDEFROMCONTRACT;g' | \
				  sed -e 's;__SELLYOURSAAS_LOGIN_FOR_SUPPORT__;$SELLYOURSAAS_LOGIN_FOR_SUPPORT;g' | \
				  sed -e 's;#ErrorLog;$ErrorLog;g' | \
				  sed -e 's;__webMyAccount__;$SELLYOURSAAS_ACCOUNT_URL;g' | \
				  sed -e 's;__webAppPath__;$instancedir;g' > $phpfpmconf"
		cat $fpmpoolfiletemplate | sed -e "s/__webAppDomain__/$instancename.$domainname/g" | \
				  sed -e "s/__webAppAliases__/$instancename.$domainname/g" | \
				  sed -e "s/__webAppLogName__/$instancename/g" | \
	              sed -e "s/__webSSLCertificateCRT__/$webSSLCertificateCRT/g" | \
	              sed -e "s/__webSSLCertificateKEY__/$webSSLCertificateKEY/g" | \
	              sed -e "s/__webSSLCertificateIntermediate__/$webSSLCertificateIntermediate/g" | \
				  sed -e "s/__webAdminEmail__/$EMAILFROM/g" | \
				  sed -e "s/__osUsername__/$osusername/g" | \
				  sed -e "s/__osGroupname__/$osusername/g" | \
				  sed -e "s;__osUserPath__;$targetdir/$osusername/$dbname;g" | \
				  sed -e "s;__VirtualHostHead__;$VIRTUALHOSTHEAD;g" | \
				  sed -e "s;__AllowOverride__;$ALLOWOVERRIDE;g" | \
				  sed -e "s;__IncludeFromContract__;$INCLUDEFROMCONTRACT;g" | \
				  sed -e "s;__SELLYOURSAAS_LOGIN_FOR_SUPPORT__;$SELLYOURSAAS_LOGIN_FOR_SUPPORT;g" | \
				  sed -e "s;#ErrorLog;$ErrorLog;g" | \
				  sed -e "s;__webMyAccount__;$SELLYOURSAAS_ACCOUNT_URL;g" | \
				  sed -e "s;__webAppPath__;$instancedir;g" > $phpfpmconf
	fi
	
	
	echo /usr/sbin/apache2ctl configtest
	/usr/sbin/apache2ctl configtest
	if [[ "x$?" != "x0" ]]; then
		echo Error when running apache2ctl configtest. We remove the new created virtual host /etc/apache2/sellyoursaas-online/$fqn...conf to hope to restore configtest ok.
		rm -f /etc/apache2/sellyoursaas-online/$fqn.conf
		rm -f /etc/apache2/sellyoursaas-online/$fqn.custom.conf
		rm -f /etc/apache2/sellyoursaas-online/$fqn.website*.conf
		echo "Failed to deployall instance $instancename.$domainname with: Error when running apache2ctl configtest" | mail -aFrom:$EMAILFROM -s "[Alert] Pb in deployment" $EMAILTO
		exit 19
	fi
	
	if [[ "x$apachereload" != "xnoapachereload" ]]; then
		echo `date +'%Y-%m-%d %H:%M:%S'`" ***** Apache tasks finished. We can launch service apache2 reload."
		service apache2 reload
		if [[ "x$?" != "x0" ]]; then
			echo Error when running service apache2 reload to deploy instance $instancename.$domainname
			echo "Failed to deployall instance $instancename.$domainname with: Error when running service apache2 reload" | mail -aFrom:$EMAILFROM -s "[Alert] Pb in deployment" $EMAILTO
			sleep 1		# add a delay after an apache reload
			exit 20
		fi
		sleep 1			# add a delay after an apache reload
	else
		echo `date +'%Y-%m-%d %H:%M:%S'`" ***** Apache tasks finished. But we do not reload apache2 now to reduce reloading."
	fi

fi

if [[ "$mode" == "undeploy" || "$mode" == "undeployall" ]]; then

	export apacheconf="/etc/apache2/sellyoursaas-online/$fqn.conf"
	echo `date +'%Y-%m-%d %H:%M:%S'`" ***** Remove apache conf $apacheconf"

	if [ -f $apacheconf ]; then
	
		echo Disable conf with a2dissite $fqn.conf
		#a2dissite $fqn.conf
		rm /etc/apache2/sellyoursaas-online/$fqn.conf

		echo Disable conf with a2dissite $fqn.custom.conf
		#a2dissite $fqn.conf
		rm /etc/apache2/sellyoursaas-online/$fqn.custom.conf

		echo Disable conf with a2dissite $fqn.website*.conf
		#a2dissite $fqn.conf
		rm /etc/apache2/sellyoursaas-online/$fqn.website*.conf

		echo Delete php fpm file $fqn.conf
		if [ -f /etc/apache2/sellyoursaas-fpm-pool.d/$fqn.conf ]; then
			rm /etc/apache2/sellyoursaas-fpm-pool.d/$fqn.conf
		fi

		/usr/sbin/apache2ctl configtest
		if [[ "x$?" != "x0" ]]; then
			echo Error when running apache2ctl configtest 
			echo "Failed to undeploy or undeployall instance $instancename.$domainname with: Error when running apache2ctl configtest" | mail -aFrom:$EMAILFROM -s "[Alert] Pb in undeployment" $EMAILTO
			exit 21
		fi

		if [[ "x$apachereload" != "xnoapachereload" ]]; then
			echo `date +'%Y-%m-%d %H:%M:%S'`" ***** Apache tasks finished. service apache2 reload."
			service apache2 reload
			if [[ "x$?" != "x0" ]]; then
				echo Error when running service apache2 reload to undeploy instance $instancename.$domainname
				echo "Failed to undeploy or undeployall instance $instancename.$domainname with: Error when running service apache2 reload" | mail -aFrom:$EMAILFROM -s "[Alert] Pb in undeployment" $EMAILTO
				#sleep 1   	# no delay added for undeployment
				exit 24
			fi
			#sleep 1		# no delay added for undeployment
		else
			echo `date +'%Y-%m-%d %H:%M:%S'`" ***** Apache tasks finished. But we do not reload apache2 now to reduce reloading."
		fi
	else
		echo "Virtual host $apacheconf seems already disabled"
	fi
fi



# Install/Uninstall cron

if [[ "$mode" == "deploy" || "$mode" == "deployall" ]]; then

	echo `date +'%Y-%m-%d %H:%M:%S'`" ***** Install cron file $cronfile"
	
	if [[ -s $cronfile ]]
	then
		if [[ -f /var/spool/cron/crontabs/$osusername ]]; then
			echo merge existing $cronfile with existing /var/spool/cron/crontabs/$osusername
			# We remove the line that contains the dbname, TZ and comment into the tmp file
			echo "cat /var/spool/cron/crontabs/$osusername | grep -v $dbname | grep -v 'TZ=' | grep -v '^#' > /tmp/$dbname.tmp"
			cat /var/spool/cron/crontabs/$osusername | grep -v "$dbname" | grep -v "TZ=" | grep -v "^#" > /tmp/$dbname.tmp
			# Now we add the lines to use for this instance into the tmp file
			echo "cat $cronfile >> /tmp/$dbname.tmp"
			cat $cronfile >> /tmp/$dbname.tmp
			# Then we add an empty line (otherwise the last line is ignored)
			#echo "echo >> /tmp/$dbname.tmp"
			#echo >> /tmp/$dbname.tmp
			echo cp /tmp/$dbname.tmp /var/spool/cron/crontabs/$osusername
			cp /tmp/$dbname.tmp /var/spool/cron/crontabs/$osusername
			echo rm -f /tmp/$dbname.tmp
			rm -f /tmp/$dbname.tmp
		else
			echo cron file /var/spool/cron/crontabs/$osusername does not exists yet
			echo cp $cronfile /var/spool/cron/crontabs/$osusername
			cp $cronfile /var/spool/cron/crontabs/$osusername
		fi
	
		chown $osusername.$osusername /var/spool/cron/crontabs/$osusername
		chmod 600 /var/spool/cron/crontabs/$osusername
	else
		echo There is no cron file to install
	fi
fi

if [[ "$mode" == "undeploy" || "$mode" == "undeployall" ]]; then

	echo `date +'%Y-%m-%d %H:%M:%S'`" ***** Remove cron file /var/spool/cron/crontabs/$osusername"
	if [ -s /var/spool/cron/crontabs/$osusername ]; then
		mkdir -p /var/spool/cron/crontabs.disabled
		rm -f /var/spool/cron/crontabs.disabled/$osusername
		echo cp /var/spool/cron/crontabs/$osusername /var/spool/cron/crontabs.disabled/$osusername
		cp /var/spool/cron/crontabs/$osusername /var/spool/cron/crontabs.disabled/$osusername

		# Remove the cron file
		echo rm -f /var/spool/cron/crontabs/$osusername
		rm -f /var/spool/cron/crontabs/$osusername
	else
		echo cron file /var/spool/cron/crontabs/$osusername already removed or empty
	fi 
fi
if [[ "$mode" == "undeployall" ]]; then

	echo rm -f /var/spool/cron/crontabs.disabled/$osusername
	rm -f /var/spool/cron/crontabs.disabled/$osusername 

fi


# Create database (last step, the longer one)

if [[ "$mode" == "deploy" || "$mode" == "deployall" ]]; then

	echo `date +'%Y-%m-%d %H:%M:%S'`" ***** Create database $dbname for user $dbusername"
	
	Q1="CREATE DATABASE IF NOT EXISTS $dbname; "
	#Q2="CREATE USER IF NOT EXISTS '$dbusername'@'localhost' IDENTIFIED BY '$dbpassword'; "
	Q2="CREATE USER '$dbusername'@'localhost' IDENTIFIED BY '$dbpassword'; "
	SQL="${Q1}${Q2}"
	echo "$MYSQL -A -h $dbserverhost -P $dbserverport -u$dbadminuser -pXXXXXX -e \"$SQL\""
	$MYSQL -A -h $dbserverhost -P $dbserverport -u$dbadminuser -p$dbadminpass -e "$SQL"
	
	Q1="CREATE DATABASE IF NOT EXISTS $dbname; "
	#Q2="CREATE USER IF NOT EXISTS '$dbusername'@'%' IDENTIFIED BY '$dbpassword'; "
	Q2="CREATE USER '$dbusername'@'%' IDENTIFIED BY '$dbpassword'; "
	SQL="${Q1}${Q2}"
	echo "$MYSQL -A -h $dbserverhost -P $dbserverport -u$dbadminuser -pXXXXXX -e \"$SQL\""
	$MYSQL -A -h $dbserverhost -P $dbserverport -u$dbadminuser -p$dbadminpass -e "$SQL"
	
	Q1="GRANT CREATE,CREATE TEMPORARY TABLES,CREATE VIEW,DROP,DELETE,INSERT,SELECT,UPDATE,ALTER,INDEX,LOCK TABLES,REFERENCES,SHOW VIEW ON $dbname.* TO '$dbusername'@'localhost'; "
	Q2="GRANT CREATE,CREATE TEMPORARY TABLES,CREATE VIEW,DROP,DELETE,INSERT,SELECT,UPDATE,ALTER,INDEX,LOCK TABLES,REFERENCES,SHOW VIEW ON $dbname.* TO '$dbusername'@'%'; "
	
	if [ $dbforcesetpassword == "1" ]; then
		Q3="SET PASSWORD FOR '$dbusername' = PASSWORD('$dbpassword'); "
		Q3a="SET PASSWORD FOR '$dbusername'@'localhost' = PASSWORD('$dbpassword'); "
		Q3b="SET PASSWORD FOR '$dbusername'@'%' = PASSWORD('$dbpassword'); "
	else
		Q3="SET PASSWORD FOR '$dbusername' = PASSWORD('$dbpassword')"
		Q3a=""
		Q3b=""
		# If we use mysql and not mariadb, we set password differently
		dpkg -l | grep mariadb > /dev/null
		if [ $? == "1" ]; then
			# For mysql
			Q3="SET PASSWORD FOR '$dbusername' = PASSWORD('$dbpassword'); "
			Q3a="SET PASSWORD FOR '$dbusername'@'localhost' = PASSWORD('$dbpassword'); "
			Q3b="SET PASSWORD FOR '$dbusername'@'%' = PASSWORD('$dbpassword'); "
		fi
	fi
	
	Q4="FLUSH PRIVILEGES; "
	SQL="${Q1}${Q2}${Q3}${Q3a}${Q3b}${Q4}"
	echo "$MYSQL -A -h $dbserverhost -P $dbserverport -u$dbadminuser -pXXXXXX -e \"$SQL\""
	$MYSQL -A -h $dbserverhost -P $dbserverport -u$dbadminuser -p$dbadminpass -e "$SQL"

	echo "You can test with mysql $dbname -h $dbserverhost -P $dbserverport -u $dbusername -p$dbpassword"

	# Load dump file
	echo `date +'%Y-%m-%d %H:%M:%S'`" Search dumpfile into $dirwithdumpfile"
	for dumpfile in `ls $dirwithdumpfile/*.sql 2>/dev/null`
	do
		echo "$MYSQL -A -h $dbserverhost -P $dbserverport -u$dbadminuser -pXXXXXX -D $dbname < $dumpfile"
		$MYSQL -A -h $dbserverhost -P $dbserverport -u$dbadminuser -p$dbadminpass -D $dbname < $dumpfile
		result=$?
		if [[ "x$result" != "x0" ]]; then
			echo Failed to load dump file $dumpfile
			echo "Failed to $mode instance $instancename.$domainname with: Failed to load dump file $dumpfile" | mail -aFrom:$EMAILFROM -s "[Alert] Pb in deploy/undeploy" $EMAILTO
			exit 25
		fi
	done

fi


# Drop database

if [[ "$mode" == "undeploy" || "$mode" == "undeployall" ]]; then

	echo `date +'%Y-%m-%d %H:%M:%S'`" ***** Archive and dump database $dbname in $archivedir/$osusername"

	echo "Do a dump of database $dbname - may fails if already removed"
	mkdir -p $archivedir/$osusername
	if [[ -x /usr/bin/zstd && "x$usecompressformatforarchive" == "xzstd" ]]; then
		echo "$MYSQLDUMP --no-tablespaces -h $dbserverhost -P $dbserverport -u$dbadminuser -pXXXXXX $dbname | zstd -z -9 -q > $archivedir/$osusername/dump.$dbname.$nowlog.sql.zst"
		$MYSQLDUMP --no-tablespaces -h $dbserverhost -P $dbserverport -u$dbadminuser -p$dbadminpass $dbname | zstd -z -9 -q > "$archivedir/$osusername/dump.$dbname.$nowlog.sql.zst"
	else
		echo "$MYSQLDUMP --no-tablespaces -h $dbserverhost -P $dbserverport -u$dbadminuser -pXXXXXX $dbname | gzip > $archivedir/$osusername/dump.$dbname.$nowlog.sql.gz"
		$MYSQLDUMP --no-tablespaces -h $dbserverhost -P $dbserverport -u$dbadminuser -p$dbadminpass $dbname | gzip > "$archivedir/$osusername/dump.$dbname.$nowlog.sql.gz"
	fi

	if [[ "x$?" == "x0" ]]; then
		echo "Now drop the database and user"
		echo "echo \"DROP DATABASE $dbname;\" | $MYSQL -h $dbserverhost -P $dbserverport -u$dbadminuser -pXXXXXX $dbname"
		echo "echo \"DROP USER '$dbusername'@'%';\" | $MYSQL -h $dbserverhost -P $dbserverport -u$dbadminuser -pXXXXXX"
		echo "echo \"DROP USER '$dbusername'@'localhost';\" | $MYSQL -h $dbserverhost -P $dbserverport -u$dbadminuser -pXXXXXX"
		if [[ $testorconfirm == "confirm" ]]; then
			echo "DROP DATABASE $dbname;" | $MYSQL -h $dbserverhost -P $dbserverport -u$dbadminuser -p$dbadminpass $dbname
			echo "DROP USER '$dbusername'@'%';" | $MYSQL -h $dbserverhost -P $dbserverport -u$dbadminuser -p$dbadminpass
			echo "DROP USER '$dbusername'@'localhost';" | $MYSQL -h $dbserverhost -P $dbserverport -u$dbadminuser -p$dbadminpass
		fi
	else
		echo "ERROR in dumping database, so we don't try to drop it"	
	fi
fi


# Delete os directory and user + group

if [[ "$mode" == "undeployall" ]]; then
	
	echo `date +'%Y-%m-%d %H:%M:%S'`" ***** Delete user $osusername with home into $targetdir/$osusername and archive it into $archivedir"

	echo crontab -r -u $osusername
	crontab -r -u $osusername
	
	# Note: When we do this the home dir of $osusername was already archived by code few lines previously
	echo deluser --remove-home --backup --backup-to $archivedir/$osusername $osusername
	if [[ $testorconfirm == "confirm" ]]
	then
		deluser --remove-home --backup --backup-to $archivedir/$osusername $osusername
		chmod -R ug+r $archivedir/$osusername/*.bz2
	fi
	
	echo deluser --group $osusername
	if [[ $testorconfirm == "confirm" ]]
	then
		deluser --group $osusername
	fi

fi


# Execute after CLI

if [[ "$mode" == "deploy" || "$mode" == "deployall" || "$mode" == "deployoption" ]]; then
	if [[ "x$cliafter" != "x" ]]; then
		if [ -f $cliafter ]; then
			echo `date +'%Y-%m-%d %H:%M:%S'`" Execute script with . $cliafter"
			. $cliafter
			if [[ "x$?" != "x0" ]]; then
				echo Error when running the CLI script $cliafter 
				echo "Error when running the CLI script $cliafter" | mail -aFrom:$EMAILFROM -s "[Alert] Pb in deployment" $EMAILTO
				exit 26
			fi
		fi
	fi
fi


if [[ "$mode" == "undeploy" ]]; then
	echo "$mode $instancename.$domainname" >> $targetdir/$osusername/$mode-$instancename.$domainname.txt
	echo "$mode $instancename.$domainname" >> $archivedir/$osusername/$mode-$instancename.$domainname.txt
fi
if [[ "$mode" == "undeployall" ]]; then
	echo "$mode $instancename.$domainname" >> $archivedir/$osusername/$mode-$instancename.$domainname.txt
fi


#if ! grep test_$i /etc/hosts >/dev/null; then
#	echo Add name test_$i into /etc/hosts
#	echo 127.0.0.1 test_$i >> /etc/hosts
#fi

echo `date +'%Y-%m-%d %H:%M:%S'`" Process of action $mode of $instancename.$domainname for user $osusername finished with no error"
echo `date +'%Y-%m-%d %H:%M:%S'`" return 0" 
echo

exit 0
