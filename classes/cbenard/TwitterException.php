<?php

class TwitterException extends \Exception {
    private $_status;

    public function __construct($message, \cbenard\TwitterErrorStatus $status) {
        parent::__construct($message);

        $this->_status = $status;
    }
}