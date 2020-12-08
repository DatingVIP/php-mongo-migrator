#!/bin/bash

apt-get install iproute2 --no-install-recommends -y

### Register host machine
export DOCKERHOST_IP="$(/sbin/ip route|awk '/default/ { print $3 }')";
echo "$DOCKERHOST_IP dockerhost" >> /etc/hosts

# install php extensions
if [[ -z $(dpkg -l | grep libssl-dev) ]];
then
    # add library requirements
    apt-get update
    apt-get install --no-install-recommends -y \
        libssl-dev \
        zip \
        gpg \
        git

    yes '' | pecl install mongodb
    docker-php-ext-enable mongodb.so

    # install ext-zip
    apt-get install --no-install-recommends -y zlib1g-dev
    docker-php-ext-install zip

    # XDEBUG
    pecl install xdebug
    docker-php-ext-enable xdebug.so

    echo "xdebug.remote_enable=on" >> /usr/local/etc/php/conf.d/xdebug.ini
    echo "xdebug.remote_autostart=off" >> /usr/local/etc/php/conf.d/xdebug.ini
    echo "xdebug.remote_connect_back=1" >> /usr/local/etc/php/conf.d/xdebug.ini
    echo "xdebug.remote_mode=req" >> /usr/local/etc/php/conf.d/xdebug.ini
    echo "xdebug.remote_port=9001" >> /usr/local/etc/php/conf.d/xdebug.ini
    echo "xdebug.remote_host=dockerhost" >> /usr/local/etc/php/conf.d/xdebug.ini
    echo "xdebug.idekey=PHPSTORM" >> /usr/local/etc/php/conf.d/xdebug.ini
fi

# print versions
php -r "echo 'MongoDB version: ' . MONGODB_VERSION . PHP_EOL;"

# install composer
if [[  -z $(which composer) ]];
then
    # download composer
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer
    # update composer dependencies
    composer update --no-interaction --prefer-dist
    # install box
    curl -LSs https://box-project.github.io/box2/installer.php | php
    mv box.phar /usr/local/bin/box
fi

# wait commands
echo "Debugging session initialised"
php -S 127.0.0.1:9876 .

