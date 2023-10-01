<?php
use Workerman\Worker;
use Workerman\Timer;
use Workerman\Connection\AsyncTcpConnection;

use Workerman\Crontab\Crontab;
use Workerman\RedisQueue\Client; 

use think\facade\Cache;
 
use GuzzleHttp\Pool;
use GuzzleHttp\Client as Guzz_Client;
use GuzzleHttp\Psr7\Request as Guzz_Request; 
use GuzzleHttp\Promise as Guzz_Promise;
require_once __DIR__ . '/vendor/autoload.php';

define('ROOT_DIR', __DIR__);



#------------------------Cache缓存配置 
Cache::config([ 
    'default'    => 'file',
    'stores'    =>    [ 
        'file'   =>  [ 
            'type'   => 'File',             // 驱动方式
            'path'   => ROOT_DIR.'/runtime/cache', // 缓存保存目录
            'prefix' => '',                 // 缓存前缀
            'expire' => 0,                  // 缓存有效期 0表示永久缓存
            'serialize'  => ['serialize', 'unserialize'],             //序列化机制 例如 ['serialize', 'unserialize']
        ],   
        'redis'   =>  [
            'type' => 'redis',
            'host' => '127.0.0.1',
            'port' => 6379,
            'prefix' => '',
            'expire' => 0,
        ],  
    ]
    ]); 
    
 
#队列处理消息
$worker = new Worker();
$worker->count = 8;
$worker->name = 'queue';
$worker->onWorkerStart = function () { 
    $client = new Client('redis://127.0.0.1:6379'); 
    $options['max_attempts'] = 1; //消费失败后重试次数
    $options['retry_seconds'] = 5; //重试时间间隔
    
    $client->__construct('redis://127.0.0.1:6379',$options);
    $client->subscribe('queue_send', function($data){
        echo "消费队列：{$data['id']}\n"; 
        if(empty($data['url'])){
            return true;
        } 
        $Guzz_Client = new Guzz_Client(['timeout' => 5,'http_errors' => false,'verify' => false]);
        try { 
            $Guzz_Client->request('GET', $data['url'])->getBody(); 
        } catch (\Throwable $e) {    
            throw new Exception($e->getMessage()); 
        }
        
    });
    
    
};
 
 
  
