<?php

/*
 * This file is part of the FOSElasticaBundle package.
 *
 * (c) FriendsOfSymfony <https://friendsofsymfony.github.com/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FOS\ElasticaBundle\Elastica;

use Elastic\Elasticsearch\Exception\ServerResponseException;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Elastic\Transport\Exception\NoNodeAvailableException;
use Elastica\Client as BaseClient;
use Elastica\Exception\ClientException;
use Elastica\Exception\ExceptionInterface;
use Elastica\Index as BaseIndex;
use Elastica\Request;
use Elastica\Response;
use FOS\ElasticaBundle\Logger\ElasticaLogger;
use Symfony\Component\Stopwatch\Stopwatch;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Extends the default Elastica client to provide logging for errors that occur
 * during communication with ElasticSearch.
 *
 * @author Gordon Franke <info@nevalon.de>
 */
class Client extends BaseClient
{
    /**
     * Stores created indexes to avoid recreation.
     *
     * @var array<string, BaseIndex>
     */
    private $indexCache = [];

    /**
     * Stores created index template to avoid recreation.
     *
     * @var array<string, IndexTemplate>
     */
    private $indexTemplateCache = [];

    /**
     * Symfony's debugging Stopwatch.
     *
     * @var Stopwatch|null
     */
    private $stopwatch;

    public function setLogger(LoggerInterface $logger): void
    {
        $this->_logger = $logger;
    }

    public function sendRequest(RequestInterface $request): Elasticsearch
    {
        if ($this->stopwatch) {
            $this->stopwatch->start('es_request', 'fos_elastica');
        }

        try {
            $start = \microtime(true);
            $result = parent::sendRequest($request);
            $end = \microtime(true);
        } catch (ServerResponseException | NoNodeAvailableException $e) {
            $this->logQuery(
                $request->getUri()->getPath(),
                $request->getMethod(),
                \json_decode($request->getBody()->__toString(), true),
                $request->getUri()->getQuery() ?? '',
                0,
                0,
                0
            );

            throw $e;
        }

        $responseData = $result->asArray();

        if (isset($responseData['took'], $responseData['hits'])) {
            $this->logQuery(
                $request->getUri()->getPath(),
                $request->getMethod(),
                \json_decode($request->getBody()->__toString(), true),
                $request->getUri()->getQuery() ?? '',
                $end - $start,
                $responseData['took'],
                $responseData['hits']['total']['value'] ?? 0
            );
        } else {
            $this->logQuery(
                $request->getUri()->getPath(),
                $request->getMethod(),
                \json_decode($request->getBody()->__toString(), true),
                $request->getUri()->getQuery() ?? '',
                $end - $start,
                0,
                0
            );
        }

        if ($this->stopwatch) {
            $this->stopwatch->stop('es_request');
        }

        return $result;
    }

    public function getIndex(string $name): BaseIndex
    {
        // TODO PHP >= 7.4 ??=
        return $this->indexCache[$name] ?? ($this->indexCache[$name] = new Index($this, $name));
    }

    /**
     * @param string $name
     */
    public function getIndexTemplate($name): IndexTemplate
    {
        // TODO PHP >= 7.4 ??=
        return $this->indexTemplateCache[$name] ?? ($this->indexTemplateCache[$name] = new IndexTemplate($this, $name));
    }

    /**
     * Sets a stopwatch instance for debugging purposes.
     */
    public function setStopwatch(?Stopwatch $stopwatch = null): void
    {
        $this->stopwatch = $stopwatch;
    }

    /**
     * Log the query if we have an instance of ElasticaLogger.
     *
     * @param array<mixed>|string $data
     * @param string              $query
     * @param float               $queryTime
     * @param int                 $engineMS
     */
    private function logQuery(string $path, string $method, $data, string $query, $queryTime, $engineMS = 0, int $itemCount = 0): void
    {
        if (!$this->_logger instanceof ElasticaLogger) {
            return;
        }

        $uri = $this->getTransport()->getLastRequest()->getUri();
        $config = $this->getConfig();

        $connectionArray = [
            'host' => $uri->getHost(),
            'port' => $uri->getPort(),
            'transport' => $config['transport_config'] ?? [],
            'headers' => $this->getLastRequest()->getHeaders(),
        ];

        $this->_logger->logQuery($path, $method, $data, $queryTime, $connectionArray, $query, $engineMS, $itemCount);
    }
}
