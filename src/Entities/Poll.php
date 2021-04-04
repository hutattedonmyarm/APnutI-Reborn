<?php
namespace APnutI\Entities;

use APnutI\Entities\PollOption;
use APnutI\Entities\User;
use APnutI\APnutI;
use APnutI\Entities\Source;
use APnutI\Exceptions\NotSupportedPollException;

class Poll
{
  public \DateTime $created_at;
  public \DateTime $closed_at;
  public int $id = 0;
  public int $max_options = 0;
  public bool $is_anonymous = false;
  public bool $is_public = false;
  public array $options = [];
  public ?string $token = null;
  public string $prompt = "";
  public ?User $user = null;
  public ?Source $source = null;
  public string $type;

  private APnutI $api;

  public static string $notice_type = 'io.pnut.core.poll-notice';
  protected static array $poll_types = [
    'general.poll',
    'net.unsweets.beta',
    'io.pnut.core.poll',
    'io.broadsword.poll',
    'nl.chimpnut.quizbot.attachment.poll'
  ];

  public function __construct(array $data, APnutI $api)
  {
    $this->api = $api;
    $this->options = [];
    $type = '';
    if (array_key_exists('type', $data) && $data['type'] === Poll::$notice_type) {
      $val = $data['value'];
      $this->closed_at = new \DateTime($val['closed_at']);
      foreach ($val['options'] as $option) {
        $this->options[] = new PollOption($option);
      }
      $this->id = (int)$val['poll_id'];
      $this->token = $val['poll_token'];
      $this->prompt = $val['prompt'];
    } elseif (array_key_exists('type', $data) &&in_array($data['type'], Poll::$poll_types)) {
      $this->parsePoll($data);
    } elseif (array_key_exists('type', $data) &&strpos($data['type'], '.poll') !== false) {
      // Try parsing unknown types if they *might* be a poll
      try {
        $this->parsePoll($data);
      } catch (\Exception $e) {
        throw new NotSupportedPollException($data['type']);
      }
    } elseif (array_key_exists('raw', $data) && #Polls included in posts
      array_key_exists(Poll::$notice_type, $data['raw']) &&
      count($data['raw'][Poll::$notice_type]) > 0
    ) {
      $poll_data = $data['raw'][Poll::$notice_type][0];
      if (!empty($data['source'])) { #Source is attached to post, not to poll raw
        $poll_data['source'] = $data['source'];
      }
      $type = Poll::$notice_type;
      $this->parsePoll($poll_data);
    } else {
      throw new NotSupportedPollException($data['type']);
    }
    $this->type = empty($type) ? $data['type'] : $type;
  }

  private function parsePoll(array $data)
  {
    $this->created_at = new \DateTime($data['created_at']);
    $this->closed_at = new \DateTime($data['closed_at']);
    $this->id = (int)$data['id'];
    $this->is_anonymous = array_key_exists('is_anonymous', $data) ? (bool)$data['is_anonymous'] : false;
    $this->max_options = array_key_exists('max_options', $data) ? (int)$data['max_options'] : 1;
    $this->is_public = array_key_exists('is_public', $data) ? (bool)$data['is_public'] : false;
    foreach ($data['options'] as $option) {
      $this->options[] = new PollOption($option);
    }
    if (!empty($data['poll_token'])) {
      $this->token = $data['poll_token'];
    }
    $this->prompt = $data['prompt'];
    if (!empty($data['user'])) {
      $this->user = new User($data['user'], $this->api);
    }
    if (!empty($data['source'])) {
      $this->source = new Source($data['source']);
    }
  }

  /**
   * Returns the most voted option. If multiple options have the same amount
   * of voted, return all of them. Always returns an array!
   */
  public function getMostVotedOption(): array
  {
    if (count($this->options) === 0) {
      return [];
    }
    $optns = [];
    //$most_voted_option = $this->options[0];
    $most_voted_option = null;
    foreach ($this->options as $option) {
      if ($option->greaterThan($most_voted_option)) {
        $optns = [];
        $most_voted_option = $option;
        $optns[] = $option;
      } elseif ($option->greaterThanOrSame($most_voted_option)) {
        $optns[] = $option;
      }
    }
    return $optns;
  }

  public function getMyVotes(): array
  {
    $optns = [];
    foreach ($this->options as $option) {
      if ($option->is_your_response) {
        $optns[] = $option;
      }
    }
    return $optns;
  }

  public static function isValidPoll(string $type): bool
  {
    return $type === Poll::$notice_type || in_array($type, Poll::$poll_types);
  }

  public function canVote()
  {
    $is_authenticated = $this->api->isAuthenticated(false, true);
    return $is_authenticated && !$this->isClosed();
  }

  public function isClosed()
  {
    return $this->closed_at <= new \DateTime();
  }

  public function vote(array $options): Poll
  {
    return $this-api->voteInPoll($this->id, $options, $this->token);
  }

  /*
   * This is inconsistend with most other functions (which are provided directly by the API object.
   * I should probably settle on one of the two styles.
   * I prefer having the methods in here, but having to pass the API object along is not so great,
   * neither is having to make the API's logger public
   * TODO for v2 I guess
   */
  public static function create(
      APnutI $api,
      string $prompt,
      array $options,
      int $max_options,
      int $duration_minutes,
      bool $is_anonymous,
      bool $is_public
  ): Poll {
    $options = array_filter($options);
    $options = array_map(
        function ($v) {
          return ['text' => $v];
        },
        $options
    );
    $params = [
      'duration' => $duration_minutes,
      'options' => array_filter($options), #filters empty options
      'prompt' => $prompt,
      'type' => 'io.pnut.core.poll',
      'is_anonymous' => $is_anonymous,
      'is_public' => $is_public,
      'max_options' => $max_options
    ];
    $api->logger->debug('Creating poll');
    $api->logger->debug(json_encode($params));
    $resp = $api->post('/polls', $params, 'application/json');
    #TODO: Use getPollFromEndpoint
    return new Poll($resp, $api);
  }

  public function __toString(): string
  {
    if (!empty($this->user)) {
      $str = $this->user->username;
      #$str = 'Unknown user';
    } else {
      $str = 'Unknown user';
    }
    return $str
    . " asked: '"
    . $this->prompt
    . "', closed at "
    . $this->closed_at->format('Y-m-d H:i:s T');
  }
}
