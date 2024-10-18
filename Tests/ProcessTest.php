<?php

use PHPUnit\Framework\TestCase;
use Tanbolt\Process\ExitCode;
use Tanbolt\Process\Process;
use Tanbolt\Process\IteratorInput;
use Tanbolt\Process\Pipes\StdInput;
use Tanbolt\Process\Pipes\AbstractPipes;

class ProcessTest extends TestCase
{
    private static function getPhpProcess($code, $isFile = false)
    {
        return new Process($isFile ? [
            PHP_BINARY,
            $code
        ] : [
            PHP_BINARY,
            '-r',
            $code
        ]);
    }

    private static function assertNullOrZero($value)
    {
        static::assertTrue(null === $value || 0 === $value);
    }

    public function testBasicPropertyMethod()
    {
        // __construct
        $process = new Process('foo', __DIR__, $env = ['a' => 'a', 'b' => 'b'], 1.2);
        static::assertEquals('foo', $process->getCommand());
        static::assertTrue(false !== strpos($process->getCommandLine(), 'foo'));
        static::assertEquals(__DIR__, $process->getCwd());
        static::assertEquals($env, $process->getEnv());
        static::assertEquals('a', $process->getEnv('a'));
        static::assertEquals('b', $process->getEnv('b'));
        static::assertEquals(1.2, $process->getTimeout());

        $process = new Process();

        // Command / Arguments
        static::assertNull($process->getCommand());
        static::assertSame($process, $process->setCommand('bar'));
        static::assertEquals('bar', $process->getCommand());
        static::assertTrue(false !== strpos($process->getCommandLine(), 'bar'));
        $process->setCommand($cmd = ['foo', 'bar']);
        static::assertEquals($cmd, $process->getCommand());
        static::assertTrue(is_string($line = $process->getCommandLine()));
        static::assertTrue(false !== strpos($line, 'foo'));
        static::assertTrue(false !== strpos($line, 'bar'));

        // Cwd
        static::assertNull($process->getCwd());
        static::assertSame($process, $process->setCwd('foo'));
        static::assertEquals('foo', $process->getCwd());

        // Env
        static::assertEquals([], $process->getEnv());
        static::assertSame($process, $process->setEnv($env = ['a' => '_a', 'b' => '_b']));
        static::assertEquals($env, $process->getEnv());

        static::assertSame($process, $process->putEnv($env_put = ['a' => '_aa', 'b' => null, 'c' => '_c', 'd' => '_d']));
        $env = array_merge($env, $env_put);
        unset($env['b']);
        static::assertEquals($env, $process->getEnv());

        static::assertSame($process, $process->putEnv('c', '_c2'));
        $env['c'] = '_c2';
        static::assertEquals($env, $process->getEnv());

        foreach ($env as $key => $val) {
            static::assertEquals($val, $process->getEnv($key));
        }

        static::assertSame($process, $process->putEnv('d', null));
        unset($env['d']);
        static::assertEquals($env, $process->getEnv());

        // Timeout
        static::assertEquals(0, $process->getTimeout());
        static::assertSame($process, $process->setTimeout(10));
        static::assertEquals(10, $process->getTimeout());
        static::assertSame($process, $process->setTimeout(5.22));
        static::assertEquals(5.22, $process->getTimeout());

        // IdleTimeout
        static::assertEquals(0, $process->getIdleTimeout());
        static::assertSame($process, $process->setIdleTimeout(8));
        static::assertEquals(8, $process->getIdleTimeout());
        static::assertSame($process, $process->setIdleTimeout(3.76));
        static::assertEquals(3.76, $process->getIdleTimeout());

        // Option
        static::assertEquals([], $process->getOption());
        static::assertSame($process, $process->setOption($option = ['a' => '_a', 'b' => '_b']));
        static::assertEquals($option, $process->getOption());

        static::assertSame($process, $process->putOption($env_put = ['a' => '_aa', 'b' => null, 'c' => '_c', 'd' => '_d']));
        $option = array_merge($option, $env_put);
        unset($option['b']);
        static::assertEquals($option, $process->getOption());

        static::assertSame($process, $process->putOption('c', '_c2'));
        $option['c'] = '_c2';
        static::assertEquals($option, $process->getOption());

        foreach ($option as $key => $val) {
            static::assertEquals($val, $process->getOption($key));
        }

        static::assertSame($process, $process->putOption('d'));
        unset($option['d']);
        static::assertEquals($option, $process->getOption());
    }

