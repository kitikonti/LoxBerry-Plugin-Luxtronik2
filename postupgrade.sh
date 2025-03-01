#!/bin/sh

ARGV0=$0 # Zero argument is shell command
ARGV1=$1 # First argument is temp folder during install
ARGV2=$2 # Second argument is Plugin-Name for scipts etc.
ARGV3=$3 # Third argument is Plugin installation folder
ARGV4=$4 # Forth argument is Plugin version
ARGV5=$5 # Fifth argument is Base folder of LoxBerry

echo "<INFO> Copy back existing config files"
cp -p -v -r /tmp/$ARGV1\_upgrade/config/* $ARGV5/config/plugins/$ARGV3/

echo "<INFO> Remove temporary folders"
rm -r /tmp/$ARGV1\_upgrade

# Update composer packages
echo "<INFO> Update composer packages."
php $PBIN/composer.phar install --working-dir=$PBIN/fetch_heat_pump_data

# Exit with Status 0
exit 0