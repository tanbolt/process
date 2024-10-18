<?php
namespace Tanbolt\Process;

use Closure;
use Iterator;
use Traversable;
use Tanbolt\Process\Pipes\StdInput;
use Tanbolt\Process\Pipes\WinPipes;
use Tanbolt\Process\Pipes\UnixPipes;
use Tanbolt\Process\Pipes\AbstractPipes;
use Tanbolt\Process\Exception\LogicException;
use Tanbolt\Process\Exception\RuntimeException;
use Tanbolt\Process\Exception\TimeoutException;
use Tanbolt\Process\Exception\IdleTimeoutException;

/**
 * Class Process: 命令执行工具
 * 依赖 `php proc_open, proc_get_status, proc_terminate, proc_close` 函数
 * @package Tanbolt\Process
 */
class Process implements Iterator
{
    const STDERR = 'err';
    const STDOUT = 'out';
    const CHUNK_SIZE = 8192;
    const TIMEOUT_PRECISION = 0.1;

    const STATUS_READY = 'ready';
    const STATUS_STARTED = 'started';
    const STATUS_WAIT = 'wait';
    const STATUS_TERMINATED = 'terminated';

    // for setIteratorFlags
    const ITER_NON_BLOCKING = 1; // By default, iterating over outputs is a blocking call, use this flag to make it non-blocking
    const ITER_SKIP_OUT = 2;     // Use this flag to skip STDOUT while iterating
    const ITER_SKIP_ERR = 4;     // Use this flag to skip STDERR while iterating

    /**
     * @var bool
     */
    private static $windows;

    /**
     * @var string
     */
    private static $missingFunction;

    /**
     * @var bool
     */
    private static $sigchldSupported;

    /**
     * @var bool
     */
    private static $ttySupported;

    /**
     * @var bool
     */
    private static $ptySupported;

    /**
     * @var array|string
     */
    private $command;

    /**
     * @var string
     */
    private $cwd;

    /**
     * @var array
     */
    private $env = [];

    /**
     * @var float
     */
    private $timeout = 0;

    /**
     * @var float
     */
    private $idleTimeout = 0;

    /**
     * @var array
     */
    private $options = [];

    /**
     * @var StdInput
     */
    private $input;

    /**
     * @var bool
     */
    private $tty = false;

    /**
     * @var bool
     */
    private $pty = false;

    /**
     * @var bool
     */
    private $outputDisabled = false;

    /**
     * @var AbstractPipes
     */
    private $pipes;

    /**
     * @var bool
     */
    private $running = false;

    /**
     * @var int
     */
    private $startTime;

    /**
     * @var Closure
     */
    private $callback;

    /**
     * @var resource
     */
    private $process;

    /**
     * @var string
     */
    private $status = self::STATUS_READY;

    /**
     * @var array
     */
    private $processInformation = [];

    /**
     * @var array
     */
    private $fallback = [];

    /**
     * @var int
     */
    private $lastOutputTime;

    /**
     * @var int
     */
    private $exitCode;

    /**
     * @var resource
     */
    private $stdout;

    /**
     * @var resource
     */
    private $stderr;

    /**
     * @var int
     */
    private $stdoutOffset = 0;

    /**
     * @var int
     */
    private $stderrOffset = 0;

    /**
     * @var array
     */
    private $iterCache = [];

    /**
     * @var int
     */
    private $latestSignal;

    /**
     * @var int
     */
    private $iterFlags = 0;

    /**
     * @var int
     */
    private $iterPrevFlags = 0;

    /**
     * 是否运行在 win 系统
     * @return bool
     */
    public static function isWin()
    {
        if (null === self::$windows) {
            self::$windows = '\\' === DIRECTORY_SEPARATOR;
        }
        return self::$windows;
    }

    /**
     * 获取运行 Process 类缺少的依赖函数; 若不缺少函数, 返回 false
     * @return string|false
     */
    public static function missingProcFunction()
    {
        if (null === self::$missingFunction) {
            foreach (['proc_open', 'proc_get_status', 'proc_terminate', 'proc_close'] as $f) {
                if (!function_exists($f)) {
                    self::$missingFunction = $f;
                    break;
                }
            }
            self::$missingFunction = false;
        }
        return self::$missingFunction;
    }

