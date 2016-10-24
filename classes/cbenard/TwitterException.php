<?php

class TwitterException extends \Exception {
    private $status;

    public function __construct($message, \cbenard\TwitterErrorStatus $status) {
        parent::__construct($message);

        $this->status = $status;
    }
}