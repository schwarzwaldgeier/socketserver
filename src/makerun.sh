#!/bin/bash
#make-run.sh
#make sure a process is always running.

export DISPLAY=:0 #needed if you are running a simple gui app.

process="/usr/local/bin/wetterstation_socket/src/run_wetter_socket_server.php"
makerun="/usr/bin/php /usr/local/bin/wetterstation_socket/src/run_wetter_socket_server.php 192.168.111.11 7977"

if ps ax | grep -v grep | grep $process > /dev/null
then
    exit
else
    $makerun &
fi

exit