<?php

namespace TelegramApiServer;

use danog\MadelineProto\API;
use danog\MadelineProto\APIWrapper;
use danog\MadelineProto\MTProto;
use InvalidArgumentException;
use Psr\Log\LogLevel;
use ReflectionProperty;
use RuntimeException;
use TelegramApiServer\EventObservers\EventObserver;

class Client
{
    public static Client $self;
    /** @var API[] */
    public array $instances = [];

    public static function getInstance(): Client
    {
        if (empty(static::$self)) {
            static::$self = new static();
        }
        return static::$self;
    }

    public function connect(array $sessionFiles)
    {
        warning(PHP_EOL . '开始启动服务' . PHP_EOL);

        foreach ($sessionFiles as $file) {
            $sessionName = Files::getSessionName($file);
            $this->addSession($sessionName);
            $this->startLoggedInSession($sessionName);
        }

        $this->startNotLoggedInSessions();

        $sessionsCount = count($sessionFiles);
        if($sessionsCount == 0){
            // echo "ffff\n";
            // $MadelineProto = API('session.madeline');
            // $MadelineProto->start();
            // $me = $MadelineProto->getSelf();  
            
        }
        warning(
            "\nTelegramApiServer ready."
            . "\nNumber of sessions: {$sessionsCount}."
        );
    }

    public function addSession(string $session, array $settings = []): API
    {
        if (isset($this->instances[$session])) {
            throw new InvalidArgumentException('会话已存在');
        }
        $file = Files::getSessionFile($session);
        Files::checkOrCreateSessionFolder($file);

        if ($settings) {
            Files::saveSessionSettings($session, $settings);
        }
        $settings = array_replace_recursive(
            (array)Config::getInstance()->get('telegram'),
            Files::getSessionSettings($session),
        );
        $instance = new API($file, $settings);

        $this->instances[$session] = $instance;
        return $instance;
    }

    public function removeSession(string $session): void
    {
        if (empty($this->instances[$session])) {
            throw new InvalidArgumentException('Session not found');
        }

        EventObserver::stopEventHandler($session, true);

        $instance = $this->instances[$session];
        unset($this->instances[$session]);

        if (!empty($instance->API)) {
            $instance->unsetEventHandler();
        }
        unset($instance);
        gc_collect_cycles();
    }

    /**
     * @param string|null $session
     *
     * @return API
     */
    public function getSession(?string $session = null): API
    {
        if (!$this->instances) {
            throw new RuntimeException(
                '没有可用的会话,请调用：addSession 新增'
            );
        }

        if (!$session) {
            if (count($this->instances) === 1) {
                $session = (string)array_key_first($this->instances);
            } else {
                throw new InvalidArgumentException(
                    '检测到多个会话。指定要使用的会话。有关示例'
                );
            }
        }

        if (empty($this->instances[$session])) {
            throw new InvalidArgumentException('Session not found.');
        }

        return $this->instances[$session];
    }

    private function startNotLoggedInSessions(): void
    {
        foreach ($this->instances as $name => $instance) {
            if ($instance->getAuthorization() !== MTProto::LOGGED_IN) {
                {
                    //Disable logging to stdout
                    $logLevel = Logger::getInstance()->minLevelIndex;
                    Logger::getInstance()->minLevelIndex = Logger::$levels[LogLevel::ERROR];
                    $instance->echo("授权会话: {$name}\n");
                    $instance->start();

                    //Enable logging to stdout
                    Logger::getInstance()->minLevelIndex = $logLevel;
                }
                $this->startLoggedInSession($name);
            }
        }
    }

    public function startLoggedInSession(string $sessionName): void
    {
        if ($this->instances[$sessionName]->getAuthorization() === MTProto::LOGGED_IN) {
            if (empty(EventObserver::$sessionClients[$sessionName])) {
                $this->instances[$sessionName]->unsetEventHandler();
            }
            $this->instances[$sessionName]->start();
            $this->instances[$sessionName]->echo("启动会话: {$sessionName}\n");
        }
    }

    public static function getWrapper(API $madelineProto): APIWrapper
    {
        $property = new ReflectionProperty($madelineProto, "wrapper");
        /** @var APIWrapper $wrapper */
        $wrapper = $property->getValue($madelineProto);
        return $wrapper;
    }

}
