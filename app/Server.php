<?php
namespace App;

use Monolog\Logger;

class Server {
    protected $_serv = null;
    protected $_ip   = null;
    protected $_port = 10080;
    protected $_log  = null;
    /**
     * @var swoole_http_response
     */
    static public $response = null;

    protected $_clientHandle = null;

    function __construct ( $option = array () ) {
        $this->_log = new Logger( 'image.server' );
        swoole_set_process_name ( __CLASS__ . ' on ' . $this->_port );
        $this->_log->info ( "swoole_version:" . swoole_version () );
        $this->_log->info ( "Listening:" . $this->_port );
        $this->_ip = self::getIp ();
        $this->_port = isset( $option[ 'port' ] ) ? $option[ 'port' ] : 10080;
        $this->_serv = new swoole_websocket_server( isset( $option[ 'ipaddress' ] ) ? $option[ 'ipaddress' ] : '0.0.0.0' , $this->_port );
        $this->_serv->set ( array (
            'max_conn' => isset( $option[ 'max_conn' ] ) ? $option[ 'max_conn' ] : 256 ,
            'timeout' => isset( $option[ 'timeout' ] ) ? $option[ 'timeout' ] : 2.5 ,
            'reactor_num' => isset( $option[ 'reactor_num' ] ) ? $option[ 'reactor_num' ] : 2 ,
            'worker_num' => isset( $option[ 'worker_num' ] ) ? $option[ 'worker_num' ] : 4 ,
            'task_worker_num' => isset( $option[ 'task_worker_num' ] ) ? $option[ 'task_worker_num' ] : 8 ,
            'backlog' => isset( $option[ 'backlog' ] ) ? $option[ 'backlog' ] : 128 ,
            'max_request' => isset( $option[ 'max_request' ] ) ? $option[ 'max_request' ] : 0 ,
            'dispatch_mode' => isset( $option[ 'dispatch_mode' ] ) ? $option[ 'dispatch_mode' ] : 2 ,
            'log_file' => isset( $option[ 'log_file' ] ) ? $option[ 'log_file' ] : sys_get_temp_dir () . DIRECTORY_SEPARATOR . __CLASS__ . '.log' ,
            'daemonize' => isset( $option[ 'daemonize' ] ) ? $option[ 'daemonize' ] : 0
        ) );
        $this->_serv->on ( 'Start' , array (
            $this ,
            'OnStart'
        ) );
        $this->_serv->on ( 'Shutdown' , array (
            $this ,
            'onShutdown'
        ) );
        $this->_serv->on ( 'WorkerStart' , array (
            $this ,
            'onWorkerStart'
        ) );
        $this->_serv->on ( 'WorkerStop' , array (
            $this ,
            'onWorkerStop'
        ) );
        $this->_serv->on ( 'Timer' , array (
            $this ,
            'onTimer'
        ) );
        $this->_serv->on ( 'Connect' , array (
            $this ,
            'onConnect'
        ) );
        $this->_serv->on ( 'Receive' , array (
            $this ,
            'onReceive'
        ) );
        $this->_serv->on ( 'Close' , array (
            $this ,
            'onClose'
        ) );
        $this->_serv->on ( 'Task' , array (
            $this ,
            'onTask'
        ) );
        $this->_serv->on ( 'Finish' , array (
            $this ,
            'onFinish'
        ) );
        $this->_serv->on ( 'WorkerError' , array (
            $this ,
            'onWorkerError'
        ) );
        $this->_serv->on ( 'ManagerStart' , array (
            $this ,
            'onManagerStart'
        ) );
        $this->_serv->on ( 'ManagerStop' , array (
            $this ,
            'onManagerStop'
        ) );
        $this->_serv->on ( 'Open' , array (
            $this ,
            'onOpen'
        ) );
        $this->_serv->on ( 'Message' , array (
            $this ,
            'onMessage'
        ) );
        $this->_serv->on ( 'Request' , array (
            $this ,
            'onRequest'
        ) );
    }

    protected function getRuntime () {
        return [ 'pid' => posix_getpid () ];
    }

