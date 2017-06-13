<?php
namespace App;
ini_set ( "display_errors" , true );
error_reporting ( E_ALL );
date_default_timezone_set ( 'Asia/shanghai' );
require __DIR__ . '/vendor/autoload.php';
use Intervention\Image\ImageManagerStatic as Image;

// configure with favored image driver (gd by default)
//Image::configure ( array ( 'driver' => 'imagick' ) );
if ( php_sapi_name () == 'cli' ) {
    class Base extends Swoole {
        function __construct () {
            parent::__construct ( array (
                'reactor_num' => 1 ,
                'worker_num' => 2
            ) );
        }
    }
} else {
    class Base extends Nginx {
        function run () {
            $this->index ();
        }
    }
}

class App extends Base {

    /**
     * 当前缩略图的Last-Modified
     * @var int
     */
    protected $lastModified = 0;
    /**
     * 缩略图的Content-Type
     * @var string
     */
    protected $contentType = '';
    /**
     * \GuzzleHttp\Client
     * @var null
     */
    protected $client = null;
    /**
     * 本地缓存文件
     * @var string
     */
    protected $localFile = '';
    /**
     * 本地缓存缩略图文件
     * @var string
     */
    protected $thumbnailFile = '';
    /**
     * 远程文件
     * @var string
     */
    protected $remoteFile = '';
    /**
     * 缩略图宽
     * @var int
     */
    protected $width = 100;
    /**
     * 缩略图高
     * @var int
     */
    protected $height = 100;

    /**
     * 本地文件的缓存时间,单位秒
     * @var int
     */
    protected $expire = 60;
    /**
     * 本地文件的临时路径
     * @var string
     */
    protected $cacheDir = '';

    /**
     * 开启缓存
     * @var bool
     */
    protected $cache = true;


