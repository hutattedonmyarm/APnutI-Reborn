<?php
namespace APnutI\Entities;

use APnutI\Entities\PollOption;
use APnutI\Entities\User;
use APnutI\Entities\Source;
use APnutI\Exceptions\NotSupportedPollException;

class Poll
{
  public \DateTime $created_at;
  public \DateTime $closed_at;
  public int $id = 0;
  public bool $is_anonymous = false;
  public bool $is_public = false;
  public array $options = [];
  public ?string $token = null;
  public string $prompt = "";
  public ?User $user = null;
  public ?Source $source = null;
  public string $type;

  public static string $notice_type = 'io.pnut.core.poll-notice';
  protected static array $poll_types = [
    'general.poll',
    'net.unsweets.beta',
    'io.pnut.core.poll'
  ];

  public function __construct(array $data)
  {
    $this->options = [];
    $this->type = $data['type'];
    if ($data['type'] === Poll::$notice_type) {
      $val = $data['value'];
      $this->closed_at = new \DateTime($val['closed_at']);
      foreach ($val['options'] as $option) {
        $this->options[] = new PollOption($option);
      }
      $this->id = (int)$val['poll_id'];
      $this->token = $val['poll_token'];
      $this->prompt = $val['prompt'];
    } elseif (in_array($data['type'], Poll::$poll_types)) {
      $this->created_at = new \DateTime($data['created_at']);
      $this->closed_at = new \DateTime($data['closed_at']);
      $this->id = (int)$data['id'];
      $this->is_anonymous = (bool)$data['is_anonymous'];
      $this->is_public = (bool)$data['is_public'];
      foreach ($data['options'] as $option) {
        $this->options[] = new PollOption($option);
      }
      if (!empty($data['poll_token'])) {
        $this->token = $data['poll_token'];
      }
      $this->prompt = $data['prompt'];
      if (!empty($data['user'])) {
        $this->user = new User($data['user']);
      }
      if (!empty($data['source'])) {
        $this->source = new Source($data['source']);
      }
    } else {
      throw new NotSupportedPollException($data['type']);
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

  public static function isValidPoll(string $type): bool
  {
    return $type === Poll::$notice_type || in_array($type, Poll::$poll_types);
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