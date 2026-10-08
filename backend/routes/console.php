<?php

use Illuminate\Support\Facades\Schedule;

/*
| Scheduled jobs. On cPanel add ONE cron job (every minute):
|   cd /home/CPANELUSER/morena-crm && /opt/cpanel/ea-php83/root/usr/bin/php artisan schedule:run >> /dev/null 2>&1
| Times are in the app timezone (Africa/Johannesburg).
*/
Schedule::command('morena:daily')->dailyAt('06:00')->withoutOverlapping();
Schedule::command('morena:weekly-digest')->weeklyOn(1, '07:00')->withoutOverlapping();
