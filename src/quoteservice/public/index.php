<?php
// Copyright The OpenTelemetry Authors
// SPDX-License-Identifier: Apache-2.0



declare(strict_types=1);

use DI\Bridge\Slim\Bridge;
use DI\ContainerBuilder;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use OpenTelemetry\API\Common\Instrumentation\Globals;
use OpenTelemetry\API\Common\Log\LoggerHolder;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SDK\Common\Configuration\Variables;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LogLevel;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Socket\SocketServer;
use Slim\Factory\AppFactory;
use Psr\Log\LoggerInterface;

require __DIR__ . '/../vendor/autoload.php';

LoggerHolder::set(
    new Logger('otel-php', [new StreamHandler('php://stdout', LogLevel::DEBUG)])
);


// Provided by platform vendor
class TraceProcessor
{
    public function __invoke(\Monolog\LogRecord $record): \Monolog\LogRecord
    {
        $span = Span::getCurrent();
        if ($span->isRecording()) {
            $record->extra['trace_id'] = $span->toSpanData()->getTraceId();
            $record->extra['span_id'] = $span->toSpanData()->getSpanId();
            $record->extra['parent_span_id'] = $span->toSpanData()->getParentSpanId();
        }
        return $record;
    }
}

// Instantiate PHP-DI ContainerBuilder
$containerBuilder = new ContainerBuilder();

// Set up settings
$settings = require __DIR__ . '/../app/settings.php';
$settings($containerBuilder);

// Set up dependencies
$dependencies = require __DIR__ . '/../app/dependencies.php';
$dependencies($containerBuilder);

// Build PHP-DI Container instance
$container = $containerBuilder->build();

// Instantiate the app
AppFactory::setContainer($container);
$app = Bridge::create($container);

// Register middleware
$app->addRoutingMiddleware();

// Register routes
$routes = require __DIR__ . '/../app/routes.php';
$routes($app);

// Add Body Parsing Middleware
$app->addBodyParsingMiddleware();

// Add Error Middleware
$errorMiddleware = $app->addErrorMiddleware(true, true, true);
Loop::get()->addSignal(SIGTERM, function() {
    exit;
});

/* workaround for non-async batch processors */
if (($tracerProvider = Globals::tracerProvider()) instanceof TracerProviderInterface) {
    Loop::addPeriodicTimer(Configuration::getInt(Variables::OTEL_BSP_SCHEDULE_DELAY)/1000, function() use ($tracerProvider) {
        $tracerProvider->forceFlush();
    });
}
if (($meterProvider = Globals::meterProvider()) instanceof MeterProviderInterface) {
    Loop::addPeriodicTimer(Configuration::getInt(Variables::OTEL_METRIC_EXPORT_INTERVAL)/1000, function() use ($meterProvider) {
        $meterProvider->forceFlush();
    });
}

// TODO TraceProcessor can be injected via configuration
$logger = $container->get(LoggerInterface::class);
$logger->pushProcessor(new \TraceProcessor());

$logger->info('Quote service start');

$server = new HttpServer(function (ServerRequestInterface $request) use ($app) {
    $response = $app->handle($request);
    echo sprintf('[%s] "%s %s HTTP/%s" %d %d %s',
        date('Y-m-d H:i:sP'),
        $request->getMethod(),
        $request->getUri()->getPath(),
        $request->getProtocolVersion(),
        $response->getStatusCode(),
        $response->getBody()->getSize(),
        PHP_EOL,
    );

    return $response;
});
$address = '0.0.0.0:' . getenv('QUOTE_SERVICE_PORT');
$socket = new SocketServer($address);
$server->listen($socket);

echo "Listening on: {$address}" . PHP_EOL;