    /**
     * 当前环境是否支持 SIGCHLD 信号
     * @return bool
     */
    public static function supportSigchld()
    {
        if (null === self::$sigchldSupported) {
            if (!function_exists('phpinfo') || defined('HHVM_VERSION')) {
                self::$sigchldSupported = false;
            } else {
                ob_start();
                phpinfo(INFO_GENERAL);
                self::$sigchldSupported = false !== strpos(ob_get_clean(), '--enable-sigchild');
            }
        }
        return self::$sigchldSupported;
    }

    /**
     * 当前环境是否支持 tty 模式，Unix Only
     * @return bool
     */
    public static function supportTty()
    {
        if (null === self::$ttySupported) {
            $pipes = [];
            self::$ttySupported = !static::isWin() && @proc_open(
                'echo 1 >/dev/null',
                [
                    ['file', '/dev/tty', 'r'],
                    ['file', '/dev/tty', 'w'],
                    ['file', '/dev/tty', 'w']
                ],
                $pipes
            );
        }
        return self::$ttySupported;
    }

    /**
     * 当前环境是否支持 pty 模式，Unix Only
     * @return bool
     */
    public static function supportPty()
    {
        if (null === self::$ptySupported) {
            self::$ptySupported = !static::isWin() && @proc_open(
                'echo 1 >/dev/null',
                [['pty'], ['pty'], ['pty']],
                $pipes
            );
        }
        return self::$ptySupported;
    }

    /**
     * 通过进程 ID 获取其使用的内存大小，Unix Only
     * @param string|int $pid
     * @return int|false
     */
    public static function memoryUsage($pid)
    {
        $status = static::isWin() ? null : @file_get_contents('/proc/' . $pid . '/status');
        if (!$status) {
            return false;
        }
        $matchArr = [];
        preg_match_all('~^(VmRSS|VmSwap):\s*([0-9]+).*$~im', $status, $matchArr);
        if(!isset($matchArr[2][0]) || !isset($matchArr[2][1])) {
            return false;
        }
        return 1024 * ($matchArr[2][0] + $matchArr[2][1]);
    }

    /**
     * 创建 Process 对象
     * @param array|string|null $command 执行命令
     * @param ?string $cwd 运行目录
     * @param array $env 环境变量
     * @param float $timeout 超时时长
     */
    public function __construct($command = null, string $cwd = null, array $env = [], float $timeout = 0)
    {
        if ($func = static::missingProcFunction()) {
            throw new RuntimeException('Function '.$func.' is not available.');
        }
        $this->setCommand($command)->setCwd($cwd)->setEnv($env)->setTimeout($timeout);
    }

    /**
     * 设置执行命令
     * @param array|string|null $command
     * @return $this
     */
    public function setCommand($command)
    {
        if ($this->changeSetting(__FUNCTION__)) {
            $this->command = $command;
        }
        return $this;
    }

    /**
     * 获取当前设置的命令行
     * @return array|string|null
     */
    public function getCommand()
    {
        return $this->command;
    }

    /**
     * 获取要执行的字符串命令行
     * @return string
     */
    public function getCommandLine()
    {
        return $this->pipes()->command();
    }

    /**
     * 设置要执行命令的初始工作目录
     * @param ?string $cwd
     * @return $this
     */
    public function setCwd(?string $cwd)
    {
        if ($this->changeSetting(__FUNCTION__)) {
            $this->cwd = $cwd;
        }
        return $this;
    }

    /**
     * 获取执行命令的初始工作目录
     * @return ?string
     */
    public function getCwd()
    {
        return $this->cwd;
    }

    /**
     * 重置所有环境变量
     * @param array $env
     * @return $this
     */
    public function setEnv(array $env)
    {
        if ($this->changeSetting(__FUNCTION__)) {
            $this->env = $env;
        }
        return $this;
    }

    /**
     * 设置(覆盖)一个或多个环境变量
     * - 参数 $env 为数组可批量设置，若值为 null 则移除对应选项
     * @param array|string $env
     * @param null $value
     * @return $this
     */
    public function putEnv($env, $value = null)
    {
        if (!$this->changeSetting(__FUNCTION__)) {
            return $this;
        }
        if (!is_array($env)) {
            $env = [$env => $value];
        }
        foreach ($env as $key => $value) {
            if (null === $value) {
                unset($this->env[$key]);
            } else {
                $this->env[$key] = $value;
            }
        }
        return $this;
    }

    /**
     * 获取指定或所有环境变量
     * @param ?string $key 参数为 null (默认) 返回所有环境变量
     * @return mixed
     */
    public function getEnv(string $key = null)
    {
        if ($key) {
            return array_key_exists($key, $this->env) ? $this->env[$key] : null;
        }
        return $this->env;
    }

