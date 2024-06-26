## STEP-1: Pre-requisites (Required Linux Packages):
Make sure you have PHP8.x installed and setup to use on Cli, using ```php -v```
(For Docker Images check Swoole / OpenSwoole Official Documentation)

```sh
sudo apt-get update
sudo apt install gcc -y && \
sudo apt-get install libssl-dev -y  && \
sudo apt install openssl -y && \
sudo apt install build-essential -y && \
sudo apt-get install bzip2 -y && \
sudo apt-get install zlib1g-dev -y && \
sudo apt-get install libzip-dev -y && \
sudo apt-get install autoconf -y && \
sudo apt install libpcre3-dev -y && \
sudo apt-get install -y libc-ares2 -y && \
sudo apt install libc-ares-dev -y && \
sudo apt-get install libpq-dev -y && \
sudo apt-get install libcurl4-openssl-dev -y && \
sudo apt-get install inotify-tools -y
```

## STEP-2: Swoole Installation
```sh
pecl install -D 'enable-sockets="yes" enable-openssl="yes" enable-http2="yes" enable-mysqlnd="yes" enable-swoole-json="no" enable-swoole-curl="yes" enable-cares="yes"' swoole
```
### OR

### Optionally Install Swoole using Source Code
if /tmp directory not created, then create it as below
```sh
sudo mkdir -m 775 /tmp
```

To install Swoole on PHP 8.3, use command below (For a different version of php replace all instances of '8.3' in command below with your version of php)

```sh
cd /tmp && \
git clone https://github.com/swoole/swoole-src && \
cd swoole-src && \
git checkout v5.1.2 && \
phpize8.3 clean && \
phpize8.3 && \
./configure --enable-openssl \
        --enable-mysqlnd \
        --enable-sockets \
        --enable-http2 \
        --enable-swoole-curl \
        --enable-swoole-json \
        --enable-swoole-pgsql \
        --with-postgres \
        --enable-debug \
        --enable-debug-log \
        --enable-trace-log \
        --enable-thread-context \
        --enable-cares \
        --with-php-config=/usr/bin/php-config8.3 && \
sudo make && sudo make install && \
sudo bash -c "cat > /etc/php/8.3/mods-available/swoole.ini << EOF
; Configuration for OpenSwoole
; priority=25
extension=swoole
swoole.enable_preemptive_scheduler=On
EOF"
sudo phpenmod -s cli -v 8.3 openswoole
```

### (Optional) Install OpenSwoole (Optional)

```sh
sudo pear channel-update pear.php.net  && \
sudo pecl channel-update pecl.php.net  && \
sudo pecl install -D 'enable-sockets="yes" enable-openssl="yes" enable-http2="yes" enable-mysqlnd="yes" enable-swoole-pgsql="yes" enable-swoole-json="yes" enable-swoole-curl="yes" enable-debug="yes" enable-swoole-trace="yes" enable-thread-context="yes" enable-debug-log="yes" enable-trace-log="yes" enable-cares="yes"' openswoole-22.1.2  && \
sudo bash -c "cat > /etc/php/8.3/mods-available/openswoole.ini << EOF
; Configuration for OpenSwoole
; priority=25
extension=openswoole
openswoole.enable_preemptive_scheduler=On
openswoole.use_shortname=On
swoole.use_shortname=On
EOF"  && \
sudo phpenmod -s cli -v 8.3 openswoole
```

### Install core OpenSwoole library inside Project
```composer require openswoole/core:22.1.5```


## STEP-3: Download Project From GitHub
```git clone https://github.com/fakharak/swoole-serv.git```

```sh 
composer install``
composer dump-autoload
```
