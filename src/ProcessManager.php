<?php

namespace Reactphp\Framework\ProcessManager;

use React\ChildProcess\Process;
use Reactphp\Framework\Bridge\Server;
use Reactphp\Framework\Bridge\Pool;
use Reactphp\Framework\Bridge\Verify\VerifyUuid;
use Reactphp\Framework\Bridge\Http\HttpBridge;
use Reactphp\Framework\Bridge\WebSocket\WsBridge;
use Reactphp\Framework\Bridge\Tcp\TcpBridge;
use Reactphp\Framework\Bridge\BridgeStrategy;
use Reactphp\Framework\Bridge\Io\Tcp;
use Reactphp\Framework\Bridge\SerializableClosure;

class ProcessManager
{
    use \Reactphp\Framework\Single\Single;

    protected $processes;
    protected $pool;

    private $number = 2;
    protected $php;
    protected $uri;
    protected $secret;

    protected $configs = [];

    protected $cmd;

    protected $runing = false;
    protected $closed = false;

    static $debug = false;


    protected function init()
    {
        $this->processes = new \SplObjectStorage();
        $this->cmd = '{{placeholder}} exec ' . ($this->php ?: 'php') . ' ' . __DIR__ . '/init.php';
        $this->uri = "unix:///var/run/process-manager-{$this->key}.sock";
    }


    public function call($callback)
    {
        if (!$this->runing) {
            $this->start();
        }

        $uuid = (string) rand(1, count($this->configs));
        return $this->pool->call(SerializableClosure::serialize($callback, $uuid), ['uuid' => $uuid]);
    }

    public function start()
    {

        if ($this->runing) {
            return $this;
        }

        $this->runing = true;

        if (!$this->number) {
            throw new \Exception('Number of processes not set');
        }

        if (!$this->uri) {
            throw new \Exception('URI not set');
        }

        $this->startServer();
        $this->startClient();

        return $this;
    }

    protected function startServer()
    {

        if (empty($this->configs)) {
            for ($i = 0; $i < $this->number; $i++) {
                $this->configs[(string)($i + 1)] = (string)($i + 1);
            }
        }

        $server = new Server(new VerifyUuid($this->configs));

        $server->enableKeepAlive(5);

        $pool = new Pool($server, [
            'min_connections' => 1,
            'max_connections' => 100,
            'connection_timeout' => 2,
            'uuid_max_tunnel' => 1,
            'keep_alive' => 5,
            'wait_timeout' => 3
        ]);

        $path = str_replace('unix://', '', $this->uri);

        if (file_exists($path)) {
            unlink($path);
        }

        new Tcp($this->uri, new BridgeStrategy([
            new TcpBridge($server),
            new HttpBridge(new WsBridge($server))
        ]));

        $this->pool = $pool;
    }



    protected function startClient()
    {

        if (empty($this->configs)) {
            for ($i = 0; $i < $this->number; $i++) {
                $this->configs[(string)($i + 1)] = (string)($i + 1);
            }
        }
        $debug = static::$debug ? 1 : 0;
        foreach ($this->configs as $uuid => $secret) {
            $uri = $this->uri;
            $cmd = str_replace('{{placeholder}}', "DEBUG=$debug URI=$uri UUID=$uuid SECRET=$secret", $this->cmd);
            $this->runProcess($cmd);
        }
    }

    public function setNumber($number)
    {

        if ($number < 1) {
            throw new \Exception('Number of processes must be greater than 0');
        }

        if ($this->runing) {
            error_log('已经运行了，不在生效', LOG_WARNING);
            return ;
        }

        $this->number = $number;
    }

    public function setPhp($php)
    {
        $this->php = $php;

    }

    public function setUri($uri)
    {
        $this->uri = $uri;

    }


    public function setCmd($cmd)
    {
        $this->cmd = $cmd;
    }

    protected function runProcess($cmd)
    {

        echo $cmd . PHP_EOL;

        $process = new Process($cmd);
        $process->start();

        $process->stdout->on('data', function ($chunk) {
            echo $chunk . PHP_EOL;
        });

        $process->stdout->on('end', function () {
            echo 'ended' . PHP_EOL;
        });

        $process->stdout->on('error', function (\Exception $e) {
            echo 'error: ' . $e->getMessage() . PHP_EOL;
        });

        $process->stdout->on('close', function () {
            echo 'closed' . PHP_EOL;
        });

        $process->stderr->on('data', function ($chunk) {
            echo $chunk;
        });


        $this->processes->attach($process);

        $process->on('exit', function ($exitCode, $termSignal) use ($process, $cmd) {
            echo 'exit with code ' . $exitCode . ' and signal ' . $termSignal . PHP_EOL;
            $this->processes->detach($process);
            if (!$this->closed) {
                $this->runProcess($cmd);
            }
        });
    }

    public function reload()
    {
        $this->terminate();
    }

    public function restart()
    {
        foreach ($this->processes as $process) {
            $process->close();
        }
    }

    public function terminate()
    {
        foreach ($this->processes as $process) {
            $process->terminate();
        }
    }

    public function stop()
    {
        $this->closed = true;
        foreach ($this->processes as $process) {
            $process->close();
        }
    }

    public function getInfo()
    {
        return [
            'debug' => static::$debug,
            'key' => $this->key,
            'number' => $this->number,
            'uri' => $this->uri,
            'php' => $this->php,
            'cmd' => $this->cmd,
            'runing' => $this->runing,
            'closed' => $this->closed,
            'configs' => $this->configs
        ];
    }
}