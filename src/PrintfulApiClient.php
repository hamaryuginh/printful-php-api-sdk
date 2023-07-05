<?php

namespace Printful;

use Printful\Exceptions\PrintfulApiException;
use Printful\Exceptions\PrintfulException;

/**
 * Class PrintfulClient
 */
class PrintfulApiClient
{
    final public const LOCALE_EN_US = 'en_US';
    final public const LOCALE_ES_ES = 'es_ES';
    final public const LOCALE_FR_FR = 'fr_FR';
    final public const LOCALE_DE_DE = 'de_DE';
    final public const LOCALE_IT_IT = 'it_IT';
    final public const DEFAULT_LOCALE = self::LOCALE_EN_US;
    final public const AVAILABLE_LOCALES = [
        self::LOCALE_EN_US,
        self::LOCALE_ES_ES,
        self::LOCALE_FR_FR,
        self::LOCALE_DE_DE,
        self::LOCALE_IT_IT,
    ];

    final public const TYPE_LEGACY_STORE_KEY = 'legacy-store-key';
    final public const TYPE_OAUTH_TOKEN = 'oauth-token';
    final public const DEFAULT_KEY = self::TYPE_LEGACY_STORE_KEY;

    final public const USER_AGENT = 'Printful PHP API SDK 2.0';

    /**
     * Printful API key
     * @var string|null
     */
    protected ?string $legacyStoreKey;

    /**
     * Printful OAuth token
     * @var string|null
     */
    protected ?string $oauthToken;

    protected ?string $lastResponseRaw;

    protected ?array $lastResponse;

    protected string $currentLocale = self::DEFAULT_LOCALE;

    public string $url = 'https://api.printful.com/';

    /**
     * Maximum amount of time in seconds that is allowed to make the connection to the API server
     * @var int
     */
    public int $curlConnectTimeout = 20;

    /**
     * Maximum amount of time in seconds to which the execution of cURL call will be limited
     * @var int
     */
    public int $curlTimeout = 20;

    /**
     * @param string $key
     * @param string $type // PrintfulApiClient::TYPE_LEGACY_STORE_KEY or PrintfulApiClient::TYPE_OAUTH_TOKEN
     *
     * @throws PrintfulException if the library failed to initialize
     */
    public function __construct(string $key, string $type = self::DEFAULT_KEY)
    {
        if ($type === self::TYPE_LEGACY_STORE_KEY && strlen($key) < 32) {
            throw new PrintfulException('Invalid Printful store key!');
        }

        $this->legacyStoreKey = $type === self::TYPE_LEGACY_STORE_KEY ? $key : null;
        $this->oauthToken = $type === self::TYPE_OAUTH_TOKEN ? $key : null;
    }

    /**
     * @throws PrintfulException
     */
    public static function createOauthClient(string $oAuthToken): self
    {
        return new self($oAuthToken, self::TYPE_OAUTH_TOKEN);
    }

    /**
     * @throws PrintfulException
     */
    public static function createLegacyStoreKeyClient(string $legacyStoreKey): self
    {
        return new self($legacyStoreKey, self::TYPE_LEGACY_STORE_KEY);
    }

    public function getCurrentLocale(): string
    {
        return $this->currentLocale;
    }

    public function setCurrentLocale(string $currentLocale): self
    {
        if (in_array($currentLocale, self::AVAILABLE_LOCALES)) {
            $this->currentLocale = $currentLocale;
        }

        return $this;
    }

    /**
     * Returns total available item count from the last request if it supports paging (e.g order list) or null otherwise.
     */
    public function getItemCount(): ?int
    {
        return isset($this->lastResponse['paging']['total']) ? $this->lastResponse['paging']['total'] : null;
    }

    /**
     * Perform a GET request to the API
     *
     * @param string $path Request path (e.g. 'orders' or 'orders/123')
     * @param array  $params Additional GET parameters as an associative array
     *
     * @return mixed API response
     * @throws PrintfulApiException if the API call status code is not in the 2xx range
     * @throws PrintfulException if the API call has failed or the response is invalid
     */
    public function get(string $path, array $params = []): mixed
    {
        return $this->request('GET', $path, $params);
    }

