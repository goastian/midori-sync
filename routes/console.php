<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('sync:cleanup-expired')->hourly();
Schedule::command('sync:recalculate-usage')->daily();
