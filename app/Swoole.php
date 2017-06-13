<?php
namespace App;
class Swoole extends Server {


    public function OnRequest ( swoole_http_request $request , swoole_http_response $response ) {
        self::$response = $response;
        $this->initRequest ( $request , $response );
        if ( $request->server[ 'request_uri' ] == '/favicon.ico' ) {
            $response->status ( 304 );
            $response->end ( "" );
        } elseif ( $request->server[ 'request_uri' ] == '/thumbnail' ) {
            try {
                ob_start ();
                $this->index ();
                self::$response->header ( 'Content-Type' , 'image/jpeg' );
                $response->end ( ob_get_contents () );
                ob_end_clean ();
            } catch ( Exception $e ) {
                $response->status ( 500 );
                $response->end ( "服务器忙" );
            }
        } else {
            $response->status ( 403 );
            $response->end ( "禁止访问" );
        }
    }

    function header ( $name , $value ) {
        if ( $name == 'Status' ) {
            self::$response->status ( $value );
        } else {
            self::$response->header ( $name , $value );
        }
    }

    function cookie ( $key , $value = '' , $expire = 0 , $path = '/' , $domain = '' , $secure = false , $httponly = false ) {
        self::$response->cookie ( $key , $value , $expire , $path , $domain , $secure , $httponly );
    }
}