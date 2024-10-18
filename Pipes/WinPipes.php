<?php
namespace Tanbolt\Process\Pipes;

use Tanbolt\Process\Process;
use Tanbolt\Process\Exception\RuntimeException;

class WinPipes extends AbstractPipes
{
    /**
     * @var string
     */
    private $command;

    /**
     * @var string
     */
    private $cwd;

    /**
     * @var array
     */
    private $env;

    /**
     * @var array
     */
    private $files = [];

    /**
     * @var array
     */
    private $fileHandles = [];

    /**
     * @var array
     */
    private $readBytes = [
        1 => 0,
        2 => 0,
    ];

    /**
     * @inheritdoc
     */
    public function command()
    {
        if (null === $this->command) {
            $this->preparedCommand();
        }
        return $this->command;
    }

    /**
     * @inheritdoc
     */
    public function cwd()
    {
        if (null === $this->cwd) {
            $this->cwd = $this->process->getCwd() ?: getcwd();
        }
        return $this->cwd;
    }

    /**
     * @inheritdoc
     */
    public function env()
    {
        if (null === $this->env) {
            $this->preparedCommand();
        }
        return $this->env;
    }

    /**
     * @inheritdoc
     */
    public function options()
    {
        $options = $this->process->getOption();
        $options['suppress_errors'] = true;
        $options['bypass_shell'] = true;
        return $options;
    }

    /**
     * @inheritdoc
     */
    public function isOpened()
    {
        return $this->process->isRunning() && $this->fileHandles;
    }

    /**
     * @inheritdoc
     */
    public function close()
    {
        parent::close();
        foreach ($this->fileHandles as $handle) {
            fclose($handle);
        }
        $this->fileHandles = [];
        return true;
    }

    /**
     * use temporary files instead of pipes on Windows platform
     * redirect output within the commandline and pass the nul device to the process
     * @see https://bugs.php.net/bug.php?id=51800
     * @see https://bugs.php.net/bug.php?id=65650
     * @inheritdoc
     */
    public function descriptors()
    {
        if (!$this->process->isOutputDisabled()) {
            return [
                ['pipe', 'r'],
                ['file', 'NUL', 'w'],
                ['file', 'NUL', 'w'],
            ];
        }
        $null = fopen('NUL', 'c');
        return [
            ['pipe', 'r'],
            $null,
            $null,
        ];
    }

    /**
     * @inheritdoc
     */
    public function transfer(bool $blocking = false, bool $close = false)
    {
        $output = $read = $error = [];
        $write = $this->unblock()->write();
        if (!$close && $blocking) {
            if ($write) {
                @stream_select($read, $write, $error, 0, Process::TIMEOUT_PRECISION * 1E6);
            } elseif ($this->fileHandles) {
                usleep(Process::TIMEOUT_PRECISION * 1E6);
            }
        }
        foreach ($this->fileHandles as $type => $fileHandle) {
            $data = stream_get_contents($fileHandle, -1, $this->readBytes[$type]);
            if (isset($data[0])) {
                $this->readBytes[$type] += strlen($data);
                $output[$type] = $data;
            }
            if ($close) {
                fclose($fileHandle);
                unset($this->fileHandles[$type]);
            }
        }
        return $output;
    }

    /**
     * @inheritdoc
     */
    public function reset()
    {
        $this->command = $this->cwd = $this->env = null;
        return $this;
    }

    /**
     * close and delete temp files
     */
    public function __destruct()
    {
        $this->close();
        foreach ($this->files as $file) {
            @unlink($file);
        }
        $this->files = [];
    }

    /**
     * code form symfony
     * (c) Fabien Potencier <fabien@symfony.com>
     * Licensed under the MIT/X11 License (http://opensource.org/licenses/MIT)
     * @see https://github.com/symfony/process/blob/e34416d4094d899c052aa4212b6768380250e446/Process.php#L1626
     */
    private function preparedCommand()
    {
        $count = 0;
        $var = [];
        $uid = uniqid('', true);
        $command = parent::command();
        $env = $this->process->getEnv();
        $command = preg_replace_callback(
            '/"(
                [^"%!^]*+
                (?:
                    (?: !LF! | "(?:\^[%!^])?+" )
                    [^"%!^]*+
                )++
            )"/x',
            function ($m) use (&$env, &$var, &$count, $uid) {
                if (isset($var[$m[0]])) {
                    return $var[$m[0]];
                }
                if (false !== strpos($value = $m[1], "\0")) {
                    $value = str_replace("\0", '?', $value);
                }
                if (false === strpbrk($value, "\"%!\n")) {
                    return '"'.$value.'"';
                }
                $value = str_replace(
                    ['!LF!', '"^!"', '"^%"', '"^^"', '""'],
                    ["\n", '!', '%', '^', '"'],
                    $value
                );
                $value = preg_replace('/(\\\\*)"/', '$1$1\\"', $value);
                $var = $uid.++$count;
                $env[$var] = '"'.$value.'"';
                return '!'.$var.'!';     // '%'.$var.'%'
            },
            $command
        );
        // @see https://www.microsoft.com/resources/documentation/windows/xp/all/proddocs/en-us/Cmd.mspx
        $command = 'cmd /V:ON /E:ON /D /C ('.str_replace("\n", ' ', $command).')';
        foreach ($this->getFiles() as $offset => $filename) {
            $command .= ' '.$offset.'>"'.$filename.'"';
        }
        $this->env = $env;
        $this->command = $command;
    }

    /**
     * use temporary files instead of pipes on Windows platform
     * @see https://bugs.php.net/bug.php?id=51800
     * @return array
     */
    private function getFiles()
    {
        if ($this->process->isOutputDisabled()) {
            return [];
        }
        $this->files = [
            1 => tempnam(sys_get_temp_dir(), 'pi_'),
            2 => tempnam(sys_get_temp_dir(), 'pi_'),
        ];
        foreach ($this->files as $type => $file) {
            if (false === $this->fileHandles[$type] = fopen($file, 'rb')) {
                @unlink($file);
                throw new RuntimeException('A temporary file could not be opened to write the process output');
            }
        }
        return $this->files;
    }
}