    public function testSpecialPropertyMethod()
    {
        $process = new Process();
        static::assertFalse($process->isTty());
        static::assertFalse($process->isPty());
        static::assertFalse($process->isOutputDisabled());
        if (Process::supportTty()) {
            static::assertSame($process, $process->setTty());
            static::assertTrue($process->isTty());
            static::assertSame($process, $process->setTty(false));
            static::assertFalse($process->isTty());
        } else {
            try {
                $process->setTty();
                static::fail('It should throw exception when system not support TTY');
            } catch (\Tanbolt\Process\Exception\RuntimeException $e) {
                static::assertTrue(true);
            }
        }

        if (Process::supportPty()) {
            static::assertSame($process, $process->setPty());
            static::assertTrue($process->isPty());
            static::assertSame($process, $process->setPty(false));
            static::assertFalse($process->isPty());
        } else {
            try {
                $process->setPty();
                static::fail('It should throw exception when system not support PTY');
            } catch (Exception $e) {
                static::assertTrue(true);
            }
        }

        static::assertSame($process, $process->disableOutput());
        static::assertTrue($process->isOutputDisabled());
        static::assertSame($process, $process->disableOutput(false));
        static::assertFalse($process->isOutputDisabled());

        $process->setIdleTimeout(10);
        try {
            $process->disableOutput();
            static::fail('It should throw exception when IdleTimeout above zero');
        } catch (Exception $e) {
            static::assertTrue(true);
        }
    }

    public function testSetInputMethod()
    {
        $process = new Process();
        static::assertSame($process, $process->setInput('input'));
        static::assertInstanceOf(StdInput::class, $process->getInput());

        $process = new Process();
        $input = $process->injectInput('input');
        static::assertInstanceOf(IteratorInput::class, $input);
        static::assertInstanceOf(StdInput::class, $process->getInput());

        static::assertInstanceOf(AbstractPipes::class, $process->pipes());
    }

    public function testProcessStatus()
    {
        $process = static::getPhpProcess('$loop=5; while($loop){ usleep(100000); $loop--; }');
        static::assertNull($process->proc());
        static::assertNull($process->pid());

        static::assertEquals(Process::STATUS_READY, $process->status());
        static::assertFalse($process->isRan());
        static::assertFalse($process->isRunning());
        static::assertFalse($process->isWait());
        static::assertFalse($process->isTerminated());
        static::assertFalse($process->isSignaled());
        static::assertNull($process->getTermSignal());

        static::assertNull($process->exitCode());
        static::assertNull($process->exitText());
        static::assertFalse($process->isSuccessful());

        $process->start();
        static::assertTrue(is_resource($process->proc()));
        static::assertGreaterThan(0, $process->pid());

        static::assertEquals(Process::STATUS_STARTED, $process->status());
        static::assertTrue($process->isRan());
        static::assertTrue($process->isRunning());
        static::assertFalse($process->isWait());
        static::assertFalse($process->isTerminated());
        static::assertFalse($process->isSignaled());
        static::assertNull($process->getTermSignal());

        static::assertNull($process->exitCode());
        static::assertNull($process->exitText());
        static::assertFalse($process->isSuccessful());

        $process->wait(function () use ($process) {
            static::assertTrue(is_resource($process->proc()));
            static::assertGreaterThan(0, $process->pid());

            static::assertEquals(Process::STATUS_WAIT, $process->status());
            static::assertTrue($process->isRan());
            static::assertTrue($process->isRunning());
            static::assertTrue($process->isWait());
            static::assertFalse($process->isTerminated());
            static::assertFalse($process->isSignaled());
            static::assertNull($process->getTermSignal());

            static::assertNull($process->exitCode());
            static::assertNull($process->exitText());
            static::assertFalse($process->isSuccessful());
        });
        static::assertNull($process->proc());
        static::assertNull($process->pid());

        static::assertEquals(Process::STATUS_TERMINATED, $process->status());
        static::assertTrue($process->isRan());
        static::assertFalse($process->isRunning());
        static::assertFalse($process->isWait());
        static::assertTrue($process->isTerminated());
        static::assertFalse($process->isSignaled());
        static::assertNullOrZero($process->getTermSignal());

        static::assertEquals(0, $process->exitCode());
        static::assertEquals(ExitCode::text(0), $process->exitText());
        static::assertTrue($process->isSuccessful());
    }

