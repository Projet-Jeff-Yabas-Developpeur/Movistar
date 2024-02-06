#! /bin/bash
sudo killall -9 -u wvtohls nginx
sudo killall -9 -u wvtohls php-fpm8.0
sudo -u wvtohls /home/wvtohls/nginx/sbin/nginx

sudo -u wvtohls start-stop-daemon --start --quiet --pidfile /home/wvtohls/php/daemon.pid --exec /usr/sbin/php-fpm8.0 -- --daemonize --fpm-config /home/wvtohls/php/etc/daemon.conf