    /**
     * 设置运行超时时长 (单位秒, 可以为小数)
     * @param float $timeout
     * @return $this
     */
    public function setTimeout(float $timeout)
    {
        if ($this->setBeforeRunning(__FUNCTION__)) {
            $this->timeout = max(0, $timeout);
        }
        return $this;
    }

    /**
     * 获取运行超时时长
     * @return float
     */
    public function getTimeout()
    {
        return $this->timeout;
    }

    /**
     * 设置进程空闲超时时长 (单位秒, 可以为小数)
     * @param float $timeout
     * @return $this
     */
    public function setIdleTimeout(float $timeout)
    {
        if ($this->setBeforeRunning(__FUNCTION__)) {
            $this->idleTimeout = max(0, $timeout);
        }
        return $this;
    }

    /**
     * 获取进程空闲超时时长
     * @return float
     */
    public function getIdleTimeout()
    {
        return $this->idleTimeout;
    }

    /**
     * 设置附加选项, $option 为 proc-open 支持的选项, 如:
     * - suppress_errors:（仅用于 Windows 平台）： 设置为 TRUE 表示抑制本函数产生的错误。
     * - bypass_shell: （仅用于 Windows 平台）： 设置为 TRUE 表示绕过 cmd.exe shell
     * @param array $option
     * @return $this
     * @see http://php.net/manual/zh/function.proc-open.php
     */
    public function setOption(array $option)
    {
        if ($this->changeSetting(__FUNCTION__)) {
            $this->options = $option;
        }
        return $this;
    }

    /**
     * 设置(覆盖)一个或多个附加选项
     * - 参数 $option 为数组可批量设置，若值为 null 则移除对应选项
     * @param array|string $option
     * @param null $value
     * @return $this
     * @see setOption
     */
    public function putOption($option, $value = null)
    {
        if (!$this->changeSetting(__FUNCTION__)) {
            return $this;
        }
        if (!is_array($option)) {
            $option = [$option => $value];
        }
        foreach ($option as $key => $value) {
            if (null === $value) {
                unset($this->options[$key]);
            } else {
                $this->options[$key] = $value;
            }
        }
        return $this;
    }

    /**
     * 获取指定或全部的 已设置附加选项
     * @param ?string $option 参数为 null (默认) 返回所有环境变量
     * @return mixed
     */
    public function getOption(string $option = null)
    {
        if ($option) {
            return array_key_exists($option, $this->options) ? $this->options[$option] : null;
        }
        return $this->options;
    }

    /**
     * 设置 input 数据源
     * @param Process|Iterator|Traversable|resource|array|string|mixed $input
     * @return $this
     */
    public function setInput($input)
    {
        if ($this->changeSetting(__FUNCTION__)) {
            if (!$this->input) {
                $this->input = new StdInput();
            }
            $this->input->setInput($input);
        }
        return $this;
    }

    /**
     * 生成一个 IteratorInput 的对象并将其作为 Process 的 input 数据源
     * @param Traversable|resource|array|string|null $data
     * @return IteratorInput
     */
    public function injectInput($data = null)
    {
        $this->setInput($input = new IteratorInput($data));
        return $input;
    }

    /**
     * 获取输入数据源对象
     * @return ?StdInput
     */
    public function getInput()
    {
        return $this->input;
    }

    /**
     * 启用/禁用 tty 模式
     * > 启用后，相当于把当前程序作为终端使用；
     * 输入将不再是 setInput 设置的源，输出也无法通过 getOutput 获取，需要手动输入，自动输出。
     * 有点类似于在 执行命令 和 实际终端 设备中间做为一个代理层存在，在无人职守的进程中不应使用该特性, 会阻塞程序。
     * [该特性不适用于 windows]
     * @param bool $tty
     * @return $this
     */
    public function setTty(bool $tty = true)
    {
        if (!$this->changeSetting(__FUNCTION__)) {
            return $this;
        }
        if (!static::supportTty()) {
            throw new RuntimeException('TTY mode is not supported.');
        }
        $this->tty = $tty;
        return $this;
    }

    /**
     * 是否已启用 tty 模式
     * @return bool
     */
    public function isTty()
    {
        return $this->tty;
    }

