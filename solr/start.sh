#!/bin/sh

BASEDIR=$(dirname "$0")

cd "$BASEDIR"
( java -Xms128m -Xmx512m -XX:MaxHeapFreeRatio=80 -XX:MinHeapFreeRatio=20 -Djava.net.preferIPv4Stack=true -Djava.awt.headless=true $SOLR_OPTS -jar $BASEDIR/start.jar 2>&1 | logger -t solr ) &
