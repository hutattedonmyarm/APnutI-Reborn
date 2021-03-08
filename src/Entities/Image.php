<?php
namespace APnutI\Entities;

class Image
{
  public bool $is_default;
  public int $height;
  public int $width;
  public string $link;

  public function __construct(array $data)
  {
    $this->is_default = $data['is_default'];
    $this->height = $data['height'];
    $this->width = $data['width'];
    if (isset($data['link'])) {
      $this->link = $data['link']; //API v0
    } else {
      $this->link = $data['url']; //API v1
    }
  }
}
