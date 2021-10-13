# Power meter
This application was created to monitor the output of my mini photovoltaic system (aka "Balkonkraftwerk"), but it can also be used to monitor other consumers. It currently supports the Tasmota firmware and AVM FRITZ!Box in conjunction with a FRITZ!DECT 200 or 210.

## Requirements
Web server with PHP and access to your energy meter (e.g. Raspberry Pi).

## Installation
1. Rename `config.inc.sample` to `config.inc.php`
2. Open `config.inc.php` and configure it to your needs (everything is documented in that file)
3. Upload all files to your web server - create a folder if needed
4. Access the application with your web browser

## Data logging
To record the activity over the day you need to create a cronjob which calls `log.php` every minute, like
```
* * * * * curl https://<host name or ip address>/powermeter/log.php
```

## Statistics
When viewing the chart, the statistics of past days are saved to `chart_stats.csv`. You can automate that task with a cronjob that runs once a day, like
```
0 0 * * * curl https://<host name or ip address>/powermeter/chart.php?yesterday
```

## Recommendation when using flash memory
As flash memory (e.g. the SD card when using a Raspberry Pi) can endure only a relatively small number of write cycles you might consider to log your data elsewhere. If you have a hard disk attached to your server which runs 24/7 you should store your data there.

In my personal setup the hard disk sleeps most of the time. Therefore I log the data on a ram disk and copy it once a day to the SD card and after a reboot back from the SD card to the ram disk.

#### Creating a ram disk
Open `/etc/fstab` and add a row like
```
tmpfs /home/pi/ramdisk tmpfs defaults,noatime 0 0
```

#### Copy the data from the ram disk to the SD card once a day
Create a cronjob like
```
1 0 * * * cp -u /home/pi/ramdisk/* /home/pi/www/powermeter/data/
```

#### Copy the data from the SD card to the ram disk after a reboot
Create a cronjob like
```
@reboot cp /home/pi/www/powermeter/data/* /home/pi/ramdisk/
```
