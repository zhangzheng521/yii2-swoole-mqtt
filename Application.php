<?php
/**
 * Created by PhpStorm.
 * User: immusen
 * Date: 2018/10/10
 * Time: 3:50 PM
 */

namespace immusen\mqtt;

use Yii;
use Swoole\Server;
use immusen\mqtt\src\Mqtt;
use immusen\mqtt\src\Task;
use immusen\mqtt\src\Redis;

class Application extends \yii\base\Application
{

    public $server;

    public function run()
    {
        $port = Yii::$app->params['listen'];
        $server = new Server('0.0.0.0', $port, SWOOLE_PROCESS);
        $server->set([
            'worker_num' => 2,
            'task_worker_num' => 2,
            'open_mqtt_protocol' => 1,
            'task_ipc_mode' => 3,
            'debug_mode' => 1,
            'heartbeat_check_interval' => 60,
            'heartbeat_idle_time' => 180,
            'daemonize' => Yii::$app->params['daemonize'],
            'log_file' => Yii::$app->getRuntimePath() . '/logs/app.log'
        ]);
        $server->on('Start', [$this, 'onStart']);
        $server->on('Task', [$this, 'onTask']);
        $server->on('Finish', [$this, 'onFinish']);
        $server->on('Connect', [$this, 'onConnect']);
        $server->on('Receive', [$this, 'onReceive']);
        $server->on('Close', [$this, 'onClose']);
        $server->on('WorkerStart', [$this, 'onWorkerStart']);
        //Mount redis on $server
        $server->redis = Redis::getRedis();
        $this->server = $server;
        $this->server->start();
    }

    public function onStart($server)
    {
        echo "Server Start {$server->master_pid}" . PHP_EOL;
    }

    public function onWorkerStart(Server $server, $id)
    {
        if ($id != 0) return;
        go(function () use ($server) {
            $redis = new \Swoole\Coroutine\Redis;
            $config = Yii::$app->params['redis'];
            $result = $redis->connect($config['host'], $config['port']);
            if (!$result) return;
            if(!empty($config['auth']) && !$redis->auth($config['auth'])) return;
            while (true) {
                //Redis pub/sub feature; Follow the task structure, Recommend use redis publish like this: redis->publish('async', 'send/sms/15600008888').
                $result = $redis->subscribe(['async']);
                if ($result)
                    $server->task(Task::async($result[2]));
            }
        });
    }

    public function onConnect($server, $fd, $from_id)
    {
    }

    public function onReceive(Server $server, $fd, $from, $buffer)
    {
        go(function () use ($server, $fd, $buffer) {
            try {
                $m = new Mqtt($buffer);
                echo $m;
                if ($m->tp == Mqtt::TP_CONNECT) {
                    if (Yii::$app->params['auth'] && Yii::$app->auth->judge($m->connectInfo) === false)
                        $m->replyConack(0x05);
                    else
                        $server->task(Task::internal('common/connect/' . $fd, $m->connectInfo));
                }
                if (!is_null($m->ack)) $server->send($fd, $m->ack);
                switch ($m->tp) {
                    case Mqtt::TP_PUBLISH:
                        return $server->task(Task::publish($fd, $m->getTopic(), $m->getPayload()));
                    case Mqtt::TP_SUBSCRIBE:
                        $server->task(Task::internal('common/redis/sadd', ['mqtt_sub_fds_set_#' . $m->topic, $fd]));
                        $server->task(Task::internal('common/redis/sadd', ['mqtt_sub_topics_set_#' . $fd, $m->topic]));
                        return $server->task(Task::subscribe($fd, $m->topic, $m->getReqqos()));
                    case Mqtt::TP_UNSUBSCRIBE:
                        return $server->task(Task::internal('common/unsub/' . $fd, $m->getTopic()));
                    case Mqtt::TP_DISCONNECT:
                        $server->close($fd);
                }
            } catch (\Exception $e) {
                var_dump($e->getMessage());
                $server->close($fd);
            }
        });
    }

    public function onClose($server, $fd, $from)
    {
        $server->task(Task::internal('common/close/' . $fd));
    }

    public function onTask(Server $server, $worker_id, $task_id, $task)
    {
        try {
            $class = new \ReflectionClass(Yii::$app->controllerNamespace . '\\' . ucfirst($task->class) . 'Controller');
            $method = 'action' . ucfirst($task->func);
            if ($class->hasMethod($method)) {
                $actor = $class->getMethod($method);
                return $actor->invokeArgs($class->newInstanceArgs([$server, $task->fd, $task->topic, $task->verb]), [$task->param, $task->body]);
            }
            throw new \Exception($method . ' Undefined');
        } catch (\Exception $e) {
            var_dump($e->getMessage());
        }
    }

    public function onFinish(Server $server, $task_id, $data)
    {
        echo 'Task finished #' . $task_id . '  #' . $data . PHP_EOL;
    }

    public function handleRequest($_)
    {
    }
}