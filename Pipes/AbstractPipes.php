<?php
namespace Tanbolt\Process\Pipes;

use Tanbolt\Process\Process;
use Tanbolt\Process\Exception\InvalidArgumentException;

abstract class AbstractPipes
{
    /**
     * @var array
     */
    public $pipes = [];

    /**
     * @var Process
     */
    protected $process;

    /**
     * @var bool
     */
    private $unblocked;

    /**
     * code from symfony.
     * Licensed under the MIT/X11 License (http://opensource.org/licenses/MIT)
     * (c) Fabien Potencier <fabien@symfony.com>
     * @see https://github.com/symfony/process/blob/e34416d4094d899c052aa4212b6768380250e446/Process.php#L1705
     * @param ?string $argument
     * @return string
     */
    protected static function escapeArgument(?string $argument)
    {
        if ('' === $argument = (string) $argument) {
            return '""';
        }
        if (!Process::isWin()) {
            return "'".str_replace("'", "'\\''", $argument)."'";
        }
        if (false !== strpos($argument, "\0")) {
            $argument = str_replace("\0", '?', $argument);
        }
        if (!preg_match('/[()%!^"<>&|\s]/', $argument)) {
            return $argument;
        }
        $argument = preg_replace('/(\\\\+)$/', '$1$1', $argument);
        $argument = str_replace(
            ['"', '^', '%', '!', "\n"],
            ['""', '"^^"', '"^%"', '"^!"', '!LF!'],
            $argument
        );
        return '"'.$argument.'"';
    }

    /**
     * 替换命令行中的 env 变量
     * @param string $command
     * @param array $env
     * @return string
     */
    protected static function replaceEnv(string $command, array $env)
    {
        return preg_replace_callback('/"\${:([_a-zA-Z]++[_a-zA-Z0-9]*+)}"/', function ($matches) use ($command, $env) {
            $key = $matches[1];
            if (!isset($env[$key]) || false === $env[$key]) {
                throw new InvalidArgumentException(
                    sprintf('Command line is missing a value for parameter "%s": ', $key).$command
                );
            }
            return static::escapeArgument($env[$key]);
        }, $command);
    }

    /**
     * Pipes constructor.
     * @param Process $process
     */
    public function __construct(Process $process)
    {
        $this->process = $process;
    }

    /**
     * 获取最终执行命令
     * @return string
     */
    public function command()
    {
        $command = $this->process->getCommand();
        if (is_array($command)) {
            return implode(' ', array_map([$this, 'escapeArgument'], $command));
        }
        return static::replaceEnv($command, $this->process->getEnv());
    }

    /**
     * 获取执行目录
     * @return string
     */
    public function cwd()
    {
        return $this->process->getCwd();
    }

    /**
     * 获取 Evn 环境变量
     * @return array
     */
    public function env()
    {
        return $this->process->getEnv();
    }

    /**
     * 获取 proc_open option 选项
     * @return array
     */
    public function options()
    {
        return $this->process->getOption();
    }

    /**
     * 打开输入/输出流描述符
     * @return bool
     */
    public function open()
    {
        return true;
    }

    /**
     * 判断输入/输出流描述符是否已打开
     * @return bool
     */
    public function isOpened()
    {
        return (bool) $this->pipes;
    }

    /**
     * 关闭输入/输出流描述符
     * @return bool
     */
    public function close()
    {
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        $this->pipes = [];
        return true;
    }

    /**
     * 将输入/输出流设置为非阻塞
     * @return $this
     */
    protected function unblock()
    {
        if (!$this->unblocked) {
            foreach ($this->pipes as $pipe) {
                stream_set_blocking($pipe, 0);
            }
            $this->unblocked = true;
        }
        return $this;
    }

    /**
     * 写入缓冲区数据到输入流
     * @return array
     */
    protected function write()
    {
        if (!isset($this->pipes[0])) {
            return [];
        }
        if (!$input = $this->process->getInput()) {
            $efo = true;
        } else {
            if (!$input->writeTo($this->pipes[0])) {
                return [];
            }
            $efo = $input->efo();
        }
        if ($efo) {
            fclose($this->pipes[0]);
            unset($this->pipes[0]);
            return [];
        }
        return [$this->pipes[0]];
    }

    /**
     * 获取输入/输出流的描述符
     * @return array
     */
    abstract public function descriptors();

    /**
     * 数据传输: 写入输入流，读取输出流
     * @param bool $blocking
     * @param bool $close
     * @return string[]
     */
    abstract public function transfer(bool $blocking = false, bool $close = false);

    /**
     * Process 参数发生变动, 重置为初始状态
     * @return $this
     */
    abstract public function reset();
}
