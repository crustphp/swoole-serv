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
sudo apt-get install inotify-tools -y && \
sudo apt install libbrotli-dev -y
```

## STEP-2: Swoole Installation
### Step 2.1
Run the following command to install swoole

```sh
pecl install -D 'enable-sockets="yes" enable-openssl="yes" enable-http2="yes" enable-mysqlnd="yes" enable-swoole-json="no" enable-swoole-curl="yes" enable-cares="yes" enable-swoole-pgsql="yes" enable-debug="yes" enable-debug-log="yes" enable-trace-log="yes" enable-thread-context="yes"' swoole
```

**Note:** If swoole is already installed (but not correctly) then remove it using following commands and run the above command again.

```
pecl uninstall swoole && \
pecl clear-cache && \
pear channel-update pear.php.net  && \
pecl channel-update pecl.php.net
```

### Step 2.2
After `pecl install` command if you see following message:
  
> You should add "extension=swoole.so" to php.ini
 
Then run the following commands

```
bash -c "cat > /etc/php/8.3/mods-available/swoole.ini << EOF
; Configuration for Swoole
; priority=25
extension=swoole
swoole.enable_preemptive_scheduler=On
EOF"
```

### Step 2.3
Now you need to enable Swoole

```
phpenmod -s cli -v 8.3 swoole
```

### Step 2.4
Now lastly you need to verify that if **swoole** is installed correctly.

```
php --ri swoole
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
git checkout v5.1.3 && \
phpize8.3 clean && \
phpize8.3 && \
./configure --enable-openssl \
        --enable-mysqlnd \
        --enable-sockets \
        --enable-swoole-curl \
        --enable-swoole-pgsql \
        --enable-debug \
        --enable-debug-log \
        --enable-trace-log \
        --enable-thread-context \
        --enable-cares \
        --with-php-config=/usr/bin/php-config8.3 && \
sudo make clean && make && sudo make install && \
sudo bash -c "cat > /etc/php/8.3/mods-available/swoole.ini << EOF
; Configuration for OpenSwoole
; priority=25
extension=swoole
swoole.enable_preemptive_scheduler=On
EOF"
sudo phpenmod -s cli -v 8.3 openswoole
```

### (Optional) Install OpenSwoole

```sh
sudo pear channel-update pear.php.net  && \
sudo pecl channel-update pecl.php.net  && \
sudo pecl install -D 'enable-sockets="yes" enable-openssl="yes" enable-http2="yes" enable-mysqlnd="yes" enable-swoole-pgsql="yes" with-postgres="yes" enable-swoole-json="yes" enable-hook-curl="yes" enable-swoole-curl="yes" enable-debug="yes" enable-swoole-trace="yes" enable-thread-context="yes" enable-debug-log="yes" enable-trace-log="yes" enable-cares="yes"' openswoole-22.1.2 && \
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
composer install
composer dump-autoload
```

##### To Start WebSocket Server:
cd to swoole-serv folder, and then run the command below;

```php sw_service.php websocket```

##### To send some messages to Web Socket Server from TCP Client (Like, for Testing) use below:
```sudo php ./websocketclient/websocketclient_usage.php```

##### To reload server workers from server-side script (after code changes):
```php sw_service.php reload-code```

##### To reload server workers from client-side script (after code changes):
```sudo php ./websocketclient/websocketclient_usage.php reload-code```

##### To Shutdown  Swoole Server:
```php sw_service.php shutdown```

##### To Restart the  Swoole Server:
```php sw_service.php restart```

##### Reload Workers and Task Workers, both, gracefully; after completing current requests
```kill -USR1 `cat server.pid` ```

##### Reload Task Worker Gracefully by completing current task
```kill -USR2 `cat server.pid` ```

##### Kill Service safely

```sudo kill -SIGTERM $(sudo lsof -t -i:9501)```
 OR
```sudo kill -15 $(sudo lsof -t -i:9501)```
 OR
```kill -15 [process_id]]```
 OR (specially when daemon = 1 (daemonize mode))
```sudo kill `cat server.pid` ```


## Other Useful Run-time Commands
##### Switch from Swoole to OpenSwoole, and vice versa

```sh
sudo phpdismod -s cli swoole && \
sudo phpenmod -s cli openswoole
```

##### Switch from OpenSwoole to Swoole, and vice versa

```sh
sudo phpdismod -s cli openswoole && \
sudo phpenmod -s cli swoole
```

##### Check the Swoole processes:

```sh
 ps -aux | grep swool
 ps faux | grep -i sw_service.php
 sudo lsof -t -i:9501
```

##### Get the Server Stats:

```sh
php sw_service.php stats
```

# Setting up Phinx (Database Migrations)
You can setup the database and other Phinx configuration inside `config/phinx.php` file

## Create the migration
The Create command is used to create a new migration file. It requires one argument: the name of the migration. The migration name should be specified in CamelCase format.

```
composer run-script phinx:create MigrationFileName
```

## Running Migrations
The Migrate command runs all of the available migrations, optionally up to a specific version. (Specifying environment is optional, migration will migrate on a default environment, set in phinx.php config, if -e flag is ignored)

```
composer run-script phinx:migrate -- -e <environment>
```

To migrate to a specific version then use the `--target` parameter or `-t` for short.
Example:

```
composer run-script phinx:migrate -- -t 20110103081132
```

Use `--dry-run` to print the queries to standard output without executing them

```
composer run-script phinx:migrate -- --dry-run
```

## Rollback Migrations
The Rollback command is used to undo previous migrations executed by Phinx. It is the opposite of the Migrate command.
You can rollback to the previous migration by using the  `rollback`  command with no arguments.

```
composer run-script phinx:rollback
```

To rollback all migrations to a specific version then use the `--target` parameter or `-t` for short.

```
composer run-script phinx:rollback -- -t 20120103083322
```

Specifying 0 as the target version will revert all migrations.

```
composer run-script phinx:rollback -- -t 0
```

Use `--dry-run` to print the queries to standard output without executing them

```
composer run-script phinx:rollback -- --dry-run
```

## Database Seeding
The Seed Create command can be used to create new database seed classes. It requires one argument, the name of the class. The class name should be specified in CamelCase format.

```
composer run-script phinx:seed-create MyNewSeeder
```

The Seed Run command runs all of the available seed classes or optionally just one.

```
composer run-script phinx:seed-run
```

To run only one seed class use the `--seed` parameter or `-s` for short.

```
composer run-script phinx:seed-run -- -s MyNewSeeder
```
