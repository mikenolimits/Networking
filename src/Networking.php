<?php namespace Drapor\Networking;

/**
 * Created by PhpStorm.
 * User: michaelkantor
 * Date: 12/29/14
 * Time: 2:57 PM
 */
use GuzzleHttp\Client;
use GuzzleHttp\Stream\Stream;
use GuzzleHttp\Message\ResponseInterface;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Events\Dispatcher;
use Log;

class Networking
{

    /**
     * @var string
     */
    public $baseUrl;

    /**
     * @var string
     */
    public $scheme;

    /**
     * @var string
     */
    public $proxy;

    /**
     * @var array
     */
    public $auth;

    /**
     * @var array
     */
    public $headers;


    /**
     * @var array $options
     */
    public $options = [
        'body' => false,
        'query' => false
    ];

    /** @var $body array * */
    protected $body;

    /** @var $status_code Int * */
    protected $status_code;

    /** @var $response ResponseInterface * */
    protected $response;

    /** @var $request RequestInterface * */
    protected $request;

    /** @var array $cookies * */
    protected $cookies;

    /** @var string $url * */
    protected $url;

    /** @var $dispatcher Dispatcher * */
    protected $dispatcher;

    /**
     * Override to create new Networking Object
     * Without Extending.
     *
     */
    public function __construct()
    {
        $this->setDispatcher();
    }

    /**
     *
     */
    private function setDispatcher()
    {
        $this->dispatcher = new Dispatcher();
    }

    /**
     * If you want to encode any body or query parameters
     * then you call this method to set a new array of options.
     * @param array $options
     */
    public function setOptions(array $options)
    {
        $this->options = $options;
    }

    /**
     * Unless $fields['body'] or $fields['query'] is specified, they will not
     * be sent in the http request.
     * @param $fields
     * @param $endpoint
     * @param $type
     * @return array
     */
    public function send(array $fields, $endpoint, $type)
    {
        try {
            $this->createRequest($fields, $endpoint, $type);
        } catch (RequestException $e) {
            $this->setResponse($e->getResponse());
        }

        $body        = $this->getBody();
        $status_code = $this->getStatusCode();
        $cookie      = $this->getCookies();

        $response = [
            'body' => $body,
            'status_code' => $status_code,
            'cookie' => $cookie
        ];

        return $response;
    }

    /**
     * @param array  $fields
     * @param        $endpoint
     * @param string $type
     *
     * @return void
     */
    private function createRequest(array $fields = [], $endpoint, $type = "get")
    {

        \Log::info("Logging requests headers..");
        \Log::info($this->headers);

        $this->setUrl($this->baseUrl . $endpoint);


        $client = $this->getClient();
        $jar    = $this->getCookieJar();
        $url    = $this->getUrl();
        $opts   = $this->configureRequest($fields, $jar);

        $request  = $client->createRequest($type, $url, $opts);
        $response = $client->send($request);

        $this->setRequest($request);
        $this->setResponse($response);
        $this->setCookies($jar);
    }

    /**
     * @param $fields
     * @param $endpoint
     * @param $proxy
     * @return \GuzzleHttp\Message\ResponseInterface
     */
    public function createStreamRequest(array $fields, $endpoint)
    {
        $body = json_encode($fields);

        $guzzle = $this->getClient();

        $req = $guzzle->createRequest('POST', $endpoint);
        $req->setScheme($this->scheme);
        $req->setBody(Stream::factory($body));

        $response = $guzzle->send($req);

        return $response;
    }

    /**
     * @param array $fields
     * @param       $jar
     *
     * @return array
     */
    private function configureRequest(array $fields, $jar)
    {

        $opts = [
            'headers' => $this->headers,
            'cookies' => $jar
        ];

        if (!empty($fields)) {
            $config = $this->getOptions();
            if ($config['body']) {
                $opts['body'] = $fields;
            }
            if ($config['query']) {
                $opts['query'] = $fields;
            }
            if($config['allow_redirects']){
                $opts['allow_redirects'] = [
                    'max'       => 10,       // allow at most 10 redirects.
                    'strict'    => true,     // use "strict" RFC compliant redirects.
                    'referer'   => true,     // add a Referer header
                    'protocols' => [$this->scheme] // only allow https URLs
                ];
            }
        }
        return $opts;
    }


    /**
     * @return Client
     */
    private function getClient()
    {

        $defaults = array();

        if (!empty($this->proxy)) {
            $defaults['proxy'] = $this->proxy;
        }
        if (!empty($this->auth)) {
            $defaults['auth'] = $this->auth;
        }

        $guzzle = new Client([
            'base_url' => $this->url,
            'defaults' => $defaults
        ]);

        return $guzzle;
    }

    private function getCookieJar()
    {
        return new CookieJar;
    }

    /**
     * @return string
     */
    private function getUrl()
    {
        return $this->url;
    }

    /**
     * @param string $url
     */
    private function setUrl($url)
    {
        $this->url = $url;
    }

    /**
     * @return array
     */
    private function getCookies()
    {
        Log::info("Logging requests cookies...");
        Log::info($this->cookies);
        return $this->cookies;
    }

    /**
     * @param CookieJar $jar
     */
    private function setCookies($jar)
    {
        \Log::info($this->getResponse()->getHeader('Set-Cookie'));
        $jar->extractCookies($this->getRequest(), $this->getResponse());
        $this->cookies = $jar->toArray();

        $this->getDispatcher()->fire('response.created', [
            'status_code' => $this->getStatusCode(),
            'body' =>    $this->getBody(),
            'url' =>     $this->getUrl(),
            'headers' => $this->headers,
            'cookies' => $this->getCookies()
        ]);

    }

    /**
     * @return array
     */
    private function getOptions()
    {
        return $this->options;
    }

    /**
     * @return RequestInterface
     */
    private function getRequest()
    {
        return $this->request;
    }

    /**
     * @param RequestInterface $request
     */
    private function setRequest($request)
    {
        $this->request = $request;
    }


    /**
     * @return \GuzzleHttp\Message\ResponseInterface
     */
    private function getResponse()
    {
        return $this->response;
    }

    /**
     * Set the response & related info from the response.
     * @param ResponseInterface $response
     */
    private function setResponse($response)
    {
        $this->setBody(json_decode($response->getBody(),true));
        $this->setStatusCode($response->getStatusCode());
        $this->response = $response;
    }

    /**
     * @return Dispatcher
     */
    private function getDispatcher()
    {
        return $this->dispatcher;
    }

    /**
     * @return Int
     */
    private function getStatusCode()
    {
        return $this->status_code;
    }

    /**
     * @param Int $status_code
     */
    private function setStatusCode($status_code)
    {
        $this->status_code = $status_code;
    }

    /**
     * @return array
     */
    public function getBody()
    {
        return $this->body;
    }

    /**
     * @param array $body
     */
    private function setBody(array $body)
    {
        $this->body = $body;
    }

}