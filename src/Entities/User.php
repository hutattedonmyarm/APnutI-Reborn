<?php
namespace APnutI\Entities;

use APnutI\Entities\Image;
use APnutI\Entities\Badge;
use APnutI\Entities\APnutI;

class User
{
  public ?Badge $badge = null;
  public ?Image $avatar_image = null;
  public ?Image $cover_image = null;
  public int $id;
  public string $username;
  public ?string $name = null;
  public ?bool $presence = null;


  private APnutI $api;

  public function __construct(array $data, APnutI $api)
  {
    $this->api = $api;
    $this->id = (int)$data['id'];
    $this->username = $data['username'];
    if (!empty($data['badge'])) {
      $this->badge = new Badge($data['badge']);
    }
    if (!empty($data['content'])) {
      $this->avatar_image = new Image($data['content']['avatar_image']);
      $this->cover_image = new Image($data['content']['cover_image']);
    }

    if (!empty($data['name'])) {
      $this->name = $data['name'];
    }
    if (!empty($data['presence'])) {
      if (is_string($data['presence']) || is_int($data['presence'])) {
        $this->presence = !($data['presence'] == "offline" || $data['presence'] === 0);
      }
    }
  }

  public function getPresenceInt(): int
  {
    if ($this->presence === true) {
      return 1;
    } elseif ($this->presence === false) {
      return 0;
    } else {
      return -1;
    }
  }

  public function getAvatarUrl(
      ?int $width = null,
      ?int $height = null
  ): string {
    if (empty($this->avatar_image)) {
      return $this->api->getAvatarUrl($this->id, $width, $height);
    }
    $query = '';
    if (!empty($width)) {
      $query = '?w='.$width;
    } elseif (!empty($height)) {
      $query = '?h='.$height;
    }
    return $this->avatar_image->link.$query;
  }
}
