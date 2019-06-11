<?php
require __DIR__.'/vendor/autoload.php';

/**
 * Storage (influxDB) wrapper
 */
class Storage
{
    private $client = null;
    private $database = null;
    const HOURLY_MEASUREMENT = 'power_consumption';
    const DAILY_MEASUREMENT = 'power_consumption_daily';

    /**
     * Initialize a InfluxDB wrapper
     */
    public function __construct()
    {
        $this->logger = Logger::getLogger('Storage');
    }

    /**
     * Connect to InfluxDB database, create it if not existing
     *
     * @param string $host InfluxDB server hostname (exemple: 'localhost')
     * @param string $port InfluxDB server listening port (exemple: '8086')
     * @param string $database InfluxDB database used (exemple: 'netatmo')
     * @param string $retentionDuration InfluxDB database retention policy duration (exemple: '1825d')
     * @throws Exception If can not access database
     */
    public function connect($host, $port, $database, $retentionDuration)
    {
        $this->logger->debug("Connecting to database $database (http://$host:$port)");
        try {
            // Create client
            $this->client = new InfluxDB\Client($host, $port);
            $this->logger->trace('InfluxDB client created');

            // Select database
            $this->database = $this->client->selectDB($database);
            $this->logger->trace('Database selected');
        } catch (Exception $e) {
            $this->logger->error('Can not access database');
            $this->logger->debug($e->getMessage());
            throw new Exception($e, 1);
        }
        try {
            $this->logger->trace('Check database exists');
            if (!$this->database->exists()) {
                // Create database with retention policy
                $this->logger->trace('Database does not exist');
                $this->database->create();
                $this->database->alterRetentionPolicy(new InfluxDB\Database\RetentionPolicy('autogen', $retentionDuration, 1, true));
                $this->logger->info('Database created successfully with retention duration: ' . $retentionDuration);
            }
        } catch (Exception $e) {
            $this->logger->error('Can not create database');
            $this->logger->debug($e->getMessage());
        }
    }

    /**
     * Get InfluDB measurement according to time unit
     *
     * @param string $timeUnit (hourly or daily)
     * @throws Exception If time unit is unknown
     * @return measurement name
     */
    private function get_measurement($timeUnit)
    {
        switch ($timeUnit) {
            case 'hourly':
                return $this::HOURLY_MEASUREMENT;
                break;
            case 'daily':
                return $this::DAILY_MEASUREMENT;
                break;
            default:
                throw new Exception('Unknown time unit', 1);
        }
    }

    /**
     * Get timestamp of last fetched value for specific time unit
     *
     * @param string $timeUnit Measurement time unit
     * @throws Exception If time unit is unknown or if there is no previous value
     * @return DateTime of the last fetched value
     */
    public function get_last_fetch_date($timeUnit = 'hourly')
    {
        $this->logger->debug("Get last fetch date for $timeUnit");
        try {
            $measurement = $this->get_measurement($timeUnit);
        } catch (Exception $e) {
            throw new Exception($e, 1);
        }
        $this->logger->trace("Measurement to use is $measurement");
        $dateTimeZone = new DateTimeZone(date_default_timezone_get());
        $lastPeak = null;
        $lastOffPeak = null;
        $lastNormal = null;
        $lastValue = null;
        // Request last peak data (hourly)
        try {
            $resultPeak = $this->database->query("SELECT last(peak) FROM \"$measurement\"");
            $pointsPeak = $resultPeak->getPoints();
            if (count($pointsPeak)) {
                $lastPeak = new DateTime($pointsPeak[0]['time']);
                $lastPeak->setTimezone($dateTimeZone);
                $this->logger->trace('Last data peak was '.$lastPeak->format('Y-m-d H:i:sP'));
            }
        } catch (Exception $e) {
            $this->logger->debug('Can not get peak last fetch');
            $this->logger->debug($e->getMessage());
        }
        // Request last off-peak data (hourly)
        try {
            $resultOffPeak = $this->database->query("SELECT last(\"off-peak\") FROM \"$measurement\"");
            $pointsOffPeak = $resultOffPeak->getPoints();
            if (count($pointsOffPeak)) {
                $lastOffPeak = new DateTime($pointsOffPeak[0]['time']);
                $lastOffPeak->setTimezone($dateTimeZone);
                $this->logger->trace('Last data off-peak was '.$lastOffPeak->format('Y-m-d H:i:sP'));
            }
        } catch (Exception $e) {
            $this->logger->debug('Can not get off-peak last fetch');
            $this->logger->debug($e->getMessage());
        }
        // Request last normal data (hourly)
        try {
            $resultNormal = $this->database->query("SELECT last(normal) FROM \"$measurement\"");
            $pointsNormal = $resultNormal->getPoints();
            if (count($pointsNormal)) {
                $lastNormal = new DateTime($pointsNormal[0]['time']);
                $lastNormal->setTimezone($dateTimeZone);
                $this->logger->trace('Last data normal was '.$lastNormal->format('Y-m-d H:i:sP'));
            }
        } catch (Exception $e) {
            $this->logger->debug('Can not get off-peak last fetch');
            $this->logger->debug($e->getMessage());
        }
        // Request last value data (daily)
        try {
            $resultValue = $this->database->query("SELECT last(value) FROM \"$measurement\"");
            $pointsValue = $resultValue->getPoints();
            if (count($pointsValue)) {
                $lastValue = new DateTime($pointsValue[0]['time']);
                $lastValue->setTimezone($dateTimeZone);
                $this->logger->trace('Last data was '.$lastValue->format('Y-m-d H:i:sP'));
            }
        } catch (Exception $e) {
            $this->logger->debug('Can not get off-peak last fetch');
            $this->logger->debug($e->getMessage());
        }
        if ($lastPeak === null && $lastOffPeak === null && $lastNormal === null && $lastValue === null) {
            $this->logger->error("Can not get last fetch ($timeUnit)");
            throw new Exception('Can not get last fetch', 1);
        }
        $last = max($lastPeak, $lastOffPeak, $lastNormal, $lastValue);
        $this->logger->info('Last fetch date ('.$timeUnit.'): '.$last->format('Y-m-d H:i:s'));
        return $last;
    }

    /**
     * Prepare point for insertion
     *
     * @param string $timeUnit Determine the measurement (daily/hourly)
     * @param int $timestamp
     * @param float $value main value
     * @param float[] $values additionnal values
     * @return InfluxDB\Point
     */
    public function createPoint($timeUnit, $timestamp, $value, $values)
    {
        $measurement = $this->get_measurement($timeUnit);
        // $this->logger->trace("Create point $timestamp ($measurement): value=$value");
        return new InfluxDB\Point(
            $measurement,
            $value,
            [
            ],
            $values,
            $timestamp
        );
    }

    /**
     * Write points to InfluxDB database
     *
     * @param InfluxDB\Point[] $points Array of points to write
     */
    public function writePoints($points)
    {
        $this->logger->info('Writing '.count($points).' points');
        try {
            return $this->database->writePoints($points, InfluxDB\Database::PRECISION_SECONDS);
        } catch (Exception $e) {
            $this->logger->error('Can not write data');
            $this->logger->debug($e->getMessage());
        }
    }
}