    static function getIp ( $device = 'eth0' ) {
        $ip = swoole_get_local_ip ();
        if ( isset( $ip[ $device ] ) ) {
            return $ip[ $device ];
        }
        list( , $v ) = array_pop ( $ip );
        return $v;
    }

    public function OnStart ( swoole_server $server ) {
        $this->_log->info ( __METHOD__ );
    }

    public function onShutdown ( swoole_server $server ) {
        $this->_log->info ( __METHOD__ );
        $this->_log->info ( $this->getServerInfo ( $server ) );
    }

    public function getServerInfo ( swoole_websocket_server $server ) {
        $str = 'swoole_websocket_server:' . PHP_EOL;
        $str .= "\t主进程ID:" . $server->master_pid . PHP_EOL;
        $str .= "\t管理进程ID:" . $server->manager_pid . PHP_EOL;
        if ( isset( $server->worker_id ) ) {
            $str .= "\t工作进程序号:" . $server->worker_id . PHP_EOL;
        }
        if ( isset( $server->worker_pid ) ) {
            $str .= "\t工作进程ID:" . $server->worker_pid . PHP_EOL;
        }
        if ( isset( $server->taskworker ) ) {
            $str .= "\t是否任务进程:" . $server->taskworker . PHP_EOL;
        }
        $arr = $server->stats ();
        $str .= "\t服务器启动的时间:" . date ( 'Y-m-d H:i:s' , $arr[ 'start_time' ] ) . PHP_EOL;
        $str .= "\t当前连接的数量:{$arr['connection_num']}" . PHP_EOL;
        $str .= "\t接收的连接数量:{$arr['accept_count']}" . PHP_EOL;
        $str .= "\t关闭的连接数量:{$arr['close_count']}" . PHP_EOL;
        $str .= "\t排队的任务数量:{$arr['tasking_num']}" . PHP_EOL;
        $str .= "\t当前连接的客户端:";
        $start_fd = 0;
        while ( true ) {
            $conn_list = $server->connection_list ( $start_fd , 10 );
            if ( $conn_list === false or count ( $conn_list ) === 0 ) {
                break;
            }
            $start_fd = end ( $conn_list );
            $str .= ',' . implode ( ',' , $conn_list );
        }
        $str .= PHP_EOL;
        return $str;
    }

    public function OnWorkerStart ( swoole_server $server , $worker_id ) {
        $this->_log->info ( __METHOD__ );
    }

    public function OnWorkerStop ( swoole_server $server , $worker_id ) {
        $this->_log->info ( __METHOD__ );
    }

    public function onTimer ( swoole_server $server , $interval ) {
        $this->_log->info ( __METHOD__ );
        $this->_log->info ( "定时器[$interval]被触发" );
        $this->_log->info ( $this->getServerStats ( $server ) );
    }

    public function onConnect ( swoole_server $server , $fd , $from_id ) {
        $this->_log->info ( __METHOD__ );
        $this->_log->info ( "收到Reactor[#{$from_id}]分配的客户端[#{$fd}]" );
    }

    public function OnReceive ( swoole_server $server , $fd , $from_id , $data ) {
        $this->_log->info ( __METHOD__ );
        $this->_log->info ( $fd . "和" . $from_id . "发送数据" . $data );
        $this->_log->info ( "收到Reactor[#{$from_id}]分配的客户端[#{$fd}]发送的数据" );
    }


    public function onClose ( swoole_server $server , $fd , $from_id ) {
        $this->_log->info ( __METHOD__ );
        $this->_log->info ( "客户端[#{$fd}]断开至Reactor[#{$from_id}]" );
    }

    public function onWorkerError ( swoole_server $server , $worker_id , $worker_pid , $exit_code ) {
        $this->_log->info ( __METHOD__ );
        $this->_log->info ( "工作进程[#{$worker_id}][{$worker_pid}]异常，错误代码{$exit_code}" );
    }

    public function OnManagerStart ( $server ) {
        $this->_log->info ( __METHOD__ );
        $this->_log->info ( $this->getServerInfo ( $server ) );
    }

