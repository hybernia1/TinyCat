<?php
declare(strict_types=1);

// A runtime fatal which cannot be handled as a Throwable.
class TinyCatDebugFatal { public function invalid(): void {} }
class TinyCatDebugFatalChild extends TinyCatDebugFatal { public function invalid(): int { return 1; } }
