# Power meter
This application was created to monitor the output of my mini photovoltaic system (aka "Balkonkraftwerk"), but it can also be used to monitor other consumers. It currently supports Shelly devices, the Tasmota firmware and AVM FRITZ!Box in conjunction with a FRITZ!DECT 200 or 210. We added experimental support for the EnverBridge for Envertec Inverter, but be aware the accuracy is around -10% off.

## Requirements
Web server with PHP and access to your energy meter (e.g. Raspberry Pi).

## Installation
1. Rename `config.inc.sample` to `config.inc.php`
2. Open `config.inc.php` and configure it to your needs (everything is documented in that file)
3. Upload all files to your web server - create folder(s) (for the application itself and the log directory) if needed
4. Access the application with your web browser

## Data logging
To record the activity over the day you need to create a cronjob which calls `log.php` every minute, like
```
* * * * * curl https://<host name or ip address>/powermeter/log.php
```
or, if you're on the same host
```
* * * * * cd /path/to/powermeter/ && php check.php
```

## Statistics
When viewing the chart, the statistics of **past** days are saved to `chart_stats.csv`. You can automate that task with a cronjob that runs once a day, like
```
0 1 * * * curl -L https://<host name or ip address>/powermeter/chart.php?yesterday
```

## Recommendation when using flash memory
As flash memory (e.g. the SD card when using a Raspberry Pi) can endure only a relatively small number of write cycles you might consider to log your data elsewhere. If you have a hard disk attached to your server which runs 24/7 you should store your data there.

In my personal setup the hard disk sleeps most of the time. Therefore I log the data on a ram disk and copy it once a day to the SD card and after a reboot back from the SD card to the ram disk.

#### Creating a ram disk
Open `/etc/fstab` and add a row like
```
tmpfs /home/pi/ramdisk tmpfs defaults,noatime 0 0
```

#### Copy the data from the ram disk to the flash memory card once a day
Create a cronjob like
```
1 1 * * * rsync -a /home/pi/ramdisk/* /home/pi/www/powermeter/data/ --exclude `date +%Y-%m-%d`.csv
```

#### Copy the data from the flash memory to the ram disk after a reboot
Create a cronjob like
```
@reboot cp /home/pi/www/powermeter/data/* /home/pi/ramdisk/
```

## Optional: additional external storage
If your server is located in your local network (LAN) you maybe also want to access the application even if you're not at home. Of course you could use VPN or forward a port to your web server to achieve this. If you don't want to do this and have a second web server which is accessible from the internet, you can use the built in method to store your data there additionally.

#### Setup
1. Copy the application to your second web server.
2. On your first web server, enter the URI of the application on your second web server to `$host_external` in `config.inc.php` and enter a random `$host_auth_key`. 
3. On your second web server, make sure the `$host_auth_key` matches and set `$use_cache` to `true`.
4. On your second web server, make sure the `$log_file_dir` exists and is writable.

## Favicon
If you like, you can add a file "favicon.png" to the application's root directory, which will be displayed in your browser.

## Docker

We added a basic Dockerfile.
* Install Docker
* git checkout
* Add and edit config.inc.php
* docker build powermeter -t powermeter
* docker run -p 80:80 powermeter

## Credits / used libraries
* Chart.js (https://github.com/chartjs/Chart.js)
