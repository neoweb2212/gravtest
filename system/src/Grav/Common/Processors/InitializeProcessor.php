<?php

/**
 * @package    Grav\Common\Processors
 *
 * @copyright  Copyright (C) 2015 - 2020 Trilby Media, LLC. All rights reserved.
 * @license    MIT License; see LICENSE file for details.
 */

namespace Grav\Common\Processors;

use Grav\Common\Config\Config;
use Grav\Common\Debugger;
use Grav\Common\Errors\Errors;
use Grav\Common\Grav;
use Grav\Common\Page\Pages;
use Grav\Common\Plugins;
use Grav\Common\Session;
use Grav\Common\Uri;
use Grav\Common\Utils;
use Grav\Framework\File\Formatter\YamlFormatter;
use Grav\Framework\File\YamlFile;
use Grav\Framework\Psr7\Response;
use Grav\Framework\Session\Exceptions\SessionException;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\SyslogHandler;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use function defined;

/**
 * Class InitializeProcessor
 * @package Grav\Common\Processors
 */
class InitializeProcessor extends ProcessorBase
{
    /** @var string */
    public $id = '_init';
    /** @var string */
    public $title = 'Initialize';

    /** @var bool */
    private static $cli_initialized = false;

    /**
     * @param Grav $grav
     * @return void
     */
    public static function initializeCli(Grav $grav)
    {
        if (!static::$cli_initialized) {
            static::$cli_initialized = true;

            $instance = new static($grav);
            $instance->processCli();
        }
    }

    /**
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->startTimer('_init', 'Initialize');

        // Load configuration.
        $config = $this->initializeConfig();

        // Initialize logger.
        $this->initializeLogger($config);

        // Initialize error handlers.
        $this->initializeErrors();

        // Initialize debugger.
        $debugger = $this->initializeDebugger();

        // Debugger can return response right away.
        $response = $this->handleDebuggerRequest($debugger, $request);
        if ($response) {
            $this->stopTimer('_init');

            return $response;
        }

        // Initialize output buffering.
        $this->initializeOutputBuffering($config);

        // Set timezone, locale.
        $this->initializeLocale($config);

        // Load plugins.
        $this->initializePlugins();

        // Load pages.
        $this->initializePages($config);

        // Initialize URI.
        $uri = $this->initializeUri($config);

        // Grav may return redirect response right away.
        $response = $this->handleRedirectRequest($config, $uri);
        if ($response) {
            $this->stopTimer('_init');

            return $response;
        }

        // Load accounts (decides class to be used).
        // TODO: remove in 2.0.
        $this->container['accounts'];

        // Initialize session.
        $this->initializeSession($config);

        $this->stopTimer('_init');

        // Wrap call to next handler so that debugger can profile it.
        /** @var Response $response */
        $response = $debugger->profile(static function () use ($handler, $request) {
            return $handler->handle($request);
        });

