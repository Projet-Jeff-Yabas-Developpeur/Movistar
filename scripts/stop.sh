#! /bin/bash
sudo killall -9 -u wvtohls nginx
sudo killall -9 -u wvtohls php-fpm7.4
sudo killall -9 -u wvtohls ffmpeg
sudo killall -9 -u wvtohls php
sudo killall -9 -u wvtohls aria2c
sudo rm -rf /home/wvtohls/video/*
sudo rm -rf /home/wvtohls/hls/*
sudo rm -f /home/wvtohls/cache/*.db
sudo rm -f /home/wvtohls/php/daemon.sock
