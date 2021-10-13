# Power meter
This application was created to monitor the output of my mini photovoltaic system (aka "Balkonkraftwerk"), but it can also be used to monitor other consumers. It currently supports the Tasmota firmware and AVM FRITZ!Box in conjunction with a FRITZ!DECT 200 or 210.

## Requirements
Web server with PHP and access to your energy meter (i.e. Raspberry Pi)

## Installation
1. Rename config.inc.sample to config.inc.php
2. Open config.inc.php and configure it to your needs (everything is documented in that file)
3. Upload all files to your web server
4. Access the application with your web browser

## Data logging
To record the activity over the day you need to create a cronjob which calls log.php every minute. Like this:
```
* * * * * curl https://<host name or ip address>/powermeter/log.php
```

## Statistics
When viewing the chart, the statistics of past days are saved to `chart_stats.csv`. You can automate that task with a cronjob that runs once a day, like
```
0 0 * * * curl https://<host name or ip address>/powermeter/chart.php?yesterday
```
