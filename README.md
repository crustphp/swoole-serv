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

(Optionally) To install the specific version of Swoole, run following command:

```sh
pecl install -D 'enable-sockets="yes" enable-openssl="yes" enable-http2="yes" enable-mysqlnd="yes" enable-swoole-json="no" enable-swoole-curl="yes" enable-cares="yes" enable-swoole-pgsql="yes" enable-debug="yes" enable-debug-log="yes" enable-trace-log="yes" enable-thread-context="yes"' swoole-5.1.3
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

##### Split the huge files (log files) into smaller chunks:
Some log files can be difficult to open, as their **large size** may cause them to crash. To handle this, you can split them using the following commands.

```sh
# Splits by file size (500MB per file)
split -b 500M filename.txt chunk_

# Splits by lines (10,000 per file)
split -l 10000 filename.txt chunk_
```

It will create files like: chunk_aa, chunk_ab etc.

# Other useful dependencies / services installation
## SSH2 Installation
### Step 1 - Check if SSH2 is already installed
If you run the following terminal command on your system or server instance and it does not show any **ssh2-related** entries in the output array (as shown in the example output), it means the SSH2 extension is not installed. In that case, you should install the SSH2 extension to enable related functionality.

Command to check if SSH2 is installed:
```
php -r "print_r(stream_get_wrappers());"
```

If you see the ssh2 entries (as shown in below output), it means ssh2 is already installed. Otherwise follow the steps to install it:
```
Array
(
    [0] => https
    [1] => ftps
    [2] => compress.zlib
    [3] => php
    [4] => file
    [5] => glob
    [6] => data
    [7] => http
    [8] => ftp
    [9] => phar
    [10] => ssh2.shell
    [11] => ssh2.exec
    [12] => ssh2.tunnel
    [13] => ssh2.scp
    [14] => ssh2.sftp
    [15] => zip
)
```

Further Confirm:
```
php -i | grep ssh2 
php -m | grep ssh2
```

### Step 2 - Install OpenSSH Server
To start using **SSH2** on your system, you first need to install and enable the OpenSSH Server. Follow the steps below:

#### 1. Check if SSH is already running:
```
sudo systemctl status ssh
```
This command checks the current status of the SSH service. If it's not found or not running, continue with the next steps.

#### 2. Update your system packages:
```
sudo apt update && sudo apt upgrade
```
This updates the list of available packages and installs any available upgrades. It's a good idea to run this before installing new software.

#### 3. Install the OpenSSH Server package:
```
sudo apt install openssh-server
```
This installs the SSH server on your system, allowing it to accept SSH connections.

#### 4. Enable and start the SSH service immediately:
```
sudo systemctl enable --now ssh
```
This command ensures that the SSH service starts automatically on boot and also starts it right now.

#### 5. Verify that SSH is now active:
```
sudo systemctl status ssh
```
You should now see that the SSH service is active and running.

### Step 3 - Installing the PHP SSH2 Extension (for PHP 7 or 8)
Once OpenSSH is successfully installed and running on your system, the next step is to install the SSH2 extension for PHP. This extension allows PHP to connect to remote servers over SSH.

#### 1. Install required build tools and libraries:
```
sudo apt-get -y install gcc make autoconf libc-dev pkg-config

sudo apt-get -y install libssh2-1-dev
```

#### 2. Install the SSH2 extension using PECL:
```
yes '' | sudo pecl install ssh2-beta
```

#### 3. Register SSH2 extension in PHP .ini file:
```
sudo bash -c "echo extension=ssh2.so > /etc/php/8.3/mods-available/ssh2.ini"
```
#### 4. Enable the SSH2 extension for the CLI version of PHP:
```
sudo phpenmod -v 8.3 -s cli ssh2
```
This activates the extension **specifically for PHP 8.3** in the CLI (Command Line Interface) environment. Adjust the PHP version if you're using a different one.

### Step 4 - Verify Installation

To make sure the extension was installed and enabled successfully, run:

```
sudo php -m | grep ssh2
```
If the extension is installed correctly, you’ll see ssh2 in the output.

### Step 5 - Restart PHP-FPM

Finally, restart PHP-FPM so it can load the new extension:
```
sudo systemctl restart php8.3-fpm
```

Replace 8.3 with your PHP version if needed.

## PostgreSQL Configuration
To update the settings first you need to open the `postgresql.conf` file using following command:

```
sudo nano /etc/postgresql/<version>/main/postgresql.conf
```

**Note:** Once you have done the modifications, you need to restart the postgreSQL service using:

```
sudo systemctl restart postgresql
```

### 1 - TCP Settings:
Once the file is opened, look for the **TCP settings** and update as following:

```
# - TCP settings -
# see "man tcp" for details

