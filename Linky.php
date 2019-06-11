<?php
require __DIR__.'/vendor/autoload.php';
require 'Storage.php';

/**
 * Linky wrapper
 */
class Linky
{
    private $logger;
    private $ch = null;
    private $isMocked;
    private $storage;
    private $offPeakPeriods;
    const LOGIN_URL = 'https://espace-client-connexion.enedis.fr/auth/UI/Login';
    const BASE_URL = 'https://espace-client-particuliers.enedis.fr/group/espace-particuliers';
    const HOME_PATH = '/accueil';
    const DATA_PATH = '/suivi-de-consommation';
    const COOKIE_NAME = 'iPlanetDirectoryPro';
    const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64; rv:67.0) Gecko/20100101 Firefox/67.0';
    const HOURLY_REQUEST_DURATION = 'P1Y';
    const DAILY_REQUEST_DURATION = 'P30D';
    const TIMEOUT = 60;
    const DATA_NOT_REQUESTED = -1;
    const DATA_NOT_AVAILABLE = -2;

    /**
     * Initialize a Linky wrapper
     *
     * @param boolean $isMocked True value will mock HTTP request
     * @param string $host InfluxDB server hostname (exemple: 'localhost')
     * @param string $port InfluxDB server listening port (exemple: '8086')
     * @param string $database InfluxDB database used (exemple: 'linky')
     * @param string $retentionDuration InfluxDB database retention policy duration (exemple: '1825d')
     * @param array $offPeakPeriods Off-peak periods array as start time and duration
     */
    public function __construct($isMocked, $host, $port, $database, $retentionDuration, $offPeakPeriods)
    {
        $this->logger = Logger::getLogger('Linky');
        $this->isMocked = $isMocked;
        $this->offPeakPeriods = $offPeakPeriods;
        if ($this->isMocked) {
            $this->logger->warn('API is mocked');
        }
        // Initialize database access
        $this->storage = new Storage();
        $this->storage->connect($host, $port, $database, $retentionDuration);
    }

    /**
     * Authentication on Linky server
     *
     * @param string $username Enedis account username
     * @param string $password Enedis account password
     * @throws Exception If authentication failed (credentials are not set, invalid credentials or technical error)
     */
    public function login($username, $password)
    {
        if ($username === '' || $password === '') {
            $this->logger->error('Credentials not set, please update enedis_user and enedis_pass in config.php');
            throw new Exception('Credentials not set', 1);
        }
        if (!$this->isMocked) {
            // Execute POST request to login endpoint with credentials in body
            $this->logger->debug("Authentication to Enedis API for user: $username");
            $body = http_build_query(
                array(
                    'IDToken1' => $username,
                    'IDToken2' => $password,
                    'SunQueryParamsString' => base64_encode('realm=particuliers'),
                    'encoded' => 'true',
                    'gx_charset' => 'UTF-8'
                    )
                );
            try {
                $response = $this->executeRequest('POST', $this::LOGIN_URL, $body);
            } catch (Exception $e) {
                $this->logger->error('Server not responding on POST request (auth)');
                $this->logger->debug($e->getMessage());
                throw new Exception('Server not responding on POST request (auth)', 1);
            }
                
            // Check the auth cookie
            preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $response['header'], $matches);
            $cookies = array();
            foreach ($matches[1] as $item) {
                parse_str($item, $cookie);
                $cookies = array_merge($cookies, $cookie);
            }
            if (!array_key_exists($this::COOKIE_NAME, $cookies)) {
                // Authentication cookie not found
                $this->logger->error('Authentification failed');
                throw new Exception('Authentification failed', 1);
            }

            // Consult portal
            try {
                $this->executeRequest('GET', $this::BASE_URL.$this::HOME_PATH);
            } catch (Exception $e) {
                // Warn the log but continue processing
                $this->logger->warn('Server not responding on GET request');
                $this->logger->debug($e->getMessage());
            }
        }
    }

    /**
     * Request hourly measures starting from datetime if provided
     *
     * @param datetime $startDatetime (optional) starting date of requested measurements
     */
    public function getHourlyMeasures($startDatetime)
    {
        try {
            // Handle start date
            if ($startDatetime === null) {
                $last = $this->storage->get_last_fetch_date('hourly');
                if ($last === null) {
                    $this->logger->error('No start date provided and can not get previous data, abort hourly data request');
                    return;
                }
                $startDatetime = $last;
            }

            // Get hourly data from Enedis API
            $this->logger->info('Getting hourly data from Enedis API');
            $hoursValues = $this->getDataPerHour($startDatetime);

            // Transform data and writing it to database
            $this->logger->debug('Writing hourly data to database');
            $points = [];
            $hasOffPeakPeriod = count($this->offPeakPeriods) > 0 ? true : false;
            $this->logger->debug("Off-peak periods: $hasOffPeakPeriod");
            $dateTimeZone = new DateTimeZone(date_default_timezone_get());
            foreach ($hoursValues as $timestamp => $value) {
                if ($value !== null) {
                    // Check if timestamp is in an off-peak period
                    $date = new DateTime("@$timestamp");
                    $date->setTimezone($dateTimeZone);
                    $isOffPeak = false;
                    foreach ($this->offPeakPeriods as $offPeakPeriod) {
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
                        // $this->logger->trace($timestamp.' - current='.$date->format('Y-m-d H:i:sP'). ' - start='. $offPeakPeriodStart->format('Y-m-d H:i:sP').' - end='. $offPeakPeriodEnd->format('Y-m-d H:i:sP').' '.$isOffPeak);
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
                    array_push($points, $this->storage->createPoint('hourly', $timestamp, null, $values));
                }
            }

            // Write to storage
            $this->storage->writePoints($points);
        } catch (Exception $e) {
            $this->logger->error('Failure during hourly data processing');
            $this->logger->debug($e->getMessage());
            // In case of error, do not exit, try to get daily data
        }
    }

    /**
     * Request hourly measures from provided datetime
     *
     * @param datetime $startDateDT starting date of requested measurements
     * @throws Exception If HTTP request failed or no data returned
     *
     * @return associative array of values by timestamp
     */
    private function getDataPerHour($startDateDT)
    {
        // Set start and end dates
        $startDateDT->setTime(0, 0);
        $endDateDT = (clone $startDateDT)->add(new DateInterval($this::HOURLY_REQUEST_DURATION));
        $todayDT = new DateTime('now');
        if ($endDateDT > $todayDT) {
            $endDateDT = $todayDT;
        }
        $startDate = $startDateDT->format('d/m/Y');
        $endDate = $endDateDT->format('d/m/Y');
        $this->logger->debug("Start date: $startDate, end date: $endDate");

        if (!$this->isMocked) {
            // Get hourly data from Enedis server
            try {
                $result = $this->getData('urlCdcHeure', $startDate, $endDate);
            } catch (Exception $e) {
                $this->logger->error('Error during HTTP request hourly measures');
                throw new Exception('Error during HTTP request hourly measures', 1, $e);
            }
            if (!$result['etat'] || ($result['etat'] && $result['etat']['valeur'] === 'erreur')) {
                // Handle error
                if ($result['etat'] && $result['etat']['erreurText']) {
                    // Specific error
                    $this->logger->error('API returned following error:' . $result['etat']['erreurText']);
                    throw new Exception('API returned following error:' . $result['etat']['erreurText'], 1);
                } else {
                    // Generic error
                    $this->logger->error('API returned an unknown error');
                    throw new Exception('API returned an unknown error', 1);
                }
            }
            if (!key_exists('graphe', $result) || !key_exists('data', $result['graphe'])) {
                $this->logger->error('No data found in server response (hourly)');
                throw new Exception('No data found in server response (hourly)', 1);
            }
        } else {
            // Get data from mock
            $result = json_decode(file_get_contents('mock/hours.json'), true);
        }

        // Transform values
        $hoursValues = array();
        $startHour = clone $startDateDT;
        $this->logger->debug('Start datetime for processing data: '.$startHour->format('Y-m-d H:i:sP'));
        $data = $result['graphe']['data'];
        $count = count($data);
        $this->logger->debug("$count periods returned");
        $halfHour = new DateInterval('PT30M');
        for ($i = 0; $i < $count; $i++) {
            $valeur = $data[$i]['valeur'];
            if ($valeur === $this::DATA_NOT_AVAILABLE || $valeur === $this::DATA_NOT_REQUESTED) {
                $valeur = null;
            } else {
                $valeur = $valeur / 2;
            }
            $hoursValues[$startHour->getTimestamp()] = $valeur;
            // $this->logger->trace($startHour->format('Y-m-d H:i:sP').': '.$valeur);
            $startHour->add($halfHour);
        }
        return $hoursValues;
    }

    /**
     * Request daily measures starting from datetime if provided
     *
     * @param datetime $startDatetime (optional) starting date of requested measurements
     * @return Process result
     */
    public function getDailyMeasures($startDatetime)
    {
        // Handle start date
        try {
            if ($startDatetime === null) {
                // Get last daily data fetch date
                $last = $this->storage->get_last_fetch_date('daily');
                if ($last === null) {
                    $this->logger->error('No start date provided and can not get previous data, abort hourly data request');
                    return;
                }
                $startDatetime = $last;
            }
        } catch (Exception $e) {
            $this->logger->error('Failure during daily data processing');
            $this->logger->debug($e->getMessage());
            return;
        }

        // Get daily data from Enedis API
        $this->logger->info('Getting daily data from Enedis API');
        $todayDT = new DateTime('now');
        $startDatetime = $this->capDatetime($startDatetime, $todayDT, $this::DAILY_REQUEST_DURATION);

        // Find how many requests are needed to get all data from provided start date
        $dailyMaxDuration = new DateInterval($this::DAILY_REQUEST_DURATION);
        $requestsCount = ceil(date_diff($todayDT, $startDatetime)->days / $dailyMaxDuration->d);
        $this->logger->debug("$requestsCount requests to execute");

        // Execute requests
        $daysValues = [];
        $points = [];
        $endDatetime = (clone $startDatetime)->add($dailyMaxDuration);
        try {
            for ($i=0; $i < $requestsCount; $i++) {
                $this->logger->debug('request #'.$i.' from '.$startDatetime->format('Y-m-d H:i:sP').' to '.$endDatetime->format('Y-m-d H:i:sP'));
                $daysValues = $this->getDataPerDay($startDatetime, $endDatetime);
                $startDatetime->add($dailyMaxDuration);
                $startDatetime = $this->capDatetime($startDatetime, $todayDT, $this::DAILY_REQUEST_DURATION);
                $endDatetime->add($dailyMaxDuration);

                //Transform data for writing to database
                foreach ($daysValues as $timestamp => $value) {
                    if ($value !== null) {
                        array_push($points, $this->storage->createPoint('daily', $timestamp, (float) $value, []));
                    }
                }
            }
        } catch (Exception $e) {
            $this->logger->error('Failure during daily data requesting, try to write anyway');
            $this->logger->debug($e->getMessage());
        }
        
        // Write to storage
        try {
            if (count($points)) {
                $this->logger->debug('Writing daily data to database');
                $this->storage->writePoints($points);
            }
        } catch (Exception $e) {
            $this->logger->error('Failure during daily data writing');
            $this->logger->debug($e->getMessage());
        }
    }

    /**
     * Check if a date is later than max days from today and cap it if so
     *
     * @param datetime $datetime datetime to be capped
     * @param datetime $todayDatetime today datetime
     * @param string $maxIntervalSpec Interval specification
     *
     * @return datetime Capped datetime
     */
    private function capDatetime($datetime, $todayDatetime, $maxIntervalSpec)
    {
        $maxInterval = new DateInterval($maxIntervalSpec);
        $interval = date_diff($datetime, $todayDatetime);
        $days = $interval->days;
        if ($interval->invert) {
            // Datetime is in the future
            $days *= -1;
        }
        if ($days < $maxInterval->d) {
            // Datetime is too close to today, return today minus max allowed
            $this->logger->debug('Datetime '.$datetime->format('Y-m-d').' was too close to today and has been capped');
            return (clone $todayDatetime)->sub($maxInterval);
        }
        // Datetime is ok, leave it as it is
        return $datetime;
    }

    /**
     * Request hourly measures from provided datetime
     *
     * @param datetime $startDateDT starting date of requested measurements
     * @param datetime $endDateDT ending date of requested measurements
     * @throws Exception If HTTP request failed or no data returned
     *
     * @return associative array of values by timestamp
     */
    private function getDataPerDay($startDateDT, $endDateDT)
    {
        // Set start and end dates
        $startDateDT->setTime(0, 0);
        $endDateDT->setTime(0, 0);
        $startDate = $startDateDT->format('d/m/Y');
        $endDate = $endDateDT->format('d/m/Y');
        $this->logger->debug("Start date: $startDate, end date: $endDate");

        if (!$this->isMocked) {
            // Get daily data from Enedis server
            try {
                $result = $this->getData('urlCdcJour', $startDate, $endDate);
            } catch (Exception $e) {
                $this->logger->error('Error during HTTP request daily measures');
                throw new Exception('Error during HTTP request daily measures', 1, $e);
            }
            if (!$result['etat'] || ($result['etat'] && $result['etat']['valeur'] === 'erreur')) {
                // Handle error
                if ($result['etat'] && $result['etat']['erreurText']) {
                    // Specific error
                    $this->logger->error('API returned following error:' . $result['etat']['erreurText']);
                    throw new Exception('API returned following error:' . $result['etat']['erreurText'], 1);
                } else {
                    // Generic error
                    $this->logger->error('API returned an unknown error');
                    throw new Exception('API returned an unknown error', 1);
                }
            }
            if ($result === null || !key_exists('graphe', $result) || !key_exists('data', $result['graphe'])) {
                $this->logger->error('No data found in server response (daily)');
                throw new Exception('No data found in server response (daily)', 1);
            }
        } else {
            // Get data from mock
            $result = json_decode(file_get_contents('mock/days.json'), true);
        }

        // Transform values
        $daysValues = array();
        $data = $result['graphe']['data'];
        $count = count($data);
        $this->logger->debug("$count days returned");
        $oneDay = new DateInterval('P1D');
        $datetime = clone $startDateDT;
        foreach ($data as $day) {
            $valeur = $day['valeur'];
            if ($valeur === $this::DATA_NOT_AVAILABLE || $valeur === $this::DATA_NOT_REQUESTED) {
                $valeur = null;
            }
            $daysValues[$datetime->getTimestamp()] = $valeur;
            // $this->logger->trace($datetime->format('Y-m-d H:i:sP').': '.$valeur);
            $datetime->add($oneDay);
        }
        return $daysValues;
    }

    /**
     * Close cURL session
     */
    public function closeSession()
    {
        curl_close($this->ch);
    }

    /**
     * Retrieve data from Enedis server
     *
     * @param string $resourceId Ressource identifier (hourly or daily)
     * @param string $startDate Start date as d/m/Y
     * @param string $endDate End date as d/m/Y
     * @throws Exception If HTTP request failed
     * @return array of data
     */
    private function getData($resourceId, $startDate, $endDate)
    {
        // Set query string
        $p_p_id = 'lincspartdisplaycdc_WAR_lincspartcdcportlet';

        $url = $this::BASE_URL.$this::DATA_PATH;
        $url .= '?p_p_id='.$p_p_id;
        $url .= '&p_p_lifecycle=2';
        $url .= '&p_p_state=normal';
        $url .= '&p_p_mode=view';
        $url .= '&p_p_resource_id='.$resourceId;
        $url .= '&p_p_cacheability=cacheLevelPage';
        $url .= '&p_p_col_id=column-1';
        $url .= '&p_p_col_count=3';

        // Set posted data in body
        $body = null;
        if ($startDate) {
            $body = http_build_query([
                '_'.$p_p_id.'_dateDebut' => $startDate,
                '_'.$p_p_id.'_dateFin' => $endDate
            ]);
        }

        // Execute request
        try {
            $response = $this->executeRequest('POST', $url, $body);
        } catch (Exception $e) {
            throw new Exception("Error during HTTP request", 1, $e);
        }
        return json_decode($response['body'], true);
    }

    /**
     * Execute an HTTP request
     *
     * @param string $method HTTP method (GET, POST)
     * @param string $url Requested URL
     * @param string $body URL-encoded query string
     * @throws Exception If HTTP request failed
     */
    private function executeRequest($method, $url, $body = null)
    {
        // Santize url
        $url = filter_var($url, FILTER_SANITIZE_URL);
        $this->logger->debug("HTTP request: $method $url");

        // Handle cURL session
        if ($this->ch === null) {
            $this->logger->trace('Create cURL session');
            $this->ch = curl_init();
            $options = [
                CURLOPT_COOKIEJAR => '',
                CURLOPT_COOKIEFILE => '',
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HEADER => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_REFERER => $this::BASE_URL.$this::DATA_PATH,
                CURLOPT_USERAGENT => 'User-Agent: '.$this::USER_AGENT
            ];
        } else {
            $options = [];
        }
        $options[CURLOPT_URL] = $url;
        $options[CURLOPT_TIMEOUT] = $this::TIMEOUT;
        if ($method == 'POST') {
            $options[CURLOPT_RETURNTRANSFER] = true;
            $options[CURLOPT_POST] = true;
        } else {
            $options[CURLOPT_POST] = false;
        }
        if (isset($body)) {
            $options[CURLOPT_CUSTOMREQUEST] = 'POST';
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($this->ch, $options);

        // Execute cURL session
        $response = curl_exec($this->ch);

        // Handle result
        $info = curl_getinfo($this->ch);
        $this->logger->debug('HTTP code: '.$info['http_code'].', duration: '.$info['total_time'].' sec');
        if ($response === false || curl_errno($this->ch)) {
            $error = curl_error($this->ch);
            $this->logger->debug("Error connecting API $url: $error", 1);
            throw new Exception("Error connecting API $url: $error", 1);
        }
        $header_size = $info['header_size'];
        $header = substr($response, 0, $header_size);
        $body = substr($response, $header_size);

        return [
            'header' => $header,
            'body' => $body
        ];
    }
}
