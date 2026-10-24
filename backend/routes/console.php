<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('kommunal:schedule-due')->everyMinute()->withoutOverlapping();
