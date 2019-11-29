<?php

namespace App\Services;

use App\Exceptions\UnableToExecuteRequestException;
use App\Routing\ActionContract;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use App\Http\Request;
use GuzzleHttp\Psr7\Response as PsrResponse;

/**
 * Class RestClient
 * @package App\Services
 */
class RestClient
{
    /**
     * @var Client
     */
    protected $client;

    /**
     * @var array
     */
    protected $guzzleParams = [
        'headers' => [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        ],
        'timeout' => 40
    ];

    /**
     * @var int
     */
    const USER_ID_ANONYMOUS = -1;

    /**
     * RestClient constructor.
     * @param array $options
     */
    public function __construct($options = [])
    {
        $this->client = new Client($options);
    }

    /**
     * @param array $headers
     */
    public function setHeaders(array $headers)
    {
        $this->guzzleParams['headers'] = $headers;
    }

    /**
     * @param $contentType
     * @return $this
     */
    public function setContentType($contentType)
    {
        $this->guzzleParams['headers']['Content-Type'] = $contentType;

        return $this;
    }

    /**
     * @param $contentSize
     * @return $this
     */
    public function setContentSize($contentSize)
    {
        $this->guzzleParams['headers']['Content-Length'] = $contentSize;

        return $this;
    }

    /**
     * @return array
     */
    public function getHeaders()
    {
        return $this->guzzleParams['headers'];
    }

    /**
     * @param string $body
     * @return $this
     */
    public function setBody($body)
    {
        $this->guzzleParams['body'] = $body;

        return $this;
    }

    /**
     * @param array $files
     * @return $this
     */
    public function setFiles($files)
    {
        // Get rid of everything else
        $this->setHeaders(array_intersect_key($this->getHeaders(), ['X-User' => null, 'X-Token-Scopes' => null]));

        if (isset($this->guzzleParams['body'])) unset($this->guzzleParams['body']);

        $this->guzzleParams['timeout'] = 20;
        $this->guzzleParams['multipart'] = [];

        foreach ($files as $key => $file) {
            $this->guzzleParams['multipart'][] = [
                'name' => $key,
                'contents' => fopen($file->getRealPath(), 'r'),
                'filename' => $file->getClientOriginalName()
            ];
        }

        return $this;
    }

    /**
     * @param $url
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function post($url)
    {
        return $this->client->post($url, $this->guzzleParams);
    }

    /**
     * @param $url
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function put($url)
    {
        return $this->client->put($url, $this->guzzleParams);
    }

    /**
     * @param $url
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function get($url)
    {
        return $this->client->get($url, $this->guzzleParams);
    }

    /**
     * @param $url
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function delete($url)
    {
        return $this->client->delete($url, $this->guzzleParams);
    }

    /**
     * @param Collection $batch
     * @param array $parametersJar
     * @return RestBatchResponse
     */
    public function asyncRequest(Collection $batch, array $parametersJar)
    {
        $wrapper = new RestBatchResponse();
        $wrapper->setCritical($batch->filter(function($action) { return $action->isCritical(); })->count());

        $promises = $batch->reduce(function($carry, $action) use ($parametersJar) {
            $method = strtolower($action->getMethod());
            $url = $this->buildUrl($action, $parametersJar);
            $carry[$action->getAlias()] = $this->client->{$method . 'Async'}($url, $this->guzzleParams);
            return $carry;
        }, []);

        return $this->processResponses(
            $wrapper,
            collect(Promise\settle($promises)->wait())
        );
    }

    /**
     * @param RestBatchResponse $wrapper
     * @param Collection $responses
     * @return RestBatchResponse
     */
    private function processResponses(RestBatchResponse $wrapper, Collection $responses)
    {
        // Process successful responses
        $responses->filter(function ($response) {
            return $response['state'] == 'fulfilled';
        })->each(function ($response, $alias) use ($wrapper) {
            $wrapper->addSuccessfulAction($alias, $response['value']);
        });

        // Process failures
        $responses->filter(function ($response) {
            return $response['state'] != 'fulfilled';
        })->each(function ($response, $alias) use ($wrapper) {
            $response = $response['reason']->getResponse();
            if ($wrapper->hasCriticalActions()) throw new UnableToExecuteRequestException($response);

            // Do we have an error response from the service?
            if (! $response) $response = new PsrResponse(502, []);
            $wrapper->addFailedAction($alias, $response);
        });

        return $wrapper;
    }

    /**
     * @param string $uri
     * @param string $method
     * @param array $parametersJar
     * @return PsrResponse
     * @throws UnableToExecuteRequestException
     */
    public function syncRequest( $uri, $method = 'get', $parametersJar= [])
    {
        try {
            $response = $this->{strtolower($method)}(
                $this->buildUrl($uri, $parametersJar)
            );
            $response = json_decode((string)$response->getBody());
        } catch (ConnectException $e) {
            throw new UnableToExecuteRequestException();
        } catch (RequestException $e) {
            return $e->getResponse();
        }

        return $response;
    }

    /**
     * @param string $url
     * @param array $params
     * @param string $prefix
     * @return string
     */
    private function injectParams($url, array $params, $prefix = '')
    {
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $url = $this->injectParams($url, $value, $prefix . $key . '.');
            }

            if (is_string($value) || is_numeric($value)) {
                $url = str_replace("{" . $prefix . $key . "}", $value, $url);
            }
        }

        return $url;
    }

    /**
     * @param string $url
     * @param $parametersJar
     * @return string
     */
    private function buildUrl($url, $parametersJar)
    {
        $url = $this->injectParams($url, $parametersJar);
        if ($url[0] != '/') $url = '/' . $url;
        if (isset($parametersJar['query_string'])) $url .= '?' . $parametersJar['query_string'];

        return $url;
    }
}
