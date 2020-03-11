#!/bin/sh

COMMAND="sudo -u pdb ./create-finkdb.pl"
if [ `/usr/bin/id -un` != "root" ]; then
	echo "you must run this script as root!"
	exit 1
fi
if [ -x "/usr/bin/ionice" ]; then
	COMMAND="/usr/bin/ionice -c3 $COMMAND"
fi

for dist in \
	10.15-x86_64-current-stable \
	10.14.5-x86_64-current-stable \
	10.14-x86_64-current-stable \
	10.13-x86_64-current-stable \
	10.12-x86_64-current-stable \
	10.11-x86_64-current-stable \
	10.10-x86_64-current-stable \
	10.9-x86_64-current-stable \
	10.8-x86_64-current-stable \
	10.7-x86_64-current-stable \
	10.6-x86_64-current-unstable \
	10.6-x86_64-current-stable \
	10.6-i386-current-unstable \
	10.6-i386-current-stable \
	10.5-i386-current-unstable \
	10.5-i386-current-stable \
	10.5-powerpc-current-unstable \
	10.5-powerpc-current-stable \
	; do
	$COMMAND --start-at $dist --end-at $dist "$@"
done
