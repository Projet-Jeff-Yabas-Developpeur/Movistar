#!/bin/bash
sudo chown -R wvtohls:wvtohls /home/wvtohls
sudo killall -9 -u wvtohls nginx
sudo killall -9 -u wvtohls php-fpm7.4
sudo killall -9 -u wvtohls ffmpeg
sudo killall -9 -u wvtohls php
sleep 2
sudo rm -rf /home/wvtohls/video/*
sudo rm -rf /home/wvtohls/hls/*
sudo rm -f /home/wvtohls/cache/*.db
sudo rm -f /home/wvtohls/php/daemon.sock
sudo -u wvtohls /home/wvtohls/nginx/sbin/nginx

sudo -u wvtohls start-stop-daemon --start --quiet --pidfile /home/wvtohls/php/daemon.pid --exec /usr/sbin/php-fpm7.4 -- --daemonize --fpm-config /home/wvtohls/php/etc/daemon.conf
php /home/wvtohls/monitor.php

