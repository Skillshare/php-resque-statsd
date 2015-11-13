<?php
/**
 * php-resque-statsd
 *
 * @package		php-resque-statsd
 * @author		Chris Boulton <chris@bigcommerce.com>
 * @copyright	(c) 2012 Chris Boulton
 * @license		http://www.opensource.org/licenses/mit-license.php
 */
class ResqueStatsD
{
    const STATSD_TIMER   = 'ms';
    const STATSD_COUNTER = 'c';

    /**
     * @var string Prefix to add to metrics submitted to StatsD.
     */
    private static $prefix = 'resque';

    /**
     * @var string Hostname when connecting to StatsD.
     */
    private static $host   = 'localhost';

    /**
     * @var int Port StatsD is running on.
     */
    private static $port   = 8125;

    /**
     * Register php-resque-statsd in php-resque.
     *
     * Register all callbacks in php-resque for when a job is run. This is
     * automatically called at the bottom of this script if the appropriate
     * Resque classes are loaded.
     */
    public static function register()
    {
        // Core php-resque events
        Resque_Event::listen('afterEnqueue', 'ResqueStatsd::afterEnqueue');
        Resque_Event::listen('beforeFork', 'ResqueStatsd::beforeFork');
        Resque_Event::listen('afterPerform', 'ResqueStatsd::afterPerform');
        Resque_Event::listen('onFailure', 'ResqueStatsd::onFailure');

        // Add support for php-resque-scheduler
        Resque_Event::listen('afterSchedule', 'ResqueStatsd::afterSchedule');
    }

    /**
     * Set the host/port combination of StatsD.
     *
     * @param string $host Hostname/IP of StatsD server.
     * @param int $port Port StatsD is listening on.
     */
    public static function setHost($host, $port)
    {
        self::$host = $host;
        self::$port = $port;
    }

    /**
     * Override the prefix for metrics that are submitted to StatsD.
     *
     * @param string $prefix Prefix to use for metrics.
     */
    public static function setPrefix($prefix)
    {
        self::$prefix = $prefix;
    }

    /**
     * Submit metrics for a queue and job whenever a job is pushed to a queue.
     *
     * @param string $class Class name of the job that was just created.
     * @param array $args Arguments passed to the job.
     * @param string $queue Name of the queue the job was created in.
     */
    public static function afterEnqueue($class, $args, $queue)
    {
        self::sendMetric(self::STATSD_COUNTER, 'queue.' . $queue . '.enqueued', 1);
        self::sendMetric(self::STATSD_COUNTER, 'job.' . $class . '.enqueued', 1);
    }

    /**
     * Submit metrics for a queue and job whenever a job is scheduled in php-resque-scheduler.
     *
     * @param DateTime|int $at Instance of PHP DateTime object or int of UNIX timestamp.
     * @param string $class Class name of the job that was just created.
     * @param array $args Arguments passed to the job.
     * @param string $queue Name of the queue the job was created in.
     */
    public static function afterSchedule($at, $queue, $class, $args)
    {
        self::sendMetric(self::STATSD_COUNTER, 'queue.' . $queue . '.scheduled', 1);
        self::sendMetric(self::STATSD_COUNTER, 'job.' . $class . '.scheduled', 1);
    }

    /**
     * Begin tracking execution time before forking out to run a job in a php-resque worker
     * and submits the metrics for the duration of a job spend waiting in the queue.
     *
     * Time tracking begins in `beforeFork` to ensure that the time spent for forking
     * and any hooks registered for `beforePerform` is also tracked.
     *
     * @param Resque_Job $job Instance of Resque_Job for the job about to be run.
     */
    public static function beforeFork(Resque_Job $job)
    {
        $job->statsDStartTime = microtime(true);

        if (isset($job->payload['queue_time'])) {
            $queuedTime = round(microtime(true) - $job->payload['queue_time']) * 1000;
            self::sendMetric(self::STATSD_TIMER, 'queue.' . $job->queue . '.time_in_queue', $queuedTime);
            self::sendMetric(self::STATSD_TIMER, 'job.' . $job->payload['class'] . '.time_in_queue', $queuedTime);
        }
    }

    /**
     * Submit metrics for a queue and job as soon as job has finished executing successfully.
     *
     * @param Resque_Job $job Instance of Resque_Job for the job that's just been executed.
     */
    public static function afterPerform(Resque_Job $job)
    {
        self::sendMetric(self::STATSD_COUNTER, 'queue.' . $job->queue . '.finished', 1);
        self::sendMetric(self::STATSD_COUNTER, 'job.' . $job->payload['class'] . '.finished', 1);

        $executionTime = round(microtime(true) - $job->statsDStartTime) * 1000;
        self::sendMetric(self::STATSD_TIMER, 'queue.' . $job->queue . '.processed', $executionTime);
        self::sendMetric(self::STATSD_TIMER, 'job.' . $job->payload['class'] . '.processed', $executionTime);
    }

    /**
     * Submit metrics for a queue and job whenever a job fails to run.
     *
     * @param Exception $e Exception thrown by the job.
     * @param Resque_Job $job Instance of Resque_Job for the job that failed.
     */
    public static function onFailure(Exception $e, Resque_Job $job)
    {
        self::sendMetric(self::STATSD_COUNTER, 'queue.' . $job->queue . '.failed', 1);
        self::sendMetric(self::STATSD_COUNTER, 'job.' . $job->payload['class'] . '.failed', 1);
    }

    /**
     * Return a tuple containing the StatsD host and port to submit metrics to.
     *
     * Looks for environment variables STATSD_HOST, then GRAPHITE_HOST before
     * resorting to the host/port combination passed to `register`, or defaulting
     * to localhost. Port is determined in much the same way, however looks for
     * the STATSD_PORT environment variable.
     *
     * If the host variable includes a single colon, the first part of the string
     * is used for the host, and the second part for the port.
     *
     * @return array Array containing host and port.
     */
    private static function getStatsDHost()
    {
        $host = self::$host;
        $port = self::$port;

        if (!empty($_ENV['STATSD_HOST'])) {
            $host = $_ENV['STATSD_HOST'];
        }
        else if(!empty($_ENV['GRAPHITE_HOST'])) {
            $host = $_ENV['GRAPHITE_HOST'];
        }

        if (!empty($_ENV['STATSD_PORT'])) {
            $port = $_ENV['STATSD_PORT'];
        }

        if (substr_count($host, ':') == 1) {
            list($host, $port) = explode(':', $host);
        }

        return array($host, $port);
    }

    /**
     * Submit a metric of the given type, name and value to StatsD.
     *
     * @param string $type Type of metric to submit (c for counter, ms for timer)
     * @param string $name Name of the metric to submit. Will be prefixed.
     * @param int $value Value of the metric to submit.
     *
     * @return boolean True if the metric was submitted successfully.
     */
    private static function sendMetric($type, $name, $value)
    {
        list($host, $port) = self::getStatsDHost();

        if (empty($host) || empty($port)) {
            return false;
        }

        $fp = fsockopen('udp://' . $host, $port, $errno, $errstr);
        if (!$fp || $errno > 0) {
            return false;
        }

        $metric = self::$prefix . '.' . $name . ':' . $value . '|' . $type;
        if (!fwrite($fp, $metric)) {
            return false;
        }

        fclose($fp);
        return true;
    }
}
