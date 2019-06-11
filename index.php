#!/usr/bin/php
<?php

chdir(__DIR__);
require __DIR__.'/vendor/autoload.php';
$config = include 'config.php';
require 'Linky.php';

// Initialize logger
Logger::configure('config.xml');
$logger = Logger::getLogger('main');
$logger->info('Start Enedis collect');
$logger->debug('Log level: '.$logger->getEffectiveLevel()->toString());

// Get optionnal start date from script argument
$startDate = null;
if ($argc > 1) {
    $startDate = DateTime::createFromFormat('Y-m-d', $argv[1]);
    if ($startDate === false) {
        $logger->error('Argument must be a valid start date as Y-m-d, provided: ' . $argv[1]);
        exit(1);
    }
    $startDate->setTime(0, 0);
    $logger->info('Provided start date: ' . $startDate->format('Y-m-d H:i:sP'));
}
$logger->debug('Start initialization');

// Initialize wrapper
$linky = new Linky($config['mock'], $config['host'], $config['port'], $config['database'], $config['retentionDuration'], $config['off-peak']);

// Log in Enedis server
try {
    $linky->login($config['enedis_user'], $config['enedis_pass']);
} catch (Exception $e) {
    // Can not authenticate on Enedis, exit script
    exit(1);
}

// Get measures from Enedis server
$linky->getHourlyMeasures($startDate);
$linky->getDailyMeasures($startDate);

// Close session
$linky->closeSession();
$logger->info('End Enedis collect');
exit(0);
