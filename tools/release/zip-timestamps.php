<?php
declare(strict_types=1);

return static function (string $path, int $timestamp): void {
    $data = file_get_contents($path);

    if (!is_string($data)) {
        throw new RuntimeException('Unable to read the completed update archive.');
    }

    $end = strrpos($data, "PK\x05\x06");

    if ($end === false || strlen($data) - $end < 22) {
        throw new RuntimeException('Unable to locate the update archive directory.');
    }

    $read16 = static fn (string $bytes, int $offset): int => (int) unpack('vvalue', substr($bytes, $offset, 2))['value'];
    $read32 = static fn (string $bytes, int $offset): int => (int) unpack('Vvalue', substr($bytes, $offset, 4))['value'];
    $entries = $read16($data, $end + 10);
    $central = $read32($data, $end + 16);
    $year = min(2107, max(1980, (int) gmdate('Y', $timestamp)));
    $dosTime = ((int) gmdate('H', $timestamp) << 11)
        | ((int) gmdate('i', $timestamp) << 5)
        | intdiv((int) gmdate('s', $timestamp), 2);
    $dosDate = (($year - 1980) << 9)
        | ((int) gmdate('n', $timestamp) << 5)
        | (int) gmdate('j', $timestamp);
    $packedTimestamp = pack('vv', $dosTime, $dosDate);

    for ($index = 0; $index < $entries; $index++) {
        if (substr($data, $central, 4) !== "PK\x01\x02") {
            throw new RuntimeException('Invalid update archive central directory.');
        }

        $local = $read32($data, $central + 42);

        if (substr($data, $local, 4) !== "PK\x03\x04") {
            throw new RuntimeException('Invalid update archive local directory.');
        }

        for ($byte = 0; $byte < 4; $byte++) {
            $data[$local + 10 + $byte] = $packedTimestamp[$byte];
            $data[$central + 12 + $byte] = $packedTimestamp[$byte];
        }
        $central += 46
            + $read16($data, $central + 28)
            + $read16($data, $central + 30)
            + $read16($data, $central + 32);
    }

    if (file_put_contents($path, $data, LOCK_EX) !== strlen($data)) {
        throw new RuntimeException('Unable to normalize update archive timestamps.');
    }
};
