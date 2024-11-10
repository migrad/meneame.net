#!/bin/bash
# -*- ENCODING: UTF-8 -*-

## Antiguo script de instalación: https://gist.github.com/cristiannsc/465a9899d28c3093821412a29ded834e

## Update the ubuntu/debian box
echo "Installing PHP...\n"
sudo apt-get update && apt-get upgrade
sudo apt-get install nginx php7.2-fpm php7.2-common php-memcache\
      php7.2-mbstring php7.2-xmlrpc php7.2-gd php7.2-xml php7.2-cgi \
      php7.2-mysql php7.2-cli php7.2-zip php7.2-curl php7.2-bcmath -y
sudo apt-get -y install gcc make autoconf libc-dev pkg-config
sudo apt-get -y install php7.2-dev
sudo apt-get -y install libmcrypt-dev
sudo pecl install mcrypt-1.0.1

echo "Installing MySQL...\n"
sudo debconf-set-selections <<< 'mysql-server-5.7 mysql-server/root_password password rootpass'
sudo debconf-set-selections <<< 'mysql-server-5.7 mysql-server/root_password_again password rootpass'
sudo apt-get install mysql-server-5.7 python-mysqldb -y

echo "Getting minimal nginx configuration file\n"
wget -O /etc/nginx/sites-available/default \
    https://gist.githubusercontent.com/cristiannsc/1d4ae9267f433a7927076c8bd79ff9e3/raw/default

wget -O /etc/nginx/php_fastcgi \
    https://gist.githubusercontent.com/cristiannsc/36c765c85daff7364e2c87639af399da/raw/php_fastcgi

wget -O /etc/nginx/fastcgi_params \
    https://gist.githubusercontent.com/cristiannsc/df341645f0cd2d8a9111f73b959e52f0/raw/fastcgi_params

sudo ln -s /etc/nginx/sites-available/default /etc/nginx/sites-enabled/

## Fixing some anoying Bug nginx + VirtualBox
## http://wiki.nginx.org/Pitfalls
## http://jeremyfelt.com/code/2013/01/08/clear-nginx-cache-in-vagrant/
sed -i 's/sendfile on;/sendfile off;/g' /etc/nginx/nginx.conf

sed -i 's/;cgi.fix_pathinfo=1/cgi.fix_pathinfo=1/g' /etc/php/7.2/fpm/php.ini

sed -i 's/;extension=shmop/;extension=shmop\nextension=mcrypt.so\nextension=memcache.so/g' /etc/php/7.2/fpm/php.ini

sudo service php7.2-fpm restart
sudo service nginx restart

echo "Configuring mySQL database\n"
echo "Creating Meneame database\n"
mysql -u root -p"rootpass" <<-CREATE_DATABASE
  CREATE DATABASE meneame;
  GRANT ALL ON meneame.* TO meneame@localhost IDENTIFIED BY 'meneame';
  GRANT ALL ON meneame.* TO meneame@'%' IDENTIFIED BY 'meneame'
CREATE_DATABASE

echo "Creating tables\n"
mysql -u meneame -p"meneame" meneame < meneame/sql/meneame.sql
mysql -u meneame -p"meneame" meneame < meneame/sql/2016-12-17-polls.sql
mysql -u meneame -p"meneame" meneame < meneame/sql/2017-02-24-strikes.sql
mysql -u meneame -p"meneame" meneame < meneame/sql/2017-04-27-links.sql
mysql -u meneame -p"meneame" meneame < meneame/sql/2017-08-04-preguntame.sql
mysql -u meneame -p"meneame" meneame < meneame/sql/2017-09-22-preguntame-patrocinado.sql
mysql -u meneame -p"meneame" meneame < meneame/sql/2017-09-26-sponsors.sql
mysql -u meneame -p"meneame" meneame < meneame/sql/2017-11-07-admin-users.sql
mysql -u meneame -p"meneame" meneame < meneame/sql/2018-09-13-backups.sql

echo "Creating initial data\n"
mysql -u meneame -p"meneame" meneame <<-INITIAL_DATA
  INSERT INTO subs (id,name,enabled,parent,server_name,base_url,name_long,visible,sub,meta,owner,nsfw,created_from,allow_main_link,color1,color2,private,show_admin,page_mode)
    VALUES ( 1, 'mnm', 1, 0, '', '/', 'Principal', 1, 0, 0, 0, 0, 0, 1, NULL, NULL, 0, 0, NULL);
  INSERT INTO sub_categories
  	SELECT LAST_INSERT_ID(), category_id, 1, 1, 1, category_calculated_coef FROM categories;
  INSERT INTO users (user_id,user_login,user_level,user_avatar,user_modification,user_date,user_validated_date,user_ip,user_pass,user_email,user_names,user_login_register,user_email_register,user_lang,user_comment_pref,user_karma,user_public_info,user_url,user_adcode,user_adchannel,user_phone)
    VALUES (1,'Shadows236','god',0,'2018-10-10 21:41:28','2018-10-10 12:04:33','2018-10-10 21:42:28','127.0.0.1','sha256:JKVNPRNe5ZM2OvQ7Re42rUxYitMiMUMe:5d62fa6c578d2166b8b89732f0e8656ed54199df4fbafbc8ec32f8c0c858b2d6','admin@admin.cl','','Shadows236','admin@admin.cl',1,0,6.00,NULL,'',NULL,NULL,NULL);
INITIAL_DATA

echo "Configuring Meneame settings\n"
wget -O meneame/www/localhost-local.php \
    https://gist.githubusercontent.com/cristiannsc/aa115a3257ecc90430de15ade1cc0d5f/raw/localhost-local.php

echo "Meneame Config finished."