    public function __construct () {
        $this->cacheDir = rtrim ( sys_get_temp_dir () , DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
        parent::__construct ();
    }

    /**
     * 是否强制刷新
     * @return bool
     */
    protected function forceReload () {
        return isset( $_GET[ 'reload' ] ) && $_GET[ 'reload' ] != 1;
    }

    /**
     * 本地缓存是否已经过期
     * @return bool
     */
    protected function hasExpired () {
        $this->_log->info ( __METHOD__ . time () );
        $this->_log->info ( __METHOD__ . filectime ( $this->localFile ) );
        $this->_log->info ( __METHOD__ . ( time () - filectime ( $this->localFile ) ) );
        return ( time () - filectime ( $this->localFile ) ) > $this->expire;
    }

    public function index () {
        try {
            $this->init ();
            if ( !file_exists ( $this->localFile ) || !filesize ( $this->localFile ) ) {
                $this->_log->info ( '本地文件不存在' . $this->localFile );
                $this->pullFileFromRemote ();
            } elseif ( $this->forceReload () ) {
                $this->_log->info ( '强制刷新' . $this->localFile );
                $this->pullFileFromRemote ();
            } elseif ( $this->hasExpired () ) {
                $this->_log->info ( '本地文件已过期' . $this->localFile );
                $status = $this->getRemoteFileStatus ();
                if ( $status == 304 ) {
                    $this->_log->info ( '远程文件响应304' );
                } elseif ( $status == 200 ) {
                    $this->_log->info ( '远程文件响应200' );
                    $this->pullFileFromRemote ();
                } else {
                    throw new \RuntimeException( '远程文件异常,http status ' . $status );
                }
            } else {
            }
            $this->createThumbnail ();
            $this->lastModified = filemtime ( $this->thumbnailFile );
            $this->display ();
        } catch ( \Exception $e ) {
            $this->_log->error ( $e->getMessage () . PHP_EOL . $e->getTraceAsString () );
            $img = Image::canvas ( $this->width , $this->height );
            echo $img->response ( 'jpg' , 70 );
        }
    }

    /**
     * 规范化URL
     * @param $string
     * @return $string
     */
    protected function parseUrl ( $url ) {
        $urls = parse_url ( $url );
        $url = '';
        $path = '';
        if ( isset( $urls[ 'scheme' ] ) ) {
            $url .= $urls[ 'scheme' ];
        }
        if ( !isset( $urls[ 'host' ] ) ) {
            return [
                '' ,
                ''
            ];
        }
        $url .= '://' . $urls[ 'host' ];
        $path .= $urls[ 'host' ];
        if ( isset( $urls[ 'port' ] ) ) {
            if ( $urls[ 'port' ] != 80 ) {
                $url .= ':' . $urls[ 'port' ];
                $path .= $urls[ 'port' ] . DIRECTORY_SEPARATOR;
            }
        }
        if ( isset( $urls[ 'path' ] ) ) {
            $dir = preg_replace ( '@/+@' , '/' , $urls[ 'path' ] );
            $url .= $dir;
            $path .= $dir;
        }
        if ( isset( $urls[ 'query' ] ) ) {
            $url .= '?' . $urls[ 'query' ];
        }
        if ( isset( $urls[ 'fragment' ] ) ) {
            $url .= '#' . $urls[ 'fragment' ];
        }
        return [
            $url ,
            $path
        ];
    }

    protected function init () {
        $this->client = new \GuzzleHttp\Client( [ 'verify' => false ] );
        /**
         * 取目标URL
         */
        if ( isset( $_GET[ 'width' ] ) && intval ( $_GET[ 'width' ] ) ) {
            $this->width = intval ( $_GET[ 'width' ] );
        }
        if ( isset( $_GET[ 'height' ] ) && intval ( $_GET[ 'height' ] ) ) {
            $this->height = intval ( $_GET[ 'height' ] );
        }
        if ( !isset( $_GET[ 'url' ] ) || !is_string ( $_GET[ 'url' ] ) ) {
            throw new \RuntimeException( '远程图片不能为空[' . __LINE__ . ']' );
        }
        if ( 0 !== stripos ( $_GET[ 'url' ] , 'http://' ) && 0 !== stripos ( $_GET[ 'url' ] , 'https://' ) ) {
            throw new \RuntimeException( '远程图片不正确[' . __LINE__ . ']' );
        }
        list( $this->remoteFile , $this->localFile ) = $this->parseUrl ( $_GET[ 'url' ] );
        $this->localFile = $this->cacheDir . $this->localFile;
        $path = pathinfo ( $this->localFile , PATHINFO_DIRNAME ) . DIRECTORY_SEPARATOR;
        $this->thumbnailFile = $path . $this->width . '_' . $this->height . '_' . pathinfo ( $this->localFile , PATHINFO_BASENAME );
        if ( !file_exists ( $path ) ) {
            if ( !mkdir ( $path , 0755 , true ) ) {
                throw new \RuntimeException( '创建目录失败' . $tmpPath );
            }
        }
    }

    protected function pathinfo ( $path ) {
        $tmpArray = explode ( "/" , $path );
        foreach ( $tmpArray as &$tmp ) {
            $tmp = rawurlencode ( $tmp );
        }
        return pathinfo ( implode ( "/" , $tmpArray ) );
    }

    /**
     *
     * 读取远程文件,缓存到本地
     * @throws Exception
     */
    protected function pullFileFromRemote () {
        try {
            $response = $this->client->get ( $this->remoteFile , [
                'save_to' => $this->localFile ,
                'headers' => [
                    'Referer' => $this->buildReferer ()
                ]
            ] );
            $lastModified = $response->getHeader ( 'Last-Modified' )[ 0 ];
            if ( $lastModified ) {
                touch ( $this->localFile , strtotime ( $lastModified ) );
            }
            $this->_log->error ( '成功拉取到远程文件' . $this->remoteFile );
        } catch ( \Exception $e ) {
            $this->header ( 'Status' , 500 );
            throw new \RuntimeException( __METHOD__ . '(),' . $e->getMessage () );
        }
    }

    /**
     * @return string
     */
    protected function buildReferer () {
        if ( isset( $_SERVER[ "REQUEST_URI" ] ) ) {
            if ( substr ( $_SERVER[ "REQUEST_URI" ] , 0 , 4 ) != "http" ) {
                $_SERVER[ "REQUEST_URI" ] = 'http://' . $_SERVER[ "HTTP_HOST" ] . ( ( $_SERVER[ "SERVER_PORT" ] != 80 ) ? ':' . $_SERVER[ "SERVER_PORT" ] : '' ) . $_SERVER[ "REQUEST_URI" ];
            }
            $referer = $_SERVER[ "REQUEST_URI" ];
        } else if ( isset( $_SERVER[ "SCRIPT_URI" ] ) ) {
            $referer = $_SERVER[ "SCRIPT_URI" ];
        } else {
            $referer = "http://www.artron.net";
        }
        return $referer;
    }

    /**
     * @return mixed
     */
    protected function getRemoteFileStatus () {
        try {
            $response = $this->client->request ( 'HEAD' , $this->remoteFile , [
                'headers' => [
                    'Referer' => $this->buildReferer () ,
                    'If-Modified-Since' => gmdate ( 'D, d M Y H:i:s' , filemtime ( $this->localFile ) ) . ' GMT'
                ]
            ] );
            if ( $response->getStatusCode () == 304 ) {
                touch ( $this->localFile , time () );
            }
            return $response->getStatusCode ();
        } catch ( \Exception $e ) {
            $this->header ( 'Status' , 500 );
            throw new \RuntimeException( __METHOD__ . '(),' . $e->getMessage () );
        }
    }

    protected function createThumbnail () {
        if ( file_exists ( $this->thumbnailFile ) && $this->cache ) {
            $time1 = filectime ( $this->localFile );
            $time2 = filectime ( $this->thumbnailFile );
            if ( $time1 > $time2 ) {
                $img = Image::make ( $this->localFile );
                $img->fit ( $this->width , $this->height , function ( $constraint ) {
                    $constraint->upsize ();
                } );
                $img->save ( $this->thumbnailFile );
                $this->_log->info ( '缩略图的时间小于本地缓存文件的时间,重新创建缩略图(' . $time1 . '/' . $time2 . ')' . $this->thumbnailFile );
                return;
            }
            $this->_log->info ( '使用原缩略图' . $this->thumbnailFile );
            return;
        }
        $img = Image::make ( $this->localFile );
        $img->fit ( $this->width , $this->height , function ( $constraint ) {
            $constraint->upsize ();
        } );
        $img->save ( $this->thumbnailFile );
        $this->_log->info ( '缩略图不存在,创建缩略图' . $this->thumbnailFile );
    }

    protected function display () {
        if ( isset ( $_SERVER [ "HTTP_IF_MODIFIED_SINCE" ] ) ) {
            if ( strtotime ( $_SERVER [ "HTTP_IF_MODIFIED_SINCE" ] ) == $this->lastModified ) {
                $this->header ( 'Status' , 304 );
                $this->_log->info ( '收到HTTP_IF_MODIFIED_SINCE且匹配成功,响应304' );
                return;
            } else {
                $message = 'HTTP_IF_MODIFIED_SINCE不匹配' . PHP_EOL;
                $message .= $_SERVER [ "HTTP_IF_MODIFIED_SINCE" ] . '/' . strtotime ( $_SERVER [ "HTTP_IF_MODIFIED_SINCE" ] ) . PHP_EOL;
                $message .= gmdate ( 'D, d M Y H:i:s' , $this->lastModified ) . ' GMT/' . $this->lastModified . PHP_EOL;
                $this->_log->info ( $message );
            }
        }
        $this->_log->info ( '未收到HTTP_IF_MODIFIED_SINCE' );
        $this->header ( 'Last-Modified' , gmdate ( 'D, d M Y H:i:s' , $this->lastModified ) . ' GMT' );
        $this->_log->info ( '输出缩略图' . $this->thumbnailFile );
        $this->header ( 'Content-type' , $this->contentType );
        readfile ( $this->thumbnailFile );
    }

    function __destruct () {
    }
}

$App = new App();
$App->run ();

