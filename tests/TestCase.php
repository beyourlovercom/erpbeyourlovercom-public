<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getApplicationBasePath(): string
    {
        return realpath(__DIR__.'/..');
    }
}