    /**
     * Perform a DELETE request to the API
     *
     * @param string $path Request path (e.g. 'orders' or 'orders/123')
     * @param array  $params Additional GET parameters as an associative array
     *
     * @return mixed API response
     * @throws PrintfulApiException if the API call status code is not in the 2xx range
     * @throws PrintfulException if the API call has failed or the response is invalid
     */
    public function delete(string $path, array $params = []): mixed
    {
        return $this->request('DELETE', $path, $params);
    }

    /**
     * Perform a POST request to the API
     *
     * @param string $path Request path (e.g. 'orders' or 'orders/123')
     * @param array  $data Request body data as an associative array
     * @param array  $params Additional GET parameters as an associative array
     *
     * @return mixed API response
     * @throws PrintfulApiException if the API call status code is not in the 2xx range
     * @throws PrintfulException if the API call has failed or the response is invalid
     */
    public function post(string $path, array $data = [], array $params = []): mixed
    {
        return $this->request('POST', $path, $params, $data);
    }

    /**
     * Perform a PUT request to the API
     *
     * @param string $path Request path (e.g. 'orders' or 'orders/123')
     * @param array  $data Request body data as an associative array
     * @param array  $params Additional GET parameters as an associative array
     *
     * @return mixed API response
     * @throws PrintfulApiException if the API call status code is not in the 2xx range
     * @throws PrintfulException if the API call has failed or the response is invalid
     */
    public function put(string $path, array $data = [], array $params = []): mixed
    {
        return $this->request('PUT', $path, $params, $data);
    }

    /**
     * Return raw response data from the last request
     * @return string|null Response data
     */
    public function getLastResponseRaw(): ?string
    {
        return $this->lastResponseRaw;
    }

    /**
     * Return decoded response data from the last request
     * @return array|null Response data
     */
    public function getLastResponse(): ?array
    {
        return $this->lastResponse;
    }

    /**
     * Internal request implementation
     *
     * @param string     $method POST, GET, etc.
     * @param string     $path
     * @param array      $params
     * @param mixed|null $data
     *
     * @throws PrintfulApiException
     * @throws PrintfulException
     */
    protected function request(string $method, string $path, array $params = [], mixed $data = null): mixed
    {
        $this->lastResponseRaw = null;
        $this->lastResponse = null;

        $url = trim($path, '/');

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $curl = curl_init($this->url . $url);

        $this->setCredentials($curl);

        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_MAXREDIRS, 3);

        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $this->curlConnectTimeout);
        curl_setopt($curl, CURLOPT_TIMEOUT, $this->curlTimeout);

        curl_setopt($curl, CURLOPT_USERAGENT, self::USER_AGENT);

        curl_setopt($curl, CURLOPT_HTTPHEADER, ['X-PF-Language' => $this->getCurrentLocale()]);

        if ($data !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $this->lastResponseRaw = curl_exec($curl);

        $errorNumber = curl_errno($curl);
        $error = curl_error($curl);
        curl_close($curl);

        if ($errorNumber) {
            throw new PrintfulException('CURL: ' . $error, $errorNumber);
        }

        $this->lastResponse = $response = json_decode($this->lastResponseRaw, true);

        if (!isset($response['code'], $response['result'])) {
            $e = new PrintfulException('Invalid API response');
            $e->rawResponse = $this->lastResponseRaw;
            throw $e;
        }

        $status = (int)$response['code'];
        if ($status < 200 || $status >= 300) {
            $e = new PrintfulApiException((string)$response['result'], $status);
            $e->rawResponse = $this->lastResponseRaw;
            throw $e;
        }

        return $response['result'];
    }

    /**
     * @param resource $curl
     * @throws PrintfulException
     */
    protected function setCredentials($curl): void
    {
        if ($this->oauthToken !== null) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, ["Authorization: Bearer $this->oauthToken"]);
        } elseif ($this->legacyStoreKey !== null) {
            curl_setopt($curl, CURLOPT_USERPWD, $this->legacyStoreKey);
        } else {
            throw new PrintfulException('Either OAuth token or store key must be set to make this request.');
        }
    }
}
