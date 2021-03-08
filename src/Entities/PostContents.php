<?php
namespace APnutI\Entities;

class PostCounts
{
  public int $bookmarks;
  public int $replies;
  public int $reposts;
  public int $threads;

  public function __construct(array $data)
  {
    $this->bookmarks = (int)$data['bookmarks'];
    $this->replies = (int)$data['replies'];
    $this->reposts = (int)$data['reposts'];
    $this->threads = (int)$data['threads'];
  }
}
