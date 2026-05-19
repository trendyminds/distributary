<?php

namespace Trendyminds\Distributary\Tests;

use Statamic\Testing\AddonTestCase;
use Trendyminds\Distributary\ServiceProvider;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;
}
