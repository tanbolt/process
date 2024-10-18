<?php
namespace Tanbolt\Process\Pipes;

use Tanbolt\Process\Process;

class UnixPipes extends AbstractPipes
{
    /**
     * @var string
     */
    private $command;

    /**
     * @var string
     */
    private $cwd = false;

    /**
     * @var resource
     */
    private $pstHandler;

    /**
     * @inheritdoc
     * @see https://unix.stackexchange.com/questions/71205/background-process-pipe-input
     */
    public function command()
    {
        if ($this->command) {
            return $this->command;
        }
        $command = $this->process->getCommand();
        if (is_array($command)) {
            $command = 'exec '.implode(' ', array_map([$this, 'escapeArgument'], $command));
        } else {
            $command = static::replaceEnv($command, $this->process->getEnv());
        }
        if ($this->process::supportSigchld()) {
            $command = '{ ('.$command.') <&3 3<&- 3>/dev/null & } 3<&0;';
            $command .= 'pid=$!; echo $pid >&3; wait $pid; code=$?; echo $code >&3; exit $code';
        }
        return $this->command = $command;
    }

    /**
     * @inheritdoc
     */
    public function cwd()
    {
        if (false === $this->cwd) {
            if ($cwd = $this->process->getCwd()) {
                $this->cwd = realpath($cwd);
            } else {
                $this->cwd = defined('ZEND_THREAD_SAFE') ? getcwd() : null;
            }
        }
        return $this->cwd;
    }

    /**
     * @inheritdoc
     */
    public function descriptors()
    {
        if ($this->process->isOutputDisabled()) {
            $null = fopen('/dev/null', 'c');
            $descriptors = [
                ['pipe', 'r'],
                $null,
                $null,
            ];
        } elseif ($this->process->isTty()) {
            $descriptors = [
                ['file', '/dev/tty', 'r'],
                ['file', '/dev/tty', 'w'],
                ['file', '/dev/tty', 'w'],
            ];
        } elseif ($this->process->isPty()) {
            $descriptors = [
                ['pty'],
                ['pty'],
                ['pty'],
            ];
        } else {
            $descriptors = [
                ['pipe', 'r'],
                ['pipe', 'w'],
                ['pipe', 'w'],
            ];
        }
        // last exit code is output on the fourth pipe and caught to work around --enable-sigchild
        if ($this->process::supportSigchld()) {
            $descriptors[3] = ['pipe', 'w'];
        }
        return $descriptors;
    }

    /**
     * Workaround for the bug, when PTS functionality is enabled.
     * @inheritdoc
     * @see https://bugs.php.net/69442
     */
    public function open()
    {
        if ($this->process::supportSigchld()) {
            $this->pstHandler = fopen(__FILE__, 'r');
        }
        return parent::open();
    }

    /**
     * @inheritdoc
     */
    public function close()
    {
        parent::close();
        if ($this->pstHandler) {
            @fclose($this->pstHandler);
            $this->pstHandler = null;
        }
        return true;
    }

    /**
     * @inheritdoc
     */
    public function transfer(bool $blocking = false, bool $close = false)
    {
        $read = $this->pipes;
        unset($read[0]);

        $output = $error = [];
        $write = $this->unblock()->write();

        $lastError = null;
        set_error_handler(function ($type, $msg) use (&$lastError) {
            $lastError = $msg;
        });
        $selectFailed = ($read || $write) && false === stream_select(
            $read, $write, $error, 0, $blocking ? Process::TIMEOUT_PRECISION * 1E6 : 0
        );
        restore_error_handler();

        // 若读写有错误，返回空数组，下次再尝试。若错误是管道错误，就不再继续尝试，重置 pipes 为空
        if ($selectFailed) {
            if (null !== $lastError && false === stripos($lastError, 'interrupted system call')) {
                $this->pipes = [];
            }
            return $output;
        }

        // 读取输出数据流
        foreach ($read as $pipe) {
            $type = array_search($pipe, $this->pipes, true);
            $output[$type] = '';
            do {
                $data = fread($pipe, Process::CHUNK_SIZE);
                $output[$type] .= $data;
            } while (isset($data[0]) && ($close || isset($data[Process::CHUNK_SIZE - 1])));

            if (!isset($output[$type][0])) {
                unset($output[$type]);
            }
            if ($close && feof($pipe)) {
                fclose($pipe);
                unset($this->pipes[$type]);
            }
        }
        return $output;
    }

    /**
     * @inheritdoc
     */
    public function reset()
    {
        $this->cwd = false;
        $this->command = null;
        return $this;
    }

    /**
     * close pipes
     */
    public function __destruct()
    {
        $this->close();
    }
}
