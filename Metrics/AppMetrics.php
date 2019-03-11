<?php
declare(strict_types=1);

namespace Artprima\PrometheusMetricsBundle\Metrics;

use Prometheus\CollectorRegistry;
use Prometheus\Exception\MetricNotFoundException;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\PostResponseEvent;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * Class AppMetrics.
 */
class AppMetrics implements MetricsGeneratorInterface
{
    private const REQUEST_LABELS = ['method', 'path'];
    private const RESPONSE_LABELS = ['method', 'path', 'code'];
    private const ANY_VALUE_PATTERN = '*';

    /**
     * @var Stopwatch
     */
    private $stopwatch;

    /**
     * @var string
     */
    private $namespace;

    /**
     * @var CollectorRegistry
     */
    private $collectionRegistry;

    /**
     * @param string            $namespace
     * @param CollectorRegistry $collectionRegistry
     */
    public function init(string $namespace, CollectorRegistry $collectionRegistry): void
    {
        $this->stopwatch = new Stopwatch();
        $this->namespace = $namespace;
        $this->collectionRegistry = $collectionRegistry;
    }

    /**
     * @param string $value
     *
     * @throws \Prometheus\Exception\MetricsRegistrationException
     */
    private function setInstance(string $value): void
    {
        $name = 'instance_name';
        try {
            // the trick with try/catch let's us setting the instance name only once
            $this->collectionRegistry->getGauge($this->namespace, $name);
        } catch (MetricNotFoundException $e) {
            /** @noinspection PhpUnhandledExceptionInspection */
            $gauge = $this->collectionRegistry->registerGauge(
                $this->namespace,
                $name,
                'app instance name',
                ['instance']
            );
            $gauge->set(1, [$value]);
        }
    }

    /**
     * @param null|string $method
     * @param null|string $path
     */
    private function incRequestsTotal(?string $method = null, ?string $path = null): void
    {
        $counter = $this->collectionRegistry->getOrRegisterCounter(
            $this->namespace,
            'http_requests_total',
            'total request count',
            static::REQUEST_LABELS
        );

        $counter->inc(array_fill_keys(static::REQUEST_LABELS, static::ANY_VALUE_PATTERN));

        if (null !== $method && null !== $path) {
            $counter->inc(compact(...static::REQUEST_LABELS));
        }
    }

    /**
     * @param null|string $method
     * @param null|string $path
     * @param null|int    $code
     */
    private function incResponsesTotal(?string $method = null, ?string $path = null, ?int $code = null): void
    {
        $counter = $this->collectionRegistry->getOrRegisterCounter(
            $this->namespace,
            'http_responses_total',
            'total response count',
            static::RESPONSE_LABELS
        );

        $counter->inc(array_fill_keys(static::RESPONSE_LABELS, static::ANY_VALUE_PATTERN));

        if (null !== $method && null !== $path && null !== $code) {
            $counter->inc(compact(...static::RESPONSE_LABELS));
        }
    }

    /**
     * @param float       $duration
     * @param null|string $method
     * @param null|string $path
     */
    private function setRequestDuration(float $duration, ?string $method = null, ?string $path = null): void
    {
        $histogram = $this->collectionRegistry->getOrRegisterHistogram(
            $this->namespace,
            'request_durations_histogram_seconds',
            'request durations in seconds',
            static::REQUEST_LABELS
        );

        $histogram->observe($duration, array_fill_keys(static::REQUEST_LABELS, static::ANY_VALUE_PATTERN));

        if (null !== $method && null !== $path) {
            $histogram->observe($duration, compact(...static::REQUEST_LABELS));
        }
    }

    /**
     * @param GetResponseEvent $event
     *
     * @throws \Prometheus\Exception\MetricsRegistrationException
     */
    public function collectRequest(GetResponseEvent $event): void
    {
        $this->stopwatch->start('execution_time');

        $request = $event->getRequest();
        $requestMethod = $request->getMethod();
        $requestPath = $request->getPathInfo();

        // do not track "OPTIONS" requests
        if ('OPTIONS' === $requestMethod) {
            return;
        }

        $this->setInstance(getenv('APP__ENV_NAME'));
        $this->incRequestsTotal($requestMethod, $requestPath);
    }

    /**
     * @param PostResponseEvent $event
     */
    public function collectResponse(PostResponseEvent $event): void
    {
        $evt = $this->stopwatch ? $this->stopwatch->stop('execution_time') : null;

        $response = $event->getResponse();
        $request = $event->getRequest();

        $requestMethod = $request->getMethod();
        $requestPath = $request->getPathInfo();

        $this->incResponsesTotal($requestMethod, $requestPath, $response->getStatusCode());

        if (null !== $evt) {
            $this->setRequestDuration($evt->getDuration() / 1000, $requestMethod, $requestPath);
        }
    }
}
