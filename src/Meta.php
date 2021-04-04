<?php

namespace APnutI;

use APnutI\Exceptions\PnutException;
use APnutI\Exceptions\NotAuthorizedException;
use APnutI\Exceptions\NotFoundException;
use APnutI\Exceptions\HttpPnutForbiddenException;

class Meta
{
  public bool $more = false;
  public ?int $max_id = null;
  public ?int $min_id = null;
  public ?int $code = -1;

  public function __construct(array $json)
  {
    if (empty($json['meta'])) {
      return;
    }
    $meta = (array)$json['meta'];
    if (!empty($meta['more'])) {
      $this->more = (bool)$meta['more'];
    }
    if (!empty($meta['max_id'])) {
      $this->max_id = (int)$meta['max_id'];
    }
    if (!empty($meta['min_id'])) {
      $this->min_id = (int)$meta['min_id'];
    }
    if (!empty($meta['code'])) {
      $this->code = $meta['code'];
      if ($this->code === 400) {
        throw new PnutException($meta['error_message']);
      }
      if ($this->code === 401) {
        throw new NotAuthorizedException($meta['error_message']);
      }
      if ($this->code === 403) {
        throw new HttpPnutForbiddenException();
      }
      if ($this->code === 404) {
        throw new NotFoundException();
      }
      if ($meta['code'] < 200 || $meta['code'] >= 300) {
        throw new PnutException($meta['error_message']);
      }
    }
  }
}
