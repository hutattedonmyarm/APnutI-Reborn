<?php

namespace APnutI;

use APnutI\Entities\Post;
use APnutI\Entities\User;
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
  protected string $scope;
  protected string $redirect_uri;
  protected $rate_limit = null;
  protected $rate_limit_remaining = null;
  protected $rate_limit_rset = null;
  protected $scopes;
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
}
