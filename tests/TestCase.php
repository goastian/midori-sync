<?php

namespace Tests;

use App\Models\Collection;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Collections use a static in-memory cache keyed by name.
        // Reset it between tests so that RefreshDatabase rollbacks do not
        // leave stale model instances memoized across the suite.
        Collection::flushNameCache();
    }
}