tcp_keepalives_idle = 15                # TCP_KEEPIDLE, in seconds;
                                        # 0 selects the system default
tcp_keepalives_interval = 3             # TCP_KEEPINTVL, in seconds;
                                        # 0 selects the system default
tcp_keepalives_count = 10               # TCP_KEEPCNT;
                                        # 0 selects the system default
tcp_user_timeout = 30000                # TCP_USER_TIMEOUT, in milliseconds;
                                        # 0 selects the system default

client_connection_check_interval = 150  # time between checks for client
                                        # disconnection while running queries;
                                        # 0 for never
```

### 2 - Increase Number of concurrent connections of PostgreSQL Server:
Look for the **Connection settings** in `postgresql.conf` file and update as following:

```
max_connections = 200			# (change requires restart)
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

# Monitoring Script
### Run the monitoring script
The monitoring script sends a ping to the WebSocket server and waits for a pong response. If, at any point, the script stops receiving the pong response within the specified timeout period, it terminates all processes running on the specified port and restarts the WebSocket server.

You can run the monitoring script using the following command (make sure to execute it with sudo):

```
sudo php monitoring.php
```

By default, the script will run with the following settings:

- Daemon mode: Enabled (1)
- Host: 127.0.0.1
- Port: 9501
- Timeout: 15 seconds (time after which the WebSocket server will restart if no pong is received)
- Ping Timer: 5000 milliseconds (interval for sending ping requests, equivalent to 5 seconds)

To run the monitoring script with custom settings or options, use the following command:

```
sudo php monitoring.php --host=127.0.0.1 --port=9501 --timeout=15 --daemon=1 --pingtimer=5000
```

### Other useful commands related to Monitoring script
1- Check if Monitoring Process is running:

```
pidof php-monitoring:swoole-monitoring-process
```

2- Stop / Shutdown the Monitoring Process:

```
sudo kill -9 $(pidof php-monitoring:swoole-monitoring-process)
```


# Custom Process Creation Guide

This guide explains how to create custom processes that run alongside configured Worker and Task Worker processes. Below, you'll find detailed instructions and options for creating these processes.

## Creating a New Process

To create a new custom process, use the following command:

```
php prompt make:process ProcessName
```

This command will:
- Create a new process.
- Register it with default process options.

### Available Swoole Process Options

You can customize the process using the following options provided by Swoole. For more details, refer to the [Swoole Process documentation](https://wiki.swoole.com/en/#/process/process?id=__construct).

- **`redirect_stdin_and_stdout`**
- **`pipe_type`**
- **`enable_coroutine`**

Example usage:

```
php prompt make:process ProcessName --redirect_stdin_and_stdout=1 --pipe_type=2 --enable_coroutine=1
```

## Additional Boilerplate Options / Configurations

In addition to Swoole options / configurations, you can use the following options provided by the boilerplate:

### Enable / Disable Processes
You can enable or disable custom user processes using the following command.

Command:

```
php prompt configure:process ProcessName --enable=1
```

- **Note:** The process will be enabled or disabled only in the environment where the command is executed. For example, if you run this command in the staging environment, it will enable or disable the process only in staging and will not affect the local or other environments.


# Redis Installation and Configuration Guide

This guide walks you through installing Redis on an system, setting a password for security, and testing your Redis server.

---

## Installation Steps

### 1. Update Package Index
```sh
sudo apt update
sudo apt install redis-server
```

## Configure Redis Password

### 3. Open Redis Configuration File
```sh
sudo nano /etc/redis/redis.conf
```

### 4. Set the Password
Find the following line:
# requirepass foobared
Uncomment it and set your own password:
requirepass mystrongpassword
Replace mystrongpassword with a secure password of your choice.

### 5. Restart Redis Service
sudo systemctl restart redis.service
```sh
sudo systemctl restart redis.service
```

### 6. (Optional) Check Redis Status
```sh
sudo systemctl status redis
```

## Test the Redis Server

### 7. Open Redis CLI
```sh
redis-cli
```

### 8. Authenticate with Your Password
```sh
auth mystrongpassword
```

### 9. Test the Connection
```sh
ping
```

### 10. Expected Response
```sh
PONG
```

