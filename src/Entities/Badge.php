<?php
namespace APnutI\Entities;

class Badge
{
  public int $id;
  public string $name;

  public function __construct(array $data)
  {
    $this->id = (int)$data['id'];
    $this->name = $data['name'];
  }
}
