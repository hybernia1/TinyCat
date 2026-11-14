<?php
declare(strict_types=1);

final class ProfilerEmptyFixture
{
    public function __construct() {}
    public function idle(): void {}
}

function profilerEmptyFunction(): void {}