    public function OnManagerStop ( $server ) {
        $this->_log->info ( __METHOD__ );
    }

    public function onTask ( swoole_server $server , $task_id , $from_id , $data ) {
        $this->_log->info ( __METHOD__ );
        $this->_log->info ( "任务进程[#{$task_id}]启动,来自工作进程[#{$from_id}],数据:{$data}" );
        $this->broadcast ( $server , $data );
    }

    public function onFinish ( swoole_server $server , $task_id , $data ) {
        $this->_log->info ( __METHOD__ );
        $this->_log->info ( "任务进程[#{$task_id}]完成,数据:{$data}" );
    }

    public function onPipeMessage ( swoole_server $server , $from_worker_id , $message ) {
        $this->_log->info ( __METHOD__ );
        $this->_log->info ( "管理进程[#{$from_worker_id}]收到消息:{$message}" );
    }

    public function OnOpen ( swoole_websocket_server $server , swoole_http_request $request ) {
        $this->_log->info ( __METHOD__ );
        $this->_log->info ( "收到客户端[#{$request->fd}]的请求" . $request->server[ 'request_uri' ] );
        $this->_log->info ( $this->getRequestInfo ( $request ) );
    }

    public function getRequestInfo ( swoole_http_request $request ) {
        $str = 'swoole_http_request' . PHP_EOL;
        foreach ( $request->header as $k => $v ) {
            $str .= "\t{$k}:{$v}" . PHP_EOL;
        }
        foreach ( $request->server as $k => $v ) {
            $str .= "\t{$k}:{$v}" . PHP_EOL;
        }
        $str .= "\t连接句柄:{$request->fd}" . PHP_EOL;
        return $str;
    }

    protected function initRequest ( swoole_http_request $request , swoole_http_response $response ) {
        //$response->header ( 'Content-type' , 'text/html; charset=utf-8' );
        $this->_log->info ( __METHOD__ );
        $this->_log->info ( "收到客户端[#{$request->fd}]的请求" . $request->server[ 'request_uri' ] );
        foreach ( $request->header as $key => $value ) {
            $_SERVER[ 'HTTP_' . str_replace ( '-' , '_' , strtoupper ( $key ) ) ] = $value;
        }
        foreach ( $request->server as $key => $value ) {
            $_SERVER[ str_replace ( '-' , '_' , strtoupper ( $key ) ) ] = $value;
        }
        $_SERVER[ 'SERVER_ADDR' ] = self::getIp ();
        $_GET = array ();
        if ( isset( $request->server[ 'query_string' ] ) ) {
            parse_str ( $request->server[ 'query_string' ] , $_GET );
            parse_str ( $request->server[ 'query_string' ] , $_REQUEST );
        }
        $_POST = array ();
        if ( isset( $request->post ) ) {
            foreach ( $request->post as $k => $v ) {
                $_POST[ $k ] = $v;
                $_REQUEST[ $k ] = $v;
            }
        }
    }

    public function OnRequest ( swoole_http_request $request , swoole_http_response $response ) {
        self::$response = $response;
        $this->initRequest ( $request , $response );
        if ( $request->server[ 'request_uri' ] == '/favicon.ico' ) {
            $response->end ( "" );
        } elseif ( $request->server[ 'request_uri' ] == '/client' ) {
            $response->end ( "" );
        } else {
            $response->end ( '请在您的应用程序中重定onRequest()方法' );
        }
    }

    public function OnMessage ( swoole_websocket_server $server , swoole_websocket_frame $frame ) {
        $this->_log->info ( __METHOD__ );
    }

    function run () {
        $this->_serv->start ();
    }

    static function header ( $name , $value ) {
        if ( defined ( 'IN_SWOOLE' ) ) {
            if ( $name == 'Status' ) {
                Artron_Http_Server::$response->status ( $value );
            } else {
                Artron_Http_Server::$response->header ( $name , $value );
            }
        } else {
            header ( $name . ': ' . $value );
        }
    }
}
