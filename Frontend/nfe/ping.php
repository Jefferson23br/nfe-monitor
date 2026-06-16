<?php

header('Content-Type: text/plain; charset=utf-8');
echo "OK nfe-monitor\n";
echo 'PHP ' . PHP_VERSION . "\n";
echo 'root ' . __DIR__ . "\n";
echo is_file(dirname(__DIR__, 2) . '/includes/bootstrap.php') ? "bootstrap OK\n" : "bootstrap MISSING\n";
echo is_file(__DIR__ . '/index.php') ? "index.php OK\n" : "index.php MISSING\n";