    /**
     * 启用/禁用 pty 模式
     * > 类似 tty 模式，启用虚拟终端模式，该特性不适用于 windows，unix 也不一定可用
     * @param bool $pty
     * @return $this
     */
    public function setPty(bool $pty = true)
    {
        if (!$this->changeSetting(__FUNCTION__)) {
            return $this;
        }
        if (!static::supportPty()) {
            throw new RuntimeException('PTY mode is not supported.');
        }
        $this->pty = $pty;
        return $this;
    }

    /**
     * 是否已启用 pty 模式
     * @return bool
     */
    public function isPty()
    {
        return $this->pty;
    }

    /**
     * 禁止输出数据
     * @param bool $disable
     * @return $this
     */
    public function disableOutput(bool $disable = true)
    {
        if (!$this->changeSetting(__FUNCTION__)) {
            return $this;
        }
        if ($disable && $this->idleTimeout > 0) {
            throw new LogicException('Output can not be disabled while an idle timeout is set.');
        }
        $this->outputDisabled = $disable;
        return $this;
    }

    /**
     * 是否已进制输出数据
     * @return bool
     */
    public function isOutputDisabled()
    {
        return $this->outputDisabled;
    }

    /**
     * 命令行相关参数发生变动，重置 pipes
     * @param string $method
     * @return bool
     */
    private function changeSetting(string $method)
    {
        if (!$this->setBeforeRunning($method)) {
            return false;
        }
        if ($this->pipes) {
            $this->pipes->reset();
        }
        return true;
    }

    /**
     * 命令开始执行后，不能再修改相关参数
     * @param string $method
     * @return bool
     */
    private function setBeforeRunning(string $method)
    {
        if ($this->isRunning()) {
            throw new RuntimeException('Call ['.$method.'] while the process is running is not possible.');
            // 若不需要抛出异常，可返回 false
            //return false;
        }
        return true;
    }

    /**
     * 获取当前对象的 IO 管道
     * @return AbstractPipes|UnixPipes|WinPipes
     */
    public function pipes()
    {
        if (!$this->pipes) {
            $this->pipes = static::isWin() ? new WinPipes($this) : new UnixPipes($this);
        }
        return $this->pipes;
    }

    /**
     * 初始化运行相关参数
     */
    protected function resetProcessData()
    {
        $this->startTime = null;
        $this->callback = null;
        $this->process = null;
        $this->status = self::STATUS_READY;
        $this->processInformation = [];
        $this->fallback = [];
        $this->lastOutputTime = null;
        $this->exitCode = null;
        if ($this->stdout) {
            @fclose($this->stdout);
        }
        $this->stdout = null;
        if ($this->stderr) {
            @fclose($this->stderr);
        }
        $this->stderr = null;
        $this->stdoutOffset = 0;
        $this->stderrOffset = 0;
        $this->iterCache = [];
        $this->latestSignal = null;
    }

    /**
     * 异步运行一个进程 ( 等同于 `start()->wait($callback)` )
     * @param callable|null $callback
     * @return $this
     */
    public function run(callable $callback = null)
    {
        return $this->start()->wait($callback);
    }

    /**
     * 开始运行进程
     * @return $this
     */
    public function start()
    {
        if ($this->isRunning()) {
            throw new RuntimeException('Process is already running');
        }
        $this->resetProcessData();
        $pipes = $this->pipes();
        if (!$pipes->open()) {
            throw new RuntimeException('Open process pipes failed');
        }
        $envBackup = [];
        foreach ($pipes->env() as $k => $v) {
            $envBackup[$k] = getenv($k);
            putenv(false === $v || null === $v ? $k : "$k=$v");
        }
        $this->startTime = $this->lastOutputTime = microtime(true);
        if (!$this->outputDisabled) {
            $this->stdout = fopen('php://temp/maxmemory:'.(1024 * 1024), 'wb+');
            $this->stderr = fopen('php://temp/maxmemory:'.(1024 * 1024), 'wb+');
        }
        $this->process = proc_open(
            $pipes->command(),
            $descriptors = $pipes->descriptors(),
            $pipes->pipes,
            $pipes->cwd(),
            null,
            $pipes->options()
        );
        foreach ($envBackup as $k => $v) {
            putenv(false === $v ? $k : "$k=$v");
        }
        if (!is_resource($this->process)) {
            throw new RuntimeException('Unable to launch a new process.');
        }
        $this->status = self::STATUS_STARTED;
        if (isset($descriptors[3])) {
            $this->fallback['pid'] = (int) fgets($pipes->pipes[3]);
        }
        if ($this->tty) {
            return $this;
        }
        return $this->updateStatus()->checkTimeout();
    }

