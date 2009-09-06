#!/bin/sh

if [ -z "$JAVA_HOME" ]; then
	for dir in \
			/System/Library/Frameworks/JavaVM.framework/Versions/1.6*/Home \
			/System/Library/Frameworks/JavaVM.framework/Versions/1.5*/Home \
			/opt/jdk1.6* \
			/opt/jdk1.5* \
			/usr/java/jdk1.6* \
			/usr/java/jdk1.5* \
			/usr/lib/jvm/java-6-sun \
			/usr/lib/jvm/java-1.6*-sun \
			/usr/lib/jvm/java-1.5*-sun \
			; do
		if [ -x "$dir/bin/java" ]; then
			export JAVA_HOME="$dir"
			break;
		fi
	done
fi

MYDIR=`dirname "$0"`
TOPDIR=`cd $MYDIR; pwd`

cd "$TOPDIR"
#echo "JAVA_HOME=$JAVA_HOME, TOPDIR=$TOPDIR"
exec $JAVA_HOME/bin/java -Xmx512m $SOLR_OPTS -jar $TOPDIR/start.jar >/dev/null 2>&1 &
