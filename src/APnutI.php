<?php

namespace APnutI;

use APnutI\Entities\Post;
use APnutI\Entities\Poll;
use APnutI\Entities\User;
use APnutI\Exceptions\PnutException;
use APnutI\Exceptions\NotFoundException;
use APnutI\Exceptions\NotAuthorizedException;
use APnutI\Exceptions\HttpPnutException;
use APnutI\Exceptions\HttpPnutRedirectException;
use APnutI\Exceptions\NotSupportedPollException;
use APnutI\Exceptions\HttpPnutForbiddenException;
use APnutI\Exceptions\PollAccessRestrictedException;
use APnutI\Meta;
use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class APnutI
{
  protected string $api_url = 'https://api.pnut.io/v1';
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
  protected string $token_redirect_after_auth;
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

  public function __construct(
      ?string $client_secret = null,
      ?string $client_id = null,
      ?string $needed_scope = null,
      ?string $app_name = null,
      ?string $redirect_uri = null,
      ?string $log_path = null
  ) {
    $this->logger = empty($log_path) ? new NullLogger() : new Logger($this->app_name);
    $this->token_session_key = $this->app_name.'access_token';
    $this->token_redirect_after_auth = $this->app_name
    .'redirect_after_auth';
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
    if (!empty($client_secret)) {
      $this->client_secret = $client_secret;
    }
    if (!empty($client_id)) {
      $this->client_id = $client_id;
    }
    if (!empty($needed_scope)) {
      $this->needed_scope = $needed_scope;
    }
    if (!empty($redirect_uri)) {
      $this->redirect_uri = $redirect_uri;
    }
    if (!empty($app_name)) {
      $this->app_name = $app_name;
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
    /*$this->rate_limit = null;
    $this->rate_limit_remaining = null;
    $this->rate_limit_reset = null;*/
    $this->scopes = [];
    $this->scope = '';
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
          $this->scope = $v;
          $this->scopes = explode(',', $v);
              break;
        case 'location':
        case 'Location':
          $this->logger->debug(
              'Is redirect. Headers: '.json_encode($this->headers)
          );
          $this->logger->debug(
              'Is redirect. Target: '. $v
          );
          $this->redirect_target = $v;
              break;
      }
    }
    return $content;
  }

  public function makeRequest(
      string $method,
      string $end_point,
      array $parameters,
      string $content_type = 'application/x-www-form-urlencoded',
      bool $follow_redirect = true
  ): array {

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
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $follow_redirect);
    $response = curl_exec($ch);
    $request = curl_getinfo($ch, CURLINFO_HEADER_OUT);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effectiveURL = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    $this->logger->debug("{$method} Request to {$url}. Received status: {$http_status}. Response: {$response}");
    if ($http_status === 0) {
      throw new \Exception('Unable to connect to Pnut ' . $url);
    }
    if ($request === false) {
      if (!curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT)) {
          throw new \Exception('SSL verification failed, connection terminated: ' . $url);
      }
    }
    if (!empty($response)) {
      $response = $this->parseHeaders($response);
      if ($http_status == 302) {
        #echo json_encode(preg_match_all('/^Location:(.*)$/mi', $response, $matches));
        $this->logger->debug("302 Redirect to {$this->redirect_target}");
        throw new HttpPnutRedirectException($this->redirect_target);
      }
      if (!empty($response)) {
        $response = json_decode($response, true);
        if ($response === null && !empty($this->redirect_target)) {
          $this->logger->debug("Redirect to {$this->redirect_target}");
          throw new HttpPnutRedirectException($this->redirect_target);
        }
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
          $this->logger->error("Error: {$pe->getMessage()}");
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
      throw new HttpPnutException('HTTP error '.$http_status);
    } elseif (isset($response['meta'], $response['data'])) {
      return $response['data'];
    } elseif (isset($response['access_token'])) {
      return $response;
    } elseif (!empty($this->redirect_target)) {
      return [$this->redirect_target];
    } else {
      throw new PnutException("No response ".json_encode($response).", http status: ${http_status}");
    }
  }

  public function post(
      string $end_point,
      array $parameters,
      string $content_type = 'application/x-www-form-urlencoded'
  ): array {
    $method = $content_type === 'multipart/form-data' ? 'POST-RAW' : 'POST';
    return $this->makeRequest($method, $end_point, $parameters, $content_type);
  }

  public function get(
      string $end_point,
      array $parameters = [],
      string $content_type = 'application/json',
      bool $follow_redirect = true
  ): array {
    if (!empty($parameters)) {
      $end_point .= '?'.http_build_query($parameters);
      $parameters = [];
    }
    return $this->makeRequest('get', $end_point, $parameters, $content_type, $follow_redirect);
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
  public function isAuthenticated(bool $allow_server_token = false, bool $skip_verify_token = false): bool
  {
    $is_authenticated = ($allow_server_token && !empty($this->server_token))
      || isset($this->access_token);
    $log_str = $is_authenticated
    ? 'Authenticated'
    : 'Not authenticated';
    $this->logger->info(
        "Checking auth status for app: {$this->app_name}: {$log_str}"
    );
    if (isset($_SERVER['HTTP_REFERER'])) {
      $this->logger->info('Referrer: '.($_SERVER['HTTP_REFERER'] ?? 'Unknown'));
      $_SESSION[$this->token_redirect_after_auth] = $_SERVER['HTTP_REFERER'];
    }
    return $is_authenticated;
  }

  public function authenticate(string $auth_code): bool
  {
    $this->logger->debug("Authenticating: {$auth_code}");
    $parameters = [
      'client_id' => $this->client_id,
      'client_secret' => $this->client_secret,
      'code' => $auth_code,
      'redirect_uri' => $this->redirect_uri,
      'grant_type'=> 'authorization_code'
    ];
    $resp = $this->post(
        '/oauth/access_token',
        $parameters,
        'application/x-www-form-urlencoded'
    );

    if (empty($resp['access_token'])) {
      $this->logger->error("No access token ".json_encode($resp));
      return false;
    } else {
      $this->logger->debug('Received access token ' . $resp['access_token']);
      $_SESSION[$this->token_session_key] = $resp['access_token'];
      $this->logger->debug('Saved access token');
      $this->access_token = $resp['access_token'];
      return true;
    }
  }

  public function logout()
  {
    unset($_SESSION[$this->token_session_key]);
    $this->access_token = null;
  }

  // TODO
  public function getPostsForUser(User $user, $count = null)
  {
  }

  // TODO
  public function getPostsForUsername(string $username, int $count = 0)
  {
    /*
    if(!$this->isAuthenticated()) {
      throw new NotAuthorizedException("Cannot retrieve posts, ");
    }
    */
    if (mb_substr($username, 0, 1) !== '@') {
      $username = '@'.$username;
    }
    $params = [];
    if ($count > 0) {
      $params['count'] = $count;
    }
    $posts = $this->get('/users/' . $username . '/posts', $params);
    $p = [];
    foreach ($posts as $post) {
      $p[] = new Post($post, $this);
    }
    var_dump($p);
  }

  public function searchPosts(
      array $args,
      bool $order_by_id = true,
      int $count = 0
  ): array {
    if ($order_by_id) {
      $args['order'] = 'id';
    }
    if ($count > 0) {
      $args['count'] = $count;
    }
    $post_obj = [];
    /*
     * Stop fetching if:
     * - count($posts) >= $count and $count != 0
     * - OR: meta['more'] is false
     */
    do {
      $posts = $this->get('/posts/search', $args);
      if ($this->meta->more) {
        $args['before_id'] = $this->meta->min_id;
      }
      foreach ($posts as $post) {
        $post_obj[] = new Post($post, $this);
      }
    } while ($this->meta != null
      && $this->meta->more
      && (count($post_obj) < $count || $count !== 0));
    return $post_obj;
  }

  // TODO Maybe support additional polls?
  public function getPollsFromUser(int $user_id, array $params = []): array
  {
    $parameters = [
      'raw_types' => 'io.pnut.core.poll-notice',
      'creator_id' => $user_id,
      'include_deleted' => false,
      'include_client' => false,
      'include_counts' => false,
      'include_html' => false,
      'include_mention_posts' => false,
      'include_copy_mentions' => false,
      'include_post_raw' => true
    ];
    foreach ($params as $param => $value) {
      $parameters[$param] = $value;
    }
    $response = $this->get('posts/search', $parameters);
    if (count($response) === 0) {
      return [];
    }
    $polls = [];
    foreach ($response as $post) {
      if (!empty($post['raw'])) {
        foreach ($post['raw'] as $raw) {
          if (Poll::$notice_type === $raw['type']) {
            $polls[] = $this->getPoll($raw['value']['poll_id']);
          }
        }
      }
    }
    return $polls;
  }

  private function getPollFromResponse(array $res): Poll
  {
    try {
      return new Poll($res, $this);
    } catch (NotSupportedPollException $e) {
      $this->logger->error('Poll not supported: '.json_encode($res));
      throw $e;
    } catch (HttpPnutForbiddenException $fe) {
      $this->logger->error('Poll token required and not provided!');
      throw new PollAccessRestrictedException();
    } catch (NotAuthorizedException $nauth) {
      $this->logger->error('Not authorized when fetching poll');
      throw new PollAccessRestrictedException();
    }
  }

  public function getPollFromToken(int $poll_id, ?string $poll_token = null): Poll
  {
    $poll_token_query = empty($poll_token) ? '' : '?poll_token=' . $poll_token;
    $res = $this->get('/polls/' . $poll_id . $poll_token_query);
    return $this->getPollFromResponse($res);
  }

  public function getPoll(int $poll_id, ?string $poll_token = null): Poll
  {
    if (empty($poll_token)) {
      return $this->getPollFromToken($poll_id, $poll_token);
    }

    $this->logger->debug('Poll token provided');
    $re = '/((http(s)?:\/\/)?((posts)|(beta))\.pnut\.io\/(@.*\/)?)?(?(1)|^)(?<postid>\d+)/';
    preg_match($re, $poll_token, $matches);
    if (!empty($matches['postid'])) {
      $this->logger->debug('Poll token is post ' . $matches['postid']);
      $post_id = (int)$matches['postid'];
      $args = [
        'include_raw' => true,
        'include_counts' => false,
        'include_html' => false,
        'include_post_raw' => true
      ];
      $res = $this->get('/posts/' . $post_id, $args);
      return $this->getPollFromResponse($res);
    } else {
      $this->logger->debug('Poll token seems to be an actual poll token');
      return $this->getPollFromToken($poll_id, $poll_token);
    }
  }

  public function getAuthorizedUser(): User
  {
    return new User($this->get('/users/me'), $this);
  }

  public function getUser(int $user_id, array $args = [])
  {
    return new User($this->get('/users/'.$user_id, $args), $this);
  }

  public function getPost(int $post_id, array $args = [])
  {
    if (!empty($this->access_token)) {
      #$this->logger->info("AT:".$this->access_token);
    } else {
      $this->logger->info("No AT");
    }

    // Remove in production again
    try {
      $p = new Post($this->get('/posts/'.$post_id, $args), $this);
      $this->logger->debug(json_encode($p));
      return $p;
    } catch (NotAuthorizedException $nae) {
      $this->logger->warning(
          'NotAuthorizedException when getting post, trying without access token'
      );
      //try again not authorized
      $r = $this->makeRequest(
          '/get',
          '/posts/' . $post_id,
          $args,
          'application/json',
          true
      );
      return new Post($r, $this);
    }
  }

  public function getAvatar(int $user_id, array $args = []): string
  {
    //get returns an array with the url at idx 0
    $r = null;
    try {
      $r = $this->get('/users/'.$user_id.'/avatar', $args, 'application/json', false);
    } catch (HttpPnutRedirectException $re) {
      return $re->response;
    }
    $this->logger->error('Could not fetch avatar: No redirection! ' . json_encode($r));
    throw new PnutException('Could not fetch avatar: No redirection!');
  }

  public function getAvatarUrl(
      int $user_id,
      ?int $width = null,
      ?int $height = null
  ): string {
    //get returns an array with the url at idx 0
    $args = [];
    if (!empty($width)) {
      $args['w'] = $width;
    }
    if (!empty($height)) {
      $args['h'] = $height;
    }
    return $this->getAvatar($user_id, $args);
  }

  public function updateAvatar(
      string $file_path,
      ?string $filename = null,
      ?string $content_type = null
  ): User {
    if (empty($content_type)) {
      $content_type = mime_content_type($file_path);
    }
    if (empty($filename)) {
      $filename = basename($file_path);
    }

    $cf = new \CURLFile($file_path, $content_type, $filename);
    $parameters = ['avatar' => $cf];
    return new User(
        $this->post('/users/me/avatar', $parameters, 'multipart/form-data'),
        $this
    );
  }

  public function updateAvatarFromUploaded($uploaded_file): User
  {
    $filename = $uploaded_file['name'];
    $filedata = $uploaded_file['tmp_name'];
    $filetype = $uploaded_file['type'];
    return $this->updateAvatar($filedata, $filename, $filetype);
  }

  public function replyToPost(
      string $text,
      int $reply_to,
      bool $is_nsfw = false,
      bool $auto_crop = false
  ): Post {
    return createPost($text, $reply_to, $is_nsfw, $auto_crop);
  }

  public function createPost(
      string $text,
      bool $is_nsfw = false,
      bool $auto_crop = false,
      ?int $reply_to = null
  ): Post {
    $text = $auto_crop ? substr($text, 0, $this->getMaxPostLength()) : $text;
    $parameters = [
      'text' => $text,
      'reply_to' => $reply_to,
      'is_nsfw' => $is_nsfw,
    ];
    return new Post($this->post('posts', $parameters), $this);
  }

  protected function fetchPnutSystemConfig()
  {
    $config = $this->get('/sys/config');
    self::$POST_MAX_LENGTH = $config['post']['max_length'];
    //self::$POST_MAX_LENGTH_REPOST = $config['post']['repost_max_length'];
    self::$POST_MAX_LENGTH_REPOST = self::$POST_MAX_LENGTH;
    self::$POST_SECONDS_BETWEEN_DUPLICATES = $config['post']['seconds_between_duplicates'];
    self::$MESSAGE_MAX_LENGTH = $config['message']['max_length'];
    self::$RAW_MAX_LENGTH = $config['raw']['max_length'];
    self::$USER_DESCRIPTION_MAX_LENGTH = $config['user']['description_max_length'];
    self::$USER_USERNAME_MAX_LENGTH = $config['user']['username_max_length'];
    $this->logger->info('-----------Pnut API config-----------');
    $this->logger->info('');
    $this->logger->info("Max post length: ".self::$POST_MAX_LENGTH);
    $this->logger->info("Max repost length: ".self::$POST_MAX_LENGTH_REPOST);
    $this->logger->info("Seconds between post duplicates: ".self::$POST_SECONDS_BETWEEN_DUPLICATES);
    $this->logger->info("Max raw length: ".self::$RAW_MAX_LENGTH);
    $this->logger->info("Max user description length: ".self::$USER_DESCRIPTION_MAX_LENGTH);
    $this->logger->info("Max username length: ".self::$USER_USERNAME_MAX_LENGTH);
    $this->logger->info('--------------------------------------');
  }

  public function getMaxPostLength(): int
  {
    if (empty(self::$POST_MAX_LENGTH)) {
      $this->fetchPnutSystemConfig();
    }
    return self::$POST_MAX_LENGTH;
  }

  public function authenticateServerToken()
  {
    $token = $this->getServerToken();
    $this->server_token = $token;
    $this->logger->info("ST:".$this->server_token);
  }

  protected function getServerToken(): string
  {
    $this->logger->info('Requesting server access token from pnut');
    $params = [
      'client_id' => $this->client_id,
      'client_secret' => $this->client_secret,
      'grant_type' => 'client_credentials'
    ];
    $resp = $this->post('oauth/access_token', $params);
    if (!empty($resp['access_token'])) {
      $this->logger->info(json_encode($resp));
      return $resp['access_token'];
    } else {
      throw new PnutException("Error retrieving app access token: ".json_encode($resp));
    }
  }
}