    /**
     * 等待进程运行完毕
     * @param callable|null $callback
     * @return $this
     */
    public function wait(callable $callback = null)
    {
        if ($callback) {
            if ($this->outputDisabled) {
                $this->kill(0);
                throw new RuntimeException('Callback function can not be set while the output is disabled.');
            }
            $this->callback = $callback;
        }
        if (self::STATUS_WAIT === $this->status || self::STATUS_TERMINATED === $this->status) {
            return $this;
        }
        $this->callAfterOpen(__FUNCTION__)->updateStatus();
        $this->status = self::STATUS_WAIT;
        do {
            $pipesOpened = $this->pipes->isOpened();
            $this->checkTimeout()->readPipes($pipesOpened, !$pipesOpened || !static::isWin());
        } while ($pipesOpened);
        while ($this->isRunning()) {
            usleep(1000);
        }
        if ($this->processInformation['signaled'] && $this->processInformation['termsig'] !== $this->latestSignal) {
            throw new RuntimeException(sprintf(
                'The process has been signaled with signal "%s".',
                $this->processInformation['termsig']
            ));
        }
        return $this;
    }

    /**
     * 更新当前进程的运行状态
     * @param bool $blocking
     * @return $this
     */
    private function updateStatus(bool $blocking = false)
    {
        if (self::STATUS_STARTED !== $this->status && self::STATUS_WAIT !== $this->status) {
            return $this;
        }
        $this->processInformation = proc_get_status($this->process);
        $this->running = (bool) $this->processInformation['running'];
        $this->readPipes($this->running && $blocking, !static::isWin() || !$this->running);
        if ($this->fallback && (static::isWin() || static::supportSigchld())) {
            $this->processInformation = $this->fallback + $this->processInformation;
        }
        if (!$this->running) {
            $this->close();
        }
        return $this;
    }

    /**
     * 判断运行是否超时，包含两部分的判断：总运行时长是否超时，空闲运行时长是否超时
     * @return $this
     */
    protected function checkTimeout()
    {
        if (self::STATUS_STARTED !== $this->status && self::STATUS_WAIT !== $this->status) {
            return $this;
        }
        if ($this->timeout > 0 && $this->timeout < microtime(true) - $this->startTime) {
            $this->kill(0);
            throw new TimeoutException(sprintf(
                'The process "%s" exceeded the timeout of %s seconds.',
                $this->pipes->command(),
                $this->timeout
            ));
        }
        if ($this->idleTimeout > 0 && $this->idleTimeout < microtime(true) - $this->lastOutputTime) {
            $this->kill(0);
            throw new IdleTimeoutException(sprintf(
                'The process "%s" exceeded the timeout of %s seconds.',
                $this->pipes->command(),
                $this->idleTimeout
            ));
        }
        return $this;
    }

    /**
     * 读取进程 pipe 通道
     * @param bool $blocking
     * @param bool $close
     */
    private function readPipes(bool $blocking = false, bool $close = false)
    {
        $result = $this->pipes->transfer($blocking, $close);
        if (!count($result)) {
            return;
        }
        foreach ($result as $type => $data) {
            if (3 !== $type) {
                2 === $type ? $this->addErrorOutput($data) : $this->addOutput($data);
                if (null !== $this->callback) {
                    call_user_func(
                        $this->callback, $data, 2 === $type ? self::STDERR : self::STDOUT
                    );
                }
            } elseif (!isset($this->fallback['signaled'])) {
                $this->fallback['exitcode'] = (int) $data;
            }
        }
    }

    /**
     * 关闭进程
     */
    private function close()
    {
        $this->pipes->close();
        if (is_resource($this->process)) {
            proc_close($this->process);
            $this->process = null;
        }
        $this->exitCode = $this->processInformation['exitcode'];
        $this->status = self::STATUS_TERMINATED;
        if (-1 === $this->exitCode) {
            if ($this->processInformation['signaled'] && 0 < $this->processInformation['termsig']) {
                // if process has been signaled, no exitcode but a valid termsig, apply Unix convention
                $this->exitCode = 128 + $this->processInformation['termsig'];
            } elseif (static::supportSigchld()) {
                $this->processInformation['signaled'] = true;
                $this->processInformation['termsig'] = -1;
            }
        }
        // Free memory from self-reference callback created by buildCallback
        // Doing so in other contexts like __destruct or by garbage collector is ineffective
        // Now pipes are closed, so the callback is no longer necessary
        $this->callback = null;
    }

