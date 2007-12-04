#!/bin/sh

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
	./create-finkdb.pl --start-at $dist --end-at $dist "$@"
done
