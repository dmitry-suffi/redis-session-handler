<?php

namespace suffi\RedisSessionHandler;

/**
 * Class RedisSessionHandler
 * @package suffi\RedisSessionHandler
 */
class RedisSessionHandler implements \SessionHandlerInterface
{
    /**
     * Инстанс редиса
     * @var \Redis
     */
    protected $redis;

    /**
     * Время жизни сессии
     * @var int
     */
    protected $ttl;

    /**
     * Префикс
     * @var string
     */
    protected $prefix;

    /**
     * Флаг блокировки
     * @var bool
     */
    protected $locked;

    /**
     * Ключ блокировки
     * @var string
     */
    private $lockKey;

    /**
     * Токен блокировки
     * @var string
     */
    private $token;

    /**
     * Время между попытками разблокировки
     * @var int
     */
    private $spinLockWait;

    /**
     * Максимальное время ожидания разблокировки
     * @var int
     */
    private $lockMaxWait;

    /**
     * RedisSessionHandler constructor.
     * @param \Redis $redis
     * @param string $prefix
     * @param int $spinLockWait
     */
    public function __construct(\Redis $redis, $prefix = 'session_key', $spinLockWait = 200000)
    {
        $this->redis = $redis;

        $this->ttl = ini_get('gc_maxlifetime');
        $iniMaxExecutionTime = ini_get('max_execution_time');
        $this->lockMaxWait = $iniMaxExecutionTime ? $iniMaxExecutionTime * 0.7 : 20;

        $this->prefix = $prefix;
        $this->locked = false;
        $this->lockKey = null;
        $this->spinLockWait = $spinLockWait;

    }

    /**
     * @inheritdoc
     */
    public function open($savePath, $sessionName)
    {
        return true;
    }

    /**
     * Попытка разблокировать сессию
     */
    protected function lockSession($sessionId)
    {
        $attempts = (1000000 * $this->lockMaxWait) / $this->spinLockWait;

        $this->token = uniqid();
        $this->lockKey = $sessionId . '.lock';
        for ($i = 0; $i < $attempts; ++$i) {
            $success = $this->redis->set(
                $this->getRedisKey($this->lockKey),
                $this->token,
                [
                    'NX', //Установить ключ только, если он уже не существует.
                ]
            );
            if ($success) {
                $this->locked = true;
                return true;
            }
            usleep($this->spinLockWait);
        }
        return false;
    }

    /**
     * Снятие блокировки сессии
     */
    private function unlockSession()
    {
        $script = <<<LUA
if redis.call("GET", KEYS[1]) == ARGV[1] then
    return redis.call("DEL", KEYS[1])
else
    return 0
end
LUA;

        $this->redis->eval($script, array($this->getRedisKey($this->lockKey), $this->token), 1);

        $this->locked = false;
        $this->token = null;
    }

    /**
     * @inheritdoc
     */
    public function close()
    {

        if ($this->locked) {
            $this->unlockSession();
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function read($sessionId)
    {

        if (!$this->locked) {
            if (!$this->lockSession($sessionId)) {
                return false;
            }
        }

        return $this->redis->get($this->getRedisKey($sessionId)) ?: '';
    }

    /**
     * @inheritdoc
     */
    public function write($sessionId, $data)
    {
        if ($this->ttl > 0) {
            $this->redis->setex($this->getRedisKey($sessionId), $this->ttl, $data);
        } else {
            $this->redis->set($this->getRedisKey($sessionId), $data);
        }
        return true;
    }

    /**
     * @inheritdoc
     */
    public function destroy($sessionId)
    {
        $this->redis->del($this->getRedisKey($sessionId));
        $this->close();
        return true;
    }

    /**
     * @inheritdoc
     */
    public function gc($lifetime)
    {
        return true;
    }

    /**
     * Установка времени жизни сессии
     * @param int $ttl
     */
    public function setTtl($ttl)
    {
        $this->ttl = $ttl;
    }

    /**
     * Максимальное время ожидания разблокировки
     * @return int
     */
    public function getLockMaxWait()
    {
        return $this->lockMaxWait;
    }

    /**
     * Максимальное время ожидания разблокировки
     * @param int $lockMaxWait
     */
    public function setLockMaxWait($lockMaxWait)
    {
        $this->lockMaxWait = $lockMaxWait;
    }

    /**
     * Подготовка ключа
     * @param string $key key
     * @return string prefixed key
     */
    protected function getRedisKey($key)
    {
        if (empty($this->prefix)) {
            return $key;
        }
        return $this->prefix . $key;
    }
    
    public function __destruct()
    {
        $this->close();
    }
}