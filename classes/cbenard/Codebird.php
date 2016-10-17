<?php
namespace cbenard;

class Codebird extends \Codebird\Codebird {
    public static function getBearerToken() {
        return self::$_bearer_token;
    }
}