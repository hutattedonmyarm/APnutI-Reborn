<?php
namespace APnutI\Exceptions;

use APnutI\Exceptions\HttpPnutException;

class HttpPnutRedirectException extends HttpPnutException
{
  public $response;

  public function __construct($response)
  {
    parent::__construct("Redirect");
    $this->response = $response;
  }
}
