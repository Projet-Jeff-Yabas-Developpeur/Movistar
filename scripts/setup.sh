adduser --system --shell /bin/false --group --disabled-login wvtohls
usermod -aG sudo wvtohls
chown -R wvtohls:wvtohls /home/wvtohls

aptitude install aria2
apt-get -y install libxslt1-dev nscd htop libonig-dev libzip-dev software-properties-common aria2 nload
add-apt-repository ppa:xapienz/curl34 -y
apt-get update
apt-get install libcurl4 curl
# instalar ioncube loader
# wget https://downloads.ioncube.com/loader_downloads/ioncube_loaders_lin_x86-64.tar.gz
# tar xzf ioncube_loaders_lin_x86-64.tar.gz

Buscar el direcorio extension_dir
php-fpm7.4 -i | grep extension
  ejemplo:
  extension_dir => /usr/lib/php/20190902
cp ioncube_loader_lin_7.4.so /usr/lib/php/20190902/ioncube_loader_lin_7.4.3.so
bash -c 'echo "zend_extension= /usr/lib/php/20190902/ioncube_loader_lin_7.4.3.so" > /etc/php/7.4/fpm/conf.d/00-ioncube.ini'

php7.4 -i | grep extension
  ejemplo:
  extension_dir => /usr/lib/php/20190902
bash -c 'echo "zend_extension=/usr/lib/php/20190902/ioncube_loader_lin_7.4.3.so" > /etc/php/7.4/cli/conf.d/00-ioncube.ini'

dpkg -i /tmp/libpng12.deb
apt-get install -y
rm /tmp/libpng12.deb
chmod +x /home/wvtohls/bin/*
chmod +x /home/wvtohls/nginx/sbin/nginx
ufw allow 49214
ufw allow 57127
#ufw allow 18000
#ufw allow 18001

touch /var/log/php-fpm.log
chown root:wvtohls /var/log/php-fpm.log
chmod 664 /var/log/php-fpm.log
aptitude install php7.2-xml

to start:
sudo su
cd /home/wvtohls
scripts/start.sh

to stop everything:
sudo su
cd /home/wvtohls
scripts/stop.sh