    /**
     * 结束当前进程
     * > 先给进程发送 SIGTERM 信号，进程可以捕捉该信号进行一些操作后自行关闭，
     * 若超过 $timeout 时长后仍未关闭，会向进程发送强制关闭信号 SIGKILL
     * @param int $timeout 等待时长
     * @param ?int $signal 强制关闭时的信号值, 默认为 SIGKILL，仅支持 Unix，Win 系统不支持
     * @return int
     */
    public function kill(int $timeout = 10, int $signal = null)
    {
        $timeoutMicro = microtime(true) + $timeout;
        if ($this->isRunning()) {
            // 发送 SIGTERM 信号, 为防止 SIGTERM 常量未定义, 这里直接使用数字
            $this->doSignal(15, false);
            do {
                usleep(1000);
            } while ($this->isRunning() && microtime(true) < $timeoutMicro);
            if ($this->isRunning()) {
                // Avoid exception here: process is supposed to be running, but it might have stopped just
                // after this line. In any case, let's silently discard the error, we cannot do anything.
                $this->doSignal($signal ?: 9, false);
            }
        }
        if ($this->isRunning()) {
            if (isset($this->fallback['pid'])) {
                unset($this->fallback['pid']);
                return $this->kill(0, $signal);
            }
            $this->close();
        }
        return $this->exitCode;
    }

    /**
     * 给当前进程发送一个 signal 信号 (Win 不支持, 若调用该函数会关闭进行)
     * @see http://php.net/manual/en/pcntl.constants.php
     * @see http://man7.org/linux/man-pages/man7/signal.7.html
     * @param int $signal
     * @return $this
     */
    public function signal(int $signal)
    {
        return $this->doSignal($signal);
    }

    /**
     * 发送一个关闭信号给当前执行进程
     * @param int $signal 信号值, win 不支持
     * @param bool $throw 发生异常是直接抛出还是返回 false
     * @return Process|false
     */
    private function doSignal(int $signal, bool $throw = true)
    {
        if (null === $pid = $this->pid()) {
            if ($throw) {
                throw new LogicException('Can not send signal on a non running process.');
            }
            return false;
        }
        if (static::isWin()) {
            $kill = 'taskkill /F /T /PID '.$pid;
            if (function_exists('exec')) {
                exec($kill.' 2>&1', $output, $code);
                $output = implode(' ', $output);
            } else {
                $kill = new static($kill);
                $code = $kill->run();
                $output = $kill->getOutput();
            }
            if ($code && $this->isRunning()) {
                if ($throw) {
                    throw new RuntimeException(sprintf('Unable to kill the process (%s).', $output));
                }
                return false;
            }
        } else {
            if (function_exists('posix_kill')) {
                $ok = @posix_kill($pid, $signal);
            } elseif ($ok = proc_open(sprintf('kill -%d %d', $signal, $pid), [2 => ['pipe', 'w']], $pipes)) {
                $ok = false === fgets($pipes[2]);
            }
            if (!$ok) {
                if ($throw) {
                    throw new RuntimeException(sprintf('Error while sending signal `%s`.', $signal));
                }
                return false;
            }
        }
        $this->latestSignal = $signal;
        $this->fallback['signaled'] = true;
        $this->fallback['exitcode'] = -1;
        $this->fallback['termsig'] = $this->latestSignal;
        return $throw ? $this : true;
    }

    /**
     * 当前 proc_open 打开的进程 resource
     * @return resource
     */
    public function proc()
    {
        // proc_get_status 获取的 info 除了 signaled / termsig 对应的 终止信号，还有 stopped / stopsig 对应的 暂停信号
        // 前二者可通过 isSignaled() / getTermSignal() 获取，后者没有直接提供相关方法
        // 因为后两个信号只能捕获一次，即在进程暂停后首次获取可正常返回，再次获取总为 false，并且后续也无法获得何时取消暂停
        // 比如外部通过 kill -STOP pid 命令暂停 process 进程，此时 stopped=true，但后续再次获取时，stopped=false
        // 若外部通过 kill -CONT pid 命令继续进程，无法触发任何有效通知，不知道何时取消暂停
        // 若对暂停信号有刚性需求，应在 process 进程内监听信号
        // 若必须在父进程中操作，可使用 proc() 方法获取进程 resource, 自行使用 proc_get_status 提取信息，需注意以上特性
        return $this->process;
    }

