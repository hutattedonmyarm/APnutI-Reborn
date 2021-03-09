<?php

namespace APnutI;

use APnutI\Entities\Post;
use APnutI\Entities\User;
use APnutI\Exceptions\PnutException;
use APnutI\Exceptions\NotFoundException;
use APnutI\Exceptions\NotAuthorizedException;
use APnutI\Exceptions\HttpPnutException;
use APnutI\Exceptions\HttpPnutRedirectException;
use APnutI\Meta;
use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class APnutI
{
  protected string $api_url = 'https://api.pnut.io/v1/';
  protected string $auth_url = 'https://pnut.io/oauth/authenticate';
  protected string $client_secret;
  protected string $client_id;
  protected string $scope = "";
  protected string $redirect_uri;
  protected int $rate_limit = 40;
  protected int $rate_limit_remaining = 40;
  protected int $rate_limit_reset = 60;
  protected array $scopes = [];
  protected ?string $needed_scope;
  protected ?string $redirect_target = null;
  protected array $headers = [];
  protected string $app_name = 'Abstract API';
  protected ?string $server_token;
  protected ?string $access_token;
  protected LoggerInterface $logger;
  protected string $token_session_key;
  protected ?string $server_token_file_path = null;

  public ?Meta $meta = null;

  /*
   * Error codes:
   * 3XX: Pnut error
   *  - 300: Cannot fetch post creator
   *  - 301: Missing field of post creator (e.g. has no avatar)
   * 4XX: User error (post not found, yada yada)
   *  - 400: Missing parameter
   *  - 404: Not found
   * 5XX: Generic server error
   */

  public static $ERROR_FETCHING_CREATOR = 300;
  public static $ERROR_FETCHING_CREATOR_FIELD = 301;
  public static $ERROR_MISSING_PARAMETER = 400;
  public static $ERROR_NOT_FOUND = 404;
  public static $ERROR_UNKNOWN_SERVER_ERROR = 500;

  public static $POST_MAX_LENGTH;
  public static $POST_MAX_LENGTH_REPOST;
  public static $POST_SECONDS_BETWEEN_DUPLICATES;
  public static $MESSAGE_MAX_LENGTH;
  public static $RAW_MAX_LENGTH;
  public static $USER_DESCRIPTION_MAX_LENGTH;
  public static $USER_USERNAME_MAX_LENGTH;

  public function __construct(?string $log_path = null)
  {
    $this->logger = empty($log_path) ? new NullLogger() : new Logger($this->app_name);
    $this->token_session_key = $this->app_name.'access_token';
    $handler = new RotatingFileHandler($log_path, 5, Logger::DEBUG, true);
    $this->logger->pushHandler($handler);
    $this->server_token = null;
    $this->logger->debug('__construct API');
    if (isset($_SESSION[$this->token_session_key])) {
      $this->access_token = $_SESSION[$this->token_session_key];
      $this->logger->debug('Access token in session');
    } else {
      $this->logger->debug('No access token in session');
    }
  }

  /**
   * Internal function, parses out important information pnut.io adds
   * to the headers. Mostly taken from PHPnut
   */
  protected function parseHeaders(string $response): string
  {
    // take out the headers
    // set internal variables
    // return the body/content
    $this->rate_limit = null;
    $this->rate_limit_remaining = null;
    $this->rate_limit_reset = null;
    $this->scope = [];
    $response = explode("\r\n\r\n", $response, 2);
    $headers = $response[0];
    if ($headers === 'HTTP/1.1 100 Continue') {
      $response = explode("\r\n\r\n", $response[1], 2);
      $headers = $response[0];
    }
    if (isset($response[1])) {
      $content = $response[1];
    } else {
      $content = '';
    }
    // this is not a good way to parse http headers
    // it will not (for example) take into account multiline headers
    // but what we're looking for is pretty basic, so we can ignore those shortcomings
    $this->headers = explode("\r\n", $headers);
    foreach ($this->headers as $header) {
      $header = explode(': ', $header, 2);
      if (count($header) < 2) {
        continue;
      }
      list($k,$v) = $header;
      switch ($k) {
        case 'X-RateLimit-Remaining':
          $this->rate_limit_remaining = (int)$v;
          break;
        case 'X-RateLimit-Limit':
          $this->rate_limit = (int)$v;
          break;
        case 'X-RateLimit-Reset':
          $this->rate_limit_reset = (int)$v;
          break;
        case 'X-OAuth-Scopes':
          $this->scope = (int)$v;
          $this->scopes = explode(',', (int)$v);
          break;
        case 'location':
        case 'Location':
          $this->redirectTarget = (int)$v;
          break;
      }
    }
    return $content;
  }

  public function makeRequest(
      string $method,
      string $end_point,
      array $parameters,
      string $content_type = 'application/x-www-form-urlencoded'
  ): string {

    $this->redirect_target = null;
    $this->meta = null;
    $method = strtoupper($method);
    $url = $this->api_url.$end_point;
    $this->logger->info("{$method} Request to {$url}");
    $ch = curl_init($url);
    $headers = [];
    $use_server_token = false;
    if ($method !== 'GET') {
      // if they passed an array, build a list of parameters from it
      curl_setopt($ch, CURLOPT_POST, true);
      if (is_array($parameters) && $method !== 'POST-RAW') {
        $parameters = http_build_query($parameters);
      }
      curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
      $headers[] = "Content-Type: ".$content_type;
    }
    if ($method !== 'POST' && $method !== 'POST-RAW') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    }
    if ($method === 'GET' && isset($parameters['access_token'])) {
      $headers[] = 'Authorization: Bearer '.$params['access_token'];
    } elseif (!empty($this->access_token)) {
      $headers[] = 'Authorization: Bearer '.$this->access_token;
    } elseif (!empty($this->server_token)) {
      $use_server_token = true;
      $this->logger->info("Using server token for auth");
      $headers[] = 'Authorization: Bearer '.$this->server_token;
    }
    #$this->logger->info("Access token: ".$this->access_token);
    #$this->logger->info("Headers: ".json_encode($headers));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLINFO_HEADER_OUT, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $response = curl_exec($ch);
    $request = curl_getinfo($ch, CURLINFO_HEADER_OUT);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effectiveURL = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    if ($http_status === 0) {
      throw new Exception('Unable to connect to Pnut ' . $url);
    }
    if ($request === false) {
      if (!curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT)) {
          throw new Exception('SSL verification failed, connection terminated: ' . $url);
      }
    }
    if (!empty($response)) {
      $response = $this->parseHeaders($response);
      if (!empty($response)) {
        $response = json_decode($response, true);
        try {
          $this->meta = new Meta($response);
        } catch (NotAuthorizedException $nae) {
          $headers_string = print_r($this->headers, true);
          $this->logger->error("Error, not authorized: {$nae->getMessage()}");
          $this->logger->error("Headers: {$headers_string}");
          # Force re-auth
          if (!$use_server_token) {
            $this->logout();
          }
          throw $nae;
        } catch (PnutException $pe) {
          $response_headers_string = print_r($this->headers, true);
          $request_headers_string = print_r($headers, true);
          $parameters_string = print_r($parameters, true);
          $this->logger->error("Error, no authorized: {$pe->getMessage()}");
          $this->logger->error("Method: {$method}");
          $this->logger->error("Parameters: {$parameters_string}");
          $this->logger->error("Request headers: {$request_headers_string}");
          $this->logger->error("Response headers: {$response_headers_string}");
          throw $pe;
        }

        // look for errors
        if (isset($response['error'])) {
          if (is_array($response['error'])) {
            throw new PnutException($response['error']['message'], $response['error']['code']);
          } else {
            throw new PnutException($response['error']);
          }
        // look for response migration errors
        } elseif (isset($response['meta'], $response['meta']['error_message'])) {
            throw new PnutException($response['meta']['error_message'], $response['meta']['code']);
        }
      }
    }
    if ($http_status < 200 || $http_status >= 300) {
      if ($http_status == 302) {
        #echo json_encode(preg_match_all('/^Location:(.*)$/mi', $response, $matches));
        throw new HttpPnutRedirectException($response);
      } else {
        throw new HttpPnutException('HTTP error '.$http_status);
      }
    } elseif (isset($response['meta'], $response['data'])) {
      return $response['data'];
    } elseif (isset($response['access_token'])) {
      return $response;
    } elseif (!empty($this->redirect_target)) {
      return $this->redirect_target;
    } else {
      throw new PnutException("No response ".json_encode($response).", http status: ${http_status}");
    }
  }

  public function post(
      string $end_point,
      array $parameters,
      string $content_type = 'application/x-www-form-urlencoded'
  ): string {
    $method = $content_type === 'multipart/form-data' ? 'POST-RAW' : 'POST';
    return $this->make_request($method, $end_point, $parameters, $content_type);
  }

  public function get(
      string $end_point,
      array $parameters = [],
      string $content_type = 'application/json'
  ): string {
    if (!empty($parameters)) {
      $end_point .= '?'.http_build_query($parameters);
      $parameters = [];
    }
    return $this->make_request('get', $end_point, $parameters, $content_type);
  }

  public function getAuthURL()
  {
      $url = $this->auth_url
      . '?client_id='
      . $this->client_id
      . '&redirect_uri='
      . urlencode($this->redirect_uri)
      . '&scope='.$this->needed_scope
      . '&response_type=code';
      $this->logger->debug('Auth URL: ' . $url);
      return $url;
  }

  //TODO: Ping server and validate token
  public function isAuthenticated(bool $allow_server_token = false): bool
  {
    $is_authenticated = ($allow_server_token && !empty($this->server_token))
      || isset($this->access_token);
    $log_str = $is_authenticated
    ? 'Authenticated'
    : 'Not authenticated';
    $this->logger->info(
        "Checking auth status for app: {$this->app_name}: {$log_str}"
    );
    $this->logger->info('Referrer: '.($_SERVER['HTTP_REFERER'] ?? 'Unknown'));
    $_SESSION['redirect_after_auth'] = $_SERVER['HTTP_REFERER'];
    return $is_authenticated;
  }
}
