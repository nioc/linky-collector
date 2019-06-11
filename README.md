# Linky collector

[![license: AGPLv3](https://img.shields.io/badge/license-AGPLv3-blue.svg)](https://www.gnu.org/licenses/agpl-3.0)
[![GitHub release](https://img.shields.io/github/release/nioc/linky-collector.svg)](https://github.com/nioc/linky-collector/releases/latest)
[![Codacy grade](https://img.shields.io/codacy/grade/097d12a798f24ac98696c5a0e164b0d6.svg)](https://www.codacy.com/app/nioc/linky-collector)

Linky collector is a PHP script for requesting measures from Linky communicating meter.

## Key features

-   Automatic script (can be used with cron for example),
-   Initialization step (providing a start date and wait for the magic),
-   Store measures into an InfluxDB database,
-   Explore data with Grafana.

## Installation

### Enedis/Linky

You need to allow consumption recording on [Enedis Portal](https://espace-client-particuliers.enedis.fr/group/espace-particuliers/courbe-de-charge). It may take a few weeks before record is effective.

### Script

Download this project and extract files to directory of your choice.

Configure your Enedis credentials and off-peak periods (depends option subscribed) in `config.php`.

### Dependencies

Install dependencies with [composer](https://getcomposer.org/): `composer install`.

### InfluxDB

You need [InfluxDB](https://docs.influxdata.com/influxdb/v1.7/introduction/installation/) installed.

Default values are ok, but configuration can be changed (see [docs](https://docs.influxdata.com/influxdb/v1.7/administration/config/)).

Script will create database.

### Grafana

You need [Grafana](https://grafana.com/grafana/download) installed.

Coming soon :)...

## Usage

### Initialization

In order to collect oldest measures, script accepts a start date (YYYY-MM-DD) as an optional argument.

Open a shell, go in script directory and execute it: `php -f index.php 2018-12-31`.

### Scheduling repeated executions

Add to your scheduler (cron for exemple) following command (change the path `/usr/local/bin/linky-collector/` according to your installation):

```shell
# /etc/cron.d/linky-collect: crontab fragment for requesting Linky measures
# Requesting Linky measures and storing to database every day at 02:00
 0 2    * * *     root   php -f /usr/local/bin/linky-collector/index.php >> /var/log/syslog 2>&1
```

### Logs

Log settings can be found in `config.xml` file.

In production mode, the default configuration use a file (`linky-collect.log`) for logging at level `INFO`.

For debugging, you can output to console and set a more verbose level (`DEBUG` or even `TRACE`) by overriding the `root` section:

```xml
  <root>
    <level value="DEBUG"/>
    <appender_ref ref="console"/>
  </root>
```

## Versioning

This project is maintained under the [semantic versioning](https://semver.org/) guidelines.

See the [releases](https://github.com/nioc/linky-collector/releases) on this repository for changelog.

## Contributing

Pull requests are welcomed.

## Credits

-   **[Nioc](https://github.com/nioc/)** - _Initial work_

See also the list of [contributors](https://github.com/nioc/linky-collector/contributors) to this project.

This project is powered by the following components:

-   [influxdb-php](https://github.com/influxdata/influxdb-php) (MIT)
-   [Apache log4php](http://logging.apache.org/log4php/) (Apache License)

## License

This project is licensed under the GNU Affero General Public License v3.0 - see the [LICENSE](LICENSE.md) file for details.
