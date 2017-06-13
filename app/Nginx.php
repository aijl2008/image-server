<?php
namespace App;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;

class Nginx {

    protected $_log = null;

    function __construct () {
        $this->_log = new Logger( 'image.server' );
        $this->_log->pushHandler ( new StreamHandler( posix_getcwd () . '/run.log' , Logger::INFO ) );
    }

    function header ( $name , $value ) {
        header ( $name . ': ' . $value );
    }

    function cookie ( $key , $value = '' , $expire = 0 , $path = '/' , $domain = '' , $secure = false , $httponly = false ) {
        setcookie ( $key , $value , $expire , $path , $domain , $secure , $httponly );
    }
}