<?php
namespace APnutI\Entities;

class Source
{
  public string $name;
  public string $link;
  public int $id;

  public function __construct($data)
  {
    $this->name = $data['name'];
    if (isset($data['link'])) {
      $this->link = $data['link']; //v0
    } else {
      $this->link = $data['url']; //v1
    }
    $this->id = (int)$data['id'];
  }
}
