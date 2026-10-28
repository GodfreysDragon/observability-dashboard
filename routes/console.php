<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('obs:snapshot-queues')->everyMinute()->withoutOverlapping();
Schedule::command('obs:prune')->hourly()->withoutOverlapping();