    /**
     * 当前进程的 pid
     * @return ?int
     */
    public function pid()
    {
        return $this->isRunning() ? $this->processInformation['pid'] : null;
    }

    /**
     * 当前进程状态，返回值有以下几种
     * - STATUS_READY
     * - STATUS_STARTED
     * - STATUS_WAIT
     * - STATUS_TERMINATED
     * @return string
     */
    public function status()
    {
        return $this->updateStatus()->status;
    }

    /**
     * 当前进程是否已运行过 (运行中或已运行完毕都返回 true)
     * @return bool
     */
    public function isRan()
    {
        return $this->status && $this->status != self::STATUS_READY;
    }

    /**
     * 当前进程是否仍处于运行状态
     * @return bool
     */
    public function isRunning()
    {
        return $this->updateStatus()->running;
    }

    /**
     * 当前进程是否处在等待状态
     * @return bool
     */
    public function isWait()
    {
        return $this->status && $this->status == self::STATUS_WAIT;
    }

    /**
     * 当前进程是否已输出完毕
     * @return bool
     */
    public function isTerminated()
    {
        return $this->updateStatus()->status == self::STATUS_TERMINATED;
    }

    /**
     * 判断进程是否由未捕获的信号所终止, 在 Windows 平台永远为 FALSE，
     * 相当于 UNIX 编程中的 WIFSIGNALED(status), 进程被外来的异常信号终止，比如 Kill -TERM pid
     * @return bool
     */
    public function isSignaled()
    {
        return $this->isTerminated() && $this->processInformation['signaled'];
    }

    /**
     * 获取终止进程的信号值，不支持或获取失败返回 null，相当于 UNIX 编程中的 WIFSIGNALED(status)
     * @return int
     */
    public function getTermSignal()
    {
        if (!$this->isTerminated()) {
            return null;
        }
        if (static::supportSigchld() && -1 === $this->processInformation['termsig']) {
            throw new RuntimeException(
                'This PHP has been compiled with --enable-sigchild. Term signal can not be retrieved.'
            );
        }
        return (int) $this->processInformation['termsig'];
    }

    /**
     * 获取进程的退出信号
     * @return ?int
     */
    public function exitCode()
    {
        return $this->updateStatus()->exitCode;
    }

    /**
     * 获取进程退出的状态说明，根据退出信号值 和 Process\ExitCode 映射表获取
     * @return ?string
     * @see ExitCode
     */
    public function exitText()
    {
        return null === ($code = $this->exitCode()) ? null : ExitCode::text($code);
    }

    /**
     * 判断进程是否成功执行 (exitCode === 0)
     * @return bool
     */
    public function isSuccessful()
    {
        return 0 === $this->exitCode();
    }

    /**
     * 获取进程当前的所有输出
     * @param bool $stream 是否获取为 resource 对象，否则返回字符串
     * @return resource|string
     */
    public function getOutput(bool $stream = false)
    {
        $this->readPipesForOutput(__FUNCTION__);
        if ($stream) {
            fseek($this->stdout, 0);
            return $this->stdout;
        }
        return false === ($output = stream_get_contents($this->stdout, -1, 0)) ? '' : $output;
    }

    /**
     * 添加一段字符到进程输出内容
     * @param string $data
     * @return $this
     */
    public function addOutput(string $data)
    {
        $this->CallOutputEnable();
        $this->lastOutputTime = microtime(true);
        fseek($this->stdout, 0, SEEK_END);
        fwrite($this->stdout, $data);
        fseek($this->stdout, 0);
        return $this;
    }

    /**
     * 清空进程输出
     * @return $this
     */
    public function clearOutput()
    {
        $this->CallOutputEnable();
        ftruncate($this->stdout, 0);
        fseek($this->stdout, 0);
        return $this;
    }

    /**
     * 获取进程当前的所有异常输出
     * @param bool $stream 是否获取为resource对象，否则返回字符串
     * @return resource|string
     */
    public function getErrorOutput(bool $stream = false)
    {
        $this->readPipesForOutput(__FUNCTION__);
        if ($stream) {
            fseek($this->stderr, 0);
            return $this->stderr;
        }
        return false === ($output = stream_get_contents($this->stderr, -1, 0)) ? '' : $output;
    }

