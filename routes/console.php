<?php

use Illuminate\Support\Facades\Schedule;

// Purge expired Hawk tokens and BSOs every hour
Schedule::command('sync:purge-expired')->hourly();
