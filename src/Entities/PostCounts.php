<?php
namespace APnutI\Entities;

class PostContent
{
  public string $text;
  public ?string $html = null;
  public array $entities;
  public bool $links_not_parsed = false;

  public function __construct(array $data)
  {
    $this->text = $data['text'];
    if (!empty($data['html'])) {
      $this->html = $data['html'];
    }
    $this->entities = $data['entities'];
    if (!empty($data['links_not_parsed'])) {
      $this->links_not_parsed = (bool)$data['links_not_parsed'];
    }
  }
}
