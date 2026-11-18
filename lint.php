<?php

$files = glob(__DIR__ . '/src/*.php');

$hasErrors = false;

foreach ($files as $file) {
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file), $output, $status);

    if ($status !== 0) {
        echo "[ERROR] " . basename($file) . "\n";
        echo implode("\n", $output) . "\n";
        $hasErrors = true;
    } else {
        echo "[OK] " . basename($file) . "\n";
    }

    $output = [];
}

if ($hasErrors) {
    exit(1);
}

echo "\nAll PHP files passed syntax checks!\n";
exit(0);