        // Log both request and response and return the response.
        return $debugger->logRequest($request, $response);
    }

    public function processCli(): void
    {
        // Load configuration.
        $config = $this->initializeConfig();

        // Initialize logger.
        $this->initializeLogger($config);

        // Disable debugger.
        $this->container['debugger']->enabled(false);

        // Set timezone, locale.
        $this->initializeLocale($config);

        // Load plugins.
        $this->initializePlugins();

        // Load pages.
        $this->initializePages($config);

        // Initialize URI.
        $this->initializeUri($config);

        // Load accounts (decides class to be used).
        // TODO: remove in 2.0.
        $this->container['accounts'];
    }

    /**
     * @return Config
     */
    protected function initializeConfig(): Config
    {
        $this->startTimer('_init_config', 'Configuration');

        // Initialize Configuration
        $grav = $this->container;

        /** @var Config $config */
        $config = $grav['config'];
        $config->init();
        $grav['plugins']->setup();

        if (defined('GRAV_SCHEMA') && $config->get('versions') === null) {
            $filename = GRAV_ROOT . '/user/config/versions.yaml';
            if (!is_file($filename)) {
                $versions = [
                    'core' => [
                        'grav' => [
                            'version' => GRAV_VERSION,
                            'schema' => GRAV_SCHEMA
                        ]
                    ]
                ];
                $config->set('versions', $versions);

                $file = new YamlFile($filename, new YamlFormatter(['inline' => 4]));
                $file->save($versions);
            }
        }

        $this->stopTimer('_init_config');

        return $config;
    }

    /**
     * @param Config $config
     * @return Logger
     */
    protected function initializeLogger(Config $config): Logger
    {
        $this->startTimer('_init_logger', 'Logger');

        $grav = $this->container;

        // Initialize Logging
        /** @var Logger $log */
        $log = $grav['log'];

        if ($config->get('system.log.handler', 'file') === 'syslog') {
            $log->popHandler();

            $facility = $config->get('system.log.syslog.facility', 'local6');
            $logHandler = new SyslogHandler('grav', $facility);
            $formatter = new LineFormatter("%channel%.%level_name%: %message% %extra%");
            $logHandler->setFormatter($formatter);

            $log->pushHandler($logHandler);
        }

        $this->stopTimer('_init_logger');

        return $log;
    }

    /**
     * @return Errors
     */
    protected function initializeErrors(): Errors
    {
        $this->startTimer('_init_errors', 'Error Handlers Reset');

        $grav = $this->container;

        // Initialize Error Handlers
        /** @var Errors $errors */
        $errors = $grav['errors'];
        $errors->resetHandlers();

        $this->stopTimer('_init_errors');

        return $errors;
    }

    /**
     * @return Debugger
     */
    protected function initializeDebugger(): Debugger
    {
        $this->startTimer('_init_debugger', 'Init Debugger');

        $grav = $this->container;

        /** @var Debugger $debugger */
        $debugger = $grav['debugger'];
        $debugger->init();

        $this->stopTimer('_init_debugger');

        return $debugger;
    }

    /**
     * @param Debugger $debugger
     * @param ServerRequestInterface $request
     * @return ResponseInterface|null
     */
    protected function handleDebuggerRequest(Debugger $debugger, ServerRequestInterface $request): ?ResponseInterface
    {
        // Clockwork integration.
        $clockwork = $debugger->getClockwork();
        if ($clockwork) {
            $server = $request->getServerParams();
//            $baseUri = str_replace('\\', '/', dirname(parse_url($server['SCRIPT_NAME'], PHP_URL_PATH)));
//            if ($baseUri === '/') {
//                $baseUri = '';
//            }
            $requestTime = $server['REQUEST_TIME_FLOAT'] ?? GRAV_REQUEST_TIME;

            $request = $request->withAttribute('request_time', $requestTime);

            // Handle clockwork API calls.
            $uri = $request->getUri();
            if (Utils::contains($uri->getPath(), '/__clockwork/')) {
                return $debugger->debuggerRequest($request);
            }

            $this->container['clockwork'] = $clockwork;
        }

        return null;
    }

    /**
     * @param Config $config
     */
    protected function initializeOutputBuffering(Config $config): void
    {
        $this->startTimer('_init_ob', 'Initialize Output Buffering');

        // Use output buffering to prevent headers from being sent too early.
        ob_start();
        if ($config->get('system.cache.gzip') && !@ob_start('ob_gzhandler')) {
            // Enable zip/deflate with a fallback in case of if browser does not support compressing.
            ob_start();
        }

        $this->stopTimer('_init_ob');
    }

    /**
     * @param Config $config
     */
    protected function initializeLocale(Config $config): void
    {
        $this->startTimer('_init_locale', 'Initialize Locale');

        // Initialize the timezone.
        $timezone = $config->get('system.timezone');
        if ($timezone) {
            date_default_timezone_set($timezone);
        }

        $grav = $this->container;
        $grav->setLocale();

        $this->stopTimer('_init_locale');
    }

    protected function initializePlugins(): Plugins
    {
        $this->startTimer('_init_plugins_load', 'Load Plugins');

        $grav = $this->container;

        /** @var Plugins $plugins */
        $plugins = $grav['plugins'];
        $plugins->init();

        $this->stopTimer('_init_plugins_load');

        return $plugins;
    }

    protected function initializePages(Config $config): Pages
    {
        $this->startTimer('_init_pages_register', 'Load Pages');

        $grav = $this->container;

        /** @var Pages $pages */
        $pages = $grav['pages'];
        // Upgrading from older Grav versions won't work without checking if the method exists.
        if (method_exists($pages, 'register')) {
            $pages->register();
        }

        $this->stopTimer('_init_pages_register');

        return $pages;
    }


    protected function initializeUri(Config $config): Uri
    {
        $this->startTimer('_init_uri', 'Initialize URI');

        $grav = $this->container;

        /** @var Uri $uri */
        $uri = $grav['uri'];
        $uri->init();

        $this->stopTimer('_init_uri');

        return $uri;
    }

    protected function handleRedirectRequest(Config $config, Uri $uri): ?ResponseInterface
    {
        $grav = $this->container;

        // Redirect pages with trailing slash if configured to do so.
        $path = $uri->path() ?: '/';
        if ($path !== '/'
            && $config->get('system.pages.redirect_trailing_slash', false)
            && Utils::endsWith($path, '/')
        ) {
            $redirect = $uri::getCurrentRoute()->toString();

            return $grav->getRedirectResponse($redirect);
        }

        return null;
    }

    /**
     * @param Config $config
     */
    protected function initializeSession(Config $config): void
    {
        // FIXME: Initialize session should happen later after plugins have been loaded. This is a workaround to fix session issues in AWS.
        if (isset($this->container['session']) && $config->get('system.session.initialize', true)) {
            $this->startTimer('_init_session', 'Start Session');

            /** @var Session $session */
            $session = $this->container['session'];

            try {
                $session->init();
            } catch (SessionException $e) {
                $session->init();
                $message = 'Session corruption detected, restarting session...';
                $this->addMessage($message);
                $this->container['messages']->add($message, 'error');
            }

            $this->stopTimer('_init_session');
        }
    }
}
