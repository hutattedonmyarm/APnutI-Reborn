<?php
namespace APnutI\Entities;

use APnutI\Entities\User;
use APnutI\Entities\Source;
use APnutI\Entities\PostCounts;
use APnutI\Entities\PostContent;

class Post
{
  public DateTime $created_at;
  public int $id;
  public bool $is_deleted = false;
  public bool $is_nsfw = false;
  public bool $is_revised = false;
  public ?int $revision = null;
  public ?User $user = null;
  public int $thread_id;
  public ?int $reply_to = null;
  public ?int $repost_of = null;
  public ?PostCounts $counts = null;
  public ?Source $source = null;
  public ?PostContent $content = null;
  public bool $you_bookmarked = false;
  public bool $you_reposted = false;

  public function __construct(array $data)
  {
    $this->created_at = new \DateTime($data['created_at']);
    $this->id = (int)$data['id'];
    if (!empty($data['is_deleted'])) {
      $this->is_deleted = (bool)$data['is_deleted'];
    }
    if (!empty($data['is_nsfw'])) {
      $this->is_nsfw = (bool)$data['is_nsfw'];
    }
    if (!empty($data['is_revised'])) {
      $this->is_revised = (bool)$data['is_revised'];
    }
    if (!empty($data['revision'])) {
      $this->revision = (bool)$data['revision'];
    }
    #file_put_contents(__DIR__."/post_log.log", json_encode($data['user']), FILE_APPEND | LOCK_EX);
    if (!empty($data['user'])) {
      $this->user = new User($data['user']);
    }
    #file_put_contents(__DIR__."/post_log.log", json_encode($this->user), FILE_APPEND | LOCK_EX);
    $this->thread_id = (int)$data['thread_id'];
    if (!empty($data['reply_to'])) {
      $this->reply_to = (int)$data['reply_to'];
    }
    if (!empty($data['repost_of'])) {
      $this->repost_of = (int)$data['repost_of'];
    }
    if (!empty($data['counts'])) {
      $this->counts = new PostCounts($data['counts']);
    }

    if (!empty($data['source']) && is_array($data['source'])) {
      $this->source = new Source($data['source']);
    }
    if (!empty($data['content'])) {
      $this->content = new PostContent($data['content']);
    }
    if (!empty($data['you_bookmarked'])) {
      $this->you_bookmarked = (bool)$data['you_bookmarked'];
    }
    if (!empty($data['you_reposted'])) {
      $this->you_reposted = (bool)$data['you_reposted'];
    }
  }

  public function getText(bool $html_if_present = true): string
  {
    $txt = '';
    if (!empty($this->content)) {
      if ($html_if_present && !empty($this->content->html)) {
        $txt = $this->content->html;
      } elseif (!empty($this->content->text)) {
        $txt = $this->content->text;
      }
    }
    return $txt;
  }
}
