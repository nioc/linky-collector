#!/usr/bin/php
<?php
// This script is a workaround to collect data from hourly CSV file since Enedis website has changed
// You need to download hourly CSV file from Enedis portal and call the script with this filename as argument

chdir(__DIR__);
require __DIR__.'/vendor/autoload.php';
$config = include 'config.php';
require 'Storage.php';

// Initialize logger
Logger::configure('config.xml');
$logger = Logger::getLogger('main');
$logger->info('Start Enedis file process');
$logger->debug('Log level: '.$logger->getEffectiveLevel()->toString());

// Get CSV filename from script argument
$filename = null;
if ($argc < 2) {
    $logger->error('This script must be called with filename as argument (example: php index.php Enedis_Conso_Heure_20200701-20200811_123456.csv)');
    exit(1);
}
$filename = $argv[1];
if (file_exists($filename) === false) {
    $logger->error('Argument must be an existing file, provided: ' . $argv[1]);
    exit(1);
}
$logger->info('Provided filename: ' . $filename);

// Initialize database access
$logger->debug('Start initialization');
$storage = new Storage();
$storage->connect($config['host'], $config['port'], $config['database'], $config['retentionDuration']);

// Get measures from Enedis hourly CSV file
$hoursValues = [];
$daysValues = [];
$row = 0;
$rowProcessed = 0;
$startAt = 3;
$halfHour = new DateInterval('PT30M');
if (($handle = fopen($filename, 'r')) !== false) {
    while (($data = fgetcsv($handle, 1000, ';')) !== false) {
        // parse from the 4th line (discard headers)
        $row++;
        if ($row > $startAt) {
            // check line format
            if (count($data) !== 2) {
                $logger->error("Line $row does not have requested format, it will be discarded");
                continue;
            }
            // set start time instead of end time
            $startHour = new DateTime($data[0]);
            $startHour->sub($halfHour);
            // set value in kWh (as the period is 30 minutes, value is divided by 2)
            $hoursValues[$startHour->getTimestamp()] = intval($data[1])/2/1000;
            $rowProcessed++;
        }
    }
    // close file
    fclose($handle);
}
$logger->info('Read '.($row-$startAt).' lines from CSV file');
$logger->info("Processed $rowProcessed lines from CSV file");

$hourlyPoints = [];
$dailyPoints = [];
try {
    // Transform hourly data and writing it to database
    $hasOffPeakPeriod = count($config['off-peak']) > 0 ? true : false;
    $logger->debug("Off-peak periods: $hasOffPeakPeriod");
    // $dateTimeZone = new DateTimeZone(date_default_timezone_get());
    $dateTimeZone = new DateTimeZone('Europe/Paris');

    $logger->debug("Processing hourly values");
    foreach ($hoursValues as $timestamp => $value) {
        if ($value !== null) {
            // Check if timestamp is in an off-peak period
            $date = new DateTime("@$timestamp");
            $date->setTimezone($dateTimeZone);
            $isOffPeak = false;
            foreach ($config['off-peak'] as $offPeakPeriod) {
                // Create start datetime and end datetime (start + duration) on each off-peak period
                $offPeakPeriodStart = clone $date;
                $offPeakPeriodStart->setTime($offPeakPeriod['start']['h'], $offPeakPeriod['start']['m']);
                $offPeakPeriodEnd = clone $offPeakPeriodStart;
                $offPeakPeriodEnd->add(new DateInterval($offPeakPeriod['duration']));
                if (
                    (
                        // Check if current date is in off peak period for current day
                        $date >= $offPeakPeriodStart &&
                        $date < $offPeakPeriodEnd
                    ) ||
                    (
                        // Check if current date is in off peak period for previous day
                        $date >= $offPeakPeriodStart->sub(new DateInterval('P1D')) &&
                        $date < $offPeakPeriodEnd->sub(new DateInterval('P1D'))
                    )
                ) {
                    $isOffPeak = true;
                }
                // $logger->trace($value.' @ '.$timestamp.' - current='.$date->format('Y-m-d H:i:sP'). ' - start='. $offPeakPeriodStart->format('Y-m-d H:i:sP').' - end='. $offPeakPeriodEnd->format('Y-m-d H:i:sP').' '.$isOffPeak);
            }
            // Create point values
            $values = [];
            if (!$hasOffPeakPeriod) {
                $values['normal'] = (float) $value;
            } elseif ($isOffPeak) {
                $values['off-peak'] = (float) $value;
            } else {
                $values['peak'] = (float) $value;
            }
            // Add current point to array
            array_push($hourlyPoints, $storage->createPoint('hourly', $timestamp, null, $values));
            // Add to day measurements
            $day = $date->format('Y-m-d');
            // $logger->trace($day.' '.$date->format('Y-m-d H:i:sP'));
            if (!array_key_exists($day, $daysValues)) {
                $daysValues[$day] = 0;
            }
            $daysValues[$day] += $value;
        }
    }

    //Transform daily data for writing to database
    $logger->debug("Processing daily values");
    $oneDay = new DateInterval('P1D');
    foreach ($daysValues as $day => $value) {
        if ($value !== null) {
            $timestamp = DateTime::createFromFormat('Y-m-d', $day)->setTimezone($dateTimeZone)->setTime(0, 0)->sub($oneDay)->getTimestamp();
            // $logger->trace("$day ($timestamp): $value");
            array_push($dailyPoints, $storage->createPoint('daily', $timestamp, (float) $value, []));
        }
    }

    // Write hourly to storage
    $logger->debug('Writing hourly data to database');
    $storage->writePoints($hourlyPoints);

    // Write daily to storage
    if (count($dailyPoints)) {
        $logger->debug('Writing daily data to database');
        $storage->writePoints($dailyPoints);
    }

} catch (Exception $e) {
    $logger->error('Failure during data processing');
    $logger->debug($e->getMessage());
    exit(1);
}

$logger->info('End Enedis file process');
exit(0);
