Обработчик сессий через Redis с механизмом блокировки
=====================================================


Описание
---------
Используется для хранения сессий php в редисе.

Добавлен механизм блокировок: пока один процесс работает с сессией, второй процесс ожидает.

Установка
---------

```
composer require dmitry-suffi/RedisSessionHandler
```

Использование
-------------

```php


$redis = new Redis();
if ($redis->pconnect('11.111.111.11', 6379') && $redis->select(0)) {
    $handler = new \suffi\RedisSessionHandler\RedisSessionHandler($redis);
    session_set_save_handler($handler);
}

session_start();

```