#-----------------------------------------
$worker_events = new Worker();
$worker_events->name = 'events';
$worker_events->onWorkerStart = function($worker_events) { 
    
    $client = new Client('redis://127.0.0.1:6379');  
 
    
    #消息处理
    $con = new AsyncTcpConnection('ws://127.0.0.1:9503/events');
    $con->websocketPingInterval = 10;
    $con->num = 1;  
    $con->onMessage = function(AsyncTcpConnection $con, $data) use ($client)   {
        $json = json_decode(file_get_contents(ROOT_DIR."/监听关键词.json"), true);
        if(empty($json['key'])){
            return;
        }
        
        #file_put_contents(__DIR__.DIRECTORY_SEPARATOR."work.txt", "\n{$data}", FILE_APPEND); 
        
        $data = json_decode($data,true); 
 
        $session = $data['result']['session']; 
        if(isset($data['result']['update']['_']) && isset($data['result']['update']['message']['message'])){ 
            if($data['result']['update']['message']['out'] == false){ 
                $type = $data['result']['update']['_'];//消息类型
                $msgId = $data['result']['update']['message']['id'];//消息ID
                $text = $data['result']['update']['message']['message'];//消息内容 
                $date = date("Y-m-d H:i:s",$data['result']['update']['message']['date']);//消息时间
                
                 
                if($type == "updateNewChannelMessage"){#群组或频道消息
                    if(empty($data['result']['update']['message']['post'])){ 
                        $lei = "群组消息";
                        $formId = $data['result']['update']['message']['from_id']['user_id']??"未知";//发言用户ID 
                        $qunId =   $data['result']['update']['message']['peer_id']['channel_id'];//群ID  
                        
                        //存在entities 可能就是机器人消息 直接忽略掉  
                        if(isset($data['result']['update']['message']['entities'])){
                            if(count($data['result']['update']['message']['entities']) >1){
                                $lei=null;
                            }  
                        } 
                        
                    }else{ 
                        $lei = "频道消息";
                        $formId = "";//发言用户ID 
                        $qunId =   $data['result']['update']['message']['peer_id']['channel_id'];//群ID 
                    } 
                     
                    
                    
                }
                // else if($type == "updateNewMessage"){#私聊消息
                //     $lei = "私聊消息";
                //     $formId = $data['result']['update']['message']['from_id']['user_id'];//发言用户ID 
                //     $qunId = "";
                // }
                
            
             
                
            if (preg_match("/({$json['key']})/u", $text,$mate)) {  
            
           
                     $tg = $json['tguser']; #收消息的人ID或用户名    
                    
                    #发送自定义消息
                    if(isset($lei)){
                        
                        $Ttext =  "<code>{$text}</code>\n\n<b>关键词：<u>{$mate[0]}</u></b>   [ {$lei} ]\n";  
                        if($lei == "群组消息"){   
                            
                            $Ttext .=  "\n<b>用户ID：</b>tg://user?id={$formId}"; 
                            $Ttext .=  "\n<b>群组ID：</b><code>-100{$qunId}</code>";  
                            
                            $qunInfo = Cache::get("-100{$qunId}");
                            if(empty($qunInfo)){ 
                               $Guzz_Client = new Guzz_Client(['timeout' => 5,'http_errors' => false,'verify' => false]);
                               $qunInfo = json_decode($Guzz_Client->request('GET', "http://127.0.0.1:9503/api/{$session}/getFullInfo/?id=-100".$qunId)->getBody()->getContents(),true);  
                               if(!empty($qunInfo['response'])){ 
                                   Cache::set("-100{$qunId}",$qunInfo);
                               } 
                            } 
                            
                            if(isset($qunInfo['response']['Chat']['title'])){
                                $title = str_replace("&", "-", $qunInfo['response']['Chat']['title']);
                                $Ttext .=  "\n<b>群名称：</b><code>{$title}</code>";  
                            }
                            
                            if(isset($qunInfo['response']['Chat']['username'])){
                                $Ttext .=  "\n<b>消息位置：</b><a href=\"https://t.me/{$qunInfo['response']['Chat']['username']}/{$msgId}\">点击查看</a>";  
                            }else if(isset($qunInfo['response']['Chat']['usernames'][0]['username'])){  
                                $Ttext .=  "\n<b>消息位置：</b><a href=\"https://t.me/{$qunInfo['response']['Chat']['usernames'][0]['username']}/{$msgId}\">点击查看</a>";      
                            } 
                            
                            // #转发消息
                            // $queue_data['id'] = $msgId; 
                            // $queue_data['lei'] = $lei;
                            // $queue_data['url'] = "http://127.0.0.1:9503/api/{$session}/messages.forwardMessages/?data[from_peer]=-100{$qunId}&data[to_peer]={$tg}&data[id][0]={$msgId}"; 
                            // $client->send('queue_send', $queue_data); 
                            #转发消息end 
                             
                              
                           
                        }else if($lei == "频道消息"){ 
                            $Ttext .=  "\n<b>消息位置：</b><a href=\"https://t.me/c/{$qunId}/{$msgId}\">点击前往</a>";
                        } 
                        
                      
                        
                        
                        $Ttext .=  "\n<b>消息时间：</b><code>{$date}</code>"; 
                         #发送提示消息
                         $queue_data['id'] = $msgId; 
                         $queue_data['lei'] = $lei;
                         
                         $Ttext .= "\n\n[<a href=\"https://t.me/phpTRON\">电报技术交流群</a>]  [<a href=\"https://www.telegbot.org\">机器人开源社区</a>]";
                         $queue_data['url'] = "http://127.0.0.1:9503/api/{$session}/sendMessage/?data[message]=".urlencode($Ttext)."&data[peer]={$tg}"; 
                         
                         
                         $client->send('queue_send', $queue_data); 
                         #提示消息end
                    }
        
            }    
                
            }
             
            
        }
 
         
 
        
    };
    
    $con->onClose = function(AsyncTcpConnection $con) { 
        echo '链接关闭5秒后重连';
        $con->reConnect(5);
    };

    $con->connect();
};
#----------------------------------
 

Worker::runAll();