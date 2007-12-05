#!/bin/sh

COMMAND="./create-finkdb.pl"
if [ `/usr/bin/id -un` != "root" ]; then
	echo "you must run this script as root!"
	exit 1
fi
if [ -x "/usr/bin/ionice" ]; then
	COMMAND="/usr/bin/ionice -c3 $COMMAND"
fi

for dist in \
	10.3-powerpc-current-stable \
	10.3-powerpc-current-unstable \
	10.4-powerpc-current-stable \
	10.4-powerpc-current-unstable \
	10.4-i386-current-stable \
	10.4-i386-current-unstable \
	10.5-powerpc-current-stable \
	10.5-powerpc-current-unstable \
	10.5-i386-current-stable \
	10.5-i386-current-unstable \
	; do
	$COMMAND --start-at $dist --end-at $dist "$@"
done