    public function testKillMethod()
    {
        $process = static::getPhpProcess('$i=0; while(true){ usleep(100000); echo $i++; }');
        $process->run(function ($data) use ($process) {
            if (2 === (int) $data) {
                static::assertTrue($process->isRunning());
                $process->kill();
            }
        });
        static::assertTrue($process->isRan());
        static::assertFalse($process->isRunning());

        static::assertTrue($process->isTerminated());
        static::assertTrue($process->isSignaled());
        static::assertEquals(15, $process->getTermSignal());

        static::assertEquals(143, $process->exitCode());
        static::assertEquals(ExitCode::text(143),  $process->exitText());
        static::assertFalse($process->isSuccessful());
    }

    public function testSignalMethod()
    {
        //发送了没什么反应
        //13: SIGPIPE
        //16: SIGSTKFLT
        //19: SIGSTOP
        //20: SIGTSTP
        //23: SIGURG
        //28: SIGWINCH
        //29: SIGPOLL

        //发送了暂停了
        //17: SIGCHLD
        //18: SIGCONT
        //21: SIGTTIN
        //22: SIGTTOU

        //----
        //27: SIGXFSZ
        if (Process::isWin()) {
            static::markTestSkipped('Win system not support signal method');
        }
        $process = static::getPhpProcess('sleep(32)');
        $process->start()->kill();
        static::assertTrue($process->isTerminated());
        static::assertTrue($process->isSignaled());
        static::assertEquals(15, $process->isSignaled());

        $process = static::getPhpProcess('
            pcntl_signal(\SIGUSR1, function () { echo \'get\'; exit; });
            $i=0;
            while(true){
                usleep(100000);
                echo $i++;
                pcntl_signal_dispatch();
            }
        ');
        $output = '';
        $process->run(function ($data) use ($process, &$output) {
            $output .= $data;
            if (2 === (int) $data) {
                static::assertTrue($process->isRunning());
                $process->signal(SIGUSR1);
            }
        });
        static::assertEquals('0123get', $output);
        static::assertTrue($process->isRan());
        static::assertFalse($process->isRunning());

        static::assertTrue($process->isTerminated());
        if (Process::supportSigchld()) {
            static::assertTrue($process->isSignaled());
            static::assertEquals(SIGUSR1, $process->getTermSignal());
            static::assertGreaterThan(0, $process->exitCode());
            static::assertFalse($process->isSuccessful());
        }
    }

    public function testExitCode()
    {
        $process = static::getPhpProcess('echo "code"; exit(130);');
        $process->run();
        static::assertEquals('code', $process->getOutput());
        static::assertEquals(130, $process->exitCode());
        static::assertEquals(ExitCode::text(130),  $process->exitText());
        static::assertFalse($process->isSuccessful());
    }

    public function testOutputData()
    {
        $process = static::getPhpProcess('
            fwrite(STDOUT, "txt");
            fwrite(STDERR, "err");
        ');
        $process->run();

        // get output
        static::assertEquals('txt', $process->getOutput());
        static::assertEquals('err', $process->getErrorOutput());
        $out = $process->getOutput(true);
        $err = $process->getErrorOutput(true);
        static::assertTrue(is_resource($out));
        static::assertEquals('txt', stream_get_contents($out));
        static::assertTrue(is_resource($err));
        static::assertEquals('err', stream_get_contents($err));

        // add output
        static::assertSame($process, $process->addOutput('_1'));
        static::assertSame($process, $process->addErrorOutput('_2'));
        static::assertEquals('txt_1', $process->getOutput());
        static::assertEquals('err_2', $process->getErrorOutput());
        static::assertEquals('txt_1', stream_get_contents($process->getOutput(true)));
        static::assertEquals('err_2', stream_get_contents($process->getErrorOutput(true)));

        // clear output
        static::assertSame($process, $process->clearOutput());
        static::assertSame($process, $process->clearErrorOutput());
        static::assertEquals('', $process->getOutput());
        static::assertEquals('', $process->getErrorOutput());
        static::assertEquals('', stream_get_contents($process->getOutput(true)));
        static::assertEquals('', stream_get_contents($process->getErrorOutput(true)));
    }

    /**
     * @dataProvider inputDataProvider
     * @param $input
     * @param $output
     */
    public function testInputData($input, $output)
    {
        $process = static::getPhpProcess('stream_copy_to_stream(STDIN, STDOUT);');
        $process->setInput($input);
        $process->run();
        static::assertEquals($output, $process->getOutput());
        if (is_resource($input)) {
            fclose($input);
        }
    }

    public function inputDataProvider()
    {
        $str = 'string';
        $arr = ['foo_', 'bar_', 'biz'];
        $arrStr = join('', $arr);
        $traversable = new PHPUNIT_Process_Traversable($arr);
        $iterator = new ArrayIterator($arr);
        $stream = fopen('php://memory', 'rb+');
        fwrite($stream, $str);
        return [
            [$str, $str],
            [$arr, $arrStr],
            [$traversable, $arrStr],
            [$iterator, $arrStr],
            [$stream, $str],
        ];
    }

    public function testIteratorInput()
    {
        $process = static::getPhpProcess('stream_copy_to_stream(STDIN, STDOUT);');
        $iterInput = $process->injectInput('foo_');
        $iterInput->write(['bar_', 'biz_']);
        $process->run(function ($data) use ($iterInput) {
            if (!$iterInput->isClosed()) {
                if ('biz_' === $data) {
                    $iterInput->write('que');
                } elseif ('que' === $data) {
                    $iterInput->close();
                }
            }
        });
        static::assertEquals('foo_bar_biz_que', $process->getOutput());
    }

    public function testNestInput()
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, '_stream');
        $iter = new \ArrayIterator(['_arr', '_arr2', $stream]);
        $iterInput = new IteratorInput();
        $iterInput->write('_iter1');
        $pp = static::getPhpProcess('echo "_process";');
        $input = ['_string1', '_string2', ['_string3', $pp, $iter], $iterInput];

        $process = static::getPhpProcess('stream_copy_to_stream(STDIN, STDOUT);');
        $process->setInput($input);
        $process->run(function ($data) use ($iterInput) {
            if (!$iterInput->isClosed()) {
                if ('_iter1' === $data) {
                    $iterInput->write('_iter2');
                } elseif ('_iter2' === $data) {
                    $iterInput->close();
                }
            }
        });
        static::assertEquals('_string1_string2_string3_process_arr_arr2_stream_iter1_iter2', $process->getOutput());
    }

    public function testProcessIterator()
    {
        $code = '
            $arr = ["txt", "err", "txt2", "err2"];
            foreach($arr as $k => $x) {
               if (0 === $k % 2) {
                  fwrite(STDOUT, $x);
               } else {
                  fwrite(STDERR, $x);
               }
               flush();
               usleep(100000);
            }
        ';
        $realOutput = [
            Process::STDOUT => ['txt', 'txt2'],
            Process::STDERR => ['err', 'err2']
        ];

        // run callback
        $process = static::getPhpProcess($code);
        $output = [];
        $process->run(function ($data, $type) use (&$output) {
            if (!isset($output[$type])) {
                $output[$type] = [];
            }
            $output[$type][] = $data;
        });
        static::assertEquals($realOutput, $output);

        // IteratorFlags
        $process = static::getPhpProcess($code);
        static::assertEquals(0, $process->getIteratorFlags());
        static::assertSame($process, $process->setIteratorFlags(Process::ITER_SKIP_OUT));
        static::assertEquals(Process::ITER_SKIP_OUT, $process->getIteratorFlags());
        static::assertSame($process, $process->backIteratorFlags());
        static::assertEquals(0, $process->getIteratorFlags());

        // foreach
        $output = [];
        foreach ($process as $type => $data) {
            if (!isset($output[$type])) {
                $output[$type] = [];
            }
            $output[$type][] = $data;
        }
        static::assertEquals($realOutput, $output);

        // IteratorFlags: ITER_SKIP_ERR
        $process = static::getPhpProcess($code);
        $process->setIteratorFlags(Process::ITER_SKIP_ERR);
        $output = [];
        foreach ($process as $type => $data) {
            if (!isset($output[$type])) {
                $output[$type] = [];
            }
            $output[$type][] = $data;
        }
        static::assertEquals([Process::STDOUT => ['txt', 'txt2']], $output);

        // IteratorFlags: ITER_SKIP_OUT
        $process = static::getPhpProcess($code);
        $process->setIteratorFlags(Process::ITER_SKIP_OUT);
        $output = [];
        foreach ($process as $type => $data) {
            if (!isset($output[$type])) {
                $output[$type] = [];
            }
            $output[$type][] = $data;
        }
        static::assertEquals([Process::STDERR => ['err', 'err2']], $output);
    }
}


class PHPUNIT_Process_Traversable implements IteratorAggregate
{
    private $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    /**
     * @inheritDoc
     */
    public function getIterator()
    {
        return new ArrayIterator($this->data);
    }
}

