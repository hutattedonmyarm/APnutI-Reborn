<?php
namespace APnutI\Entities;

class PollOption
{
  public string $text;
  public int $position;
  public bool $is_your_response = false;
  public int $respondents = 0;
  public array $respondent_ids = [];

  public function __construct($data)
  {
    $this->text = $data['text'];
    $this->position = (int)$data['position'];
    if (!empty($data['is_your_response'])) {
      $this->is_your_response = (bool)$data['is_your_response'];
    }
    if (!empty($data['respondents'])) {
      $this->respondents = (int)$data['respondents'];
    }
    if (!empty($data['respondent_ids'])) {
      $this->respondent_ids = $data['respondent_ids'];
    }
  }

  public function greaterThan(?PollOption $option): bool
  {
    return empty($option)
      || ($this->text != $option->text
        && $this->respondents > $option->respondents);
  }

  public function greaterThanOrSame(?PollOption $option): bool
  {
    return empty($option)
      || ($this->text != $option->text
        && $this->respondents >= $option->respondents);
  }

  public function smallerThan(?PollOption $option): bool
  {
    return empty($option)
    || ($this->text != $option->text
      && $this->respondents < $option->respondents);
  }

  public function smallerThanOrSame(?PollOption $option): bool
  {
    return empty($option)
    || ($this->text != $option->text
      && $this->respondents <= $option->respondents);
  }

  public function equals(?PollOption $option): bool
  {
    return $this->text == $option->text
      && $this->respondents == $option->respondents;
  }

  public function __toString(): string
  {
    return $this->text . ' with ' . $this->respondents .' respondents';
  }
}