    /**
     * 添加一段字符到进程异常输出内容
     * @param string $data
     * @return $this
     */
    public function addErrorOutput(string $data)
    {
        $this->CallOutputEnable();
        $this->lastOutputTime = microtime(true);
        fseek($this->stderr, 0, SEEK_END);
        fwrite($this->stderr, $data);
        fseek($this->stderr, 0);
        return $this;
    }

    /**
     * 清空进程异常输出
     * @return $this
     */
    public function clearErrorOutput()
    {
        $this->CallOutputEnable();
        ftruncate($this->stderr, 0);
        fseek($this->stderr, 0);
        return $this;
    }

    /**
     * 获取输出前进行确认
     * @param string $method
     */
    private function readPipesForOutput(string $method)
    {
        $this->CallOutputEnable()->callAfterOpen($method)->updateStatus();
    }

    /**
     * 执行一个在允许输出前提下才能调用的函数
     * @return $this
     */
    private function CallOutputEnable()
    {
        if ($this->outputDisabled) {
            throw new LogicException('Output has been disabled.');
        }
        return $this;
    }

    /**
     * 执行一个必须在进程打开后才能调用的函数
     * @param string $method
     * @return $this
     */
    private function callAfterOpen(string $method)
    {
        if (!$this->isRan()) {
            throw new LogicException(sprintf('Process must be started before calling %s.', $method));
        }
        return $this;
    }

    /**
     * 设置 foreach 循环输出的过滤方式，可以使用 ITER_NON_BLOCKING | ITER_SKIP_OUT | ITER_SKIP_ERR,
     * 实现 Iterator 接口，这样 Process 对象就可以作为执行另外一个命令的输入源
     * @param int $flags
     * @return $this
     */
    public function setIteratorFlags(int $flags)
    {
        $this->iterPrevFlags = $this->iterFlags;
        $this->iterFlags = $flags;
        return $this;
    }

    /**
     * 获取当前循环输出的筛选条件
     * @return int
     */
    public function getIteratorFlags()
    {
        return $this->iterFlags;
    }

    /**
     * 配合 setIteratorFlags 使用, 临时输出筛选条件后，可将配置回退到上次的设置
     * @return $this
     */
    public function backIteratorFlags()
    {
        $this->iterFlags = $this->iterPrevFlags;
        return $this;
    }

    /**
     * @return $this
     */
    public function rewind()
    {
        $this->iterCache = [];
        $this->stdoutOffset = $this->stderrOffset = 0;
        return $this;
    }

    /**
     * @return bool
     */
    public function valid()
    {
        if ($this->outputDisabled) {
            return false;
        }
        if (self::STATUS_READY === $this->status) {
            $this->start();
        }
        if ($this->iterCache) {
            return true;
        }
        $this->updateStatus();
        if (!(self::ITER_SKIP_OUT & $this->iterFlags)) {
            fseek($this->stdout, $this->stdoutOffset);
            $out = fread($this->stdout, self::CHUNK_SIZE);
            $this->stdoutOffset += strlen($out);
            fseek($this->stdout, 0);
            if (isset($out[0])) {
                $this->iterCache[self::STDOUT] = $out;
            }
        }
        if (!(self::ITER_SKIP_ERR & $this->iterFlags)) {
            fseek($this->stderr, $this->stderrOffset);
            $err = fread($this->stderr, self::CHUNK_SIZE);
            $this->stderrOffset += strlen($err);
            fseek($this->stderr, 0);
            if (isset($err[0])) {
                $this->iterCache[self::STDERR] = $err;
            }
        }
        if (self::STATUS_TERMINATED === $this->status) {
            return (bool) $this->iterCache;
        }
        $blocking = !(self::ITER_NON_BLOCKING & $this->iterFlags);
        if (!$this->iterCache && !$blocking) {
            $this->iterCache[self::STDOUT] = '';
        }
        $this->checkTimeout()->updateStatus($blocking);
        return $this->iterCache || $this->valid();
    }

    /**
     * @return string
     */
    public function current()
    {
        return current($this->iterCache);
    }

    /**
     * @return string
     */
    public function key()
    {
        return key($this->iterCache);
    }

    /**
     * @return $this
     */
    public function next()
    {
        array_shift($this->iterCache);
        return $this;
    }

    /**
     * clone instance
     */
    public function __clone()
    {
        $this->resetProcessData();
    }

    /**
     * destruct instance
     */
    public function __destruct()
    {
        $this->kill(0);
    }
}
