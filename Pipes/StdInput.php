<?php
namespace Tanbolt\Process\Pipes;

use Iterator;
use Traversable;
use ArrayIterator;
use IteratorIterator;
use Tanbolt\Process\Process;
use Tanbolt\Process\Exception\InvalidArgumentException;

/**
 * Class StdInput
 * @package Tanbolt\Process\Pipes
 */
class StdInput
{
    /**
     * @var Iterator[]
     */
    private $input = [];

    /**
     * @var mixed
     */
    private $stream;

    /**
     * @var int
     */
    private $streamOffset = 0;

    /**
     * @var string
     */
    private $buffer = '';

    /**
     * @var bool
     */
    private $efo = true;

    /**
     * Input constructor.
     * @param $input
     */
    public function __construct($input = null)
    {
        $this->setInput($input);
    }

    /**
     * 设置 input 数据源
     * @param Process|Iterator|Traversable|resource|array|string|mixed $input
     * @return $this
     */
    public function setInput($input)
    {
        $this->efo = true;
        $this->input = [];
        $this->buffer = '';
        $this->stream = null;
        $this->streamOffset = 0;
        if ($input) {
            $input = static::checkInput($input);
            if (is_string($input)) {
                $this->buffer = $input;
            } elseif (is_resource($input)) {
                $this->stream = $input;
            } else {
                $this->input[] = $input;
            }
            $this->efo = false;
        }
        return $this;
    }

    /**
     * 是否已读取到结尾
     * @return bool
     */
    public function efo()
    {
        return $this->efo;
    }

    /**
     * @param resource $pipe
     * @return bool
     */
    public function writeTo($pipe)
    {
        $write = [$pipe];
        $read = $error = [];

        // if streams changed
        if (false === @stream_select($read, $write, $error, 0, 0)) {
            return false;
        }
        $stream = false;
        foreach ($write as $std) {
            // 写入 string 数据源 或 上次预读取的 string 残余数据
            if (isset($this->buffer[0])) {
                $written = fwrite($std, $this->buffer);
                $this->buffer = substr($this->buffer, $written);
                if ($this->buffer) {
                    return true;
                }
            }
            // 获取数据源 并写入
            if (false === $stream) {
                $stream = $this->getStream();
            }
            if ($stream) {
                while (true) {
                    fseek($stream, $this->streamOffset);
                    $data = fread($stream, Process::CHUNK_SIZE);
                    if (!isset($data[0])) {
                        break;
                    }
                    $this->streamOffset += strlen($data);
                    $data = substr($data, fwrite($std, $data));
                    if (isset($data[0])) {
                        $this->buffer = $data;
                        return true;
                    }
                }
                if (feof($stream)) {
                    $this->stream = null;
                }
            }
        }
        // 写入完成，重置状态
        if (!isset($this->buffer[0]) && false === $stream) {
            $this->input = [];
            $this->stream = null;
            $this->streamOffset = 0;
            $this->efo = true;
            return true;
        }
        return !$write;
    }

    /**
     * 获取 stream 或设置 buffer
     * @return resource|false|null
     */
    private function getStream()
    {
        if ($this->stream) {
            return $this->stream;
        }
        if (!$this->input) {
            return false;
        }
        $input = $this->getInputFromIterator();
        if (false === $input) {
            return false;
        }
        if (is_resource($input)) {
            stream_set_blocking($input, 0);
            $this->streamOffset = 0;
            return $this->stream = $input;
        }
        $this->buffer = $input;
        return $this->stream = null;
    }

    /**
     * @return resource|string|false
     */
    private function getInputFromIterator()
    {
        if (!$this->input) {
            return false;
        }
        $iterator = array_pop($this->input);
        if (!$iterator->valid()) {
            if ($iterator instanceof Process) {
                $iterator->backIteratorFlags();
            }
            return $this->getInputFromIterator();
        }
        $this->input[] = $iterator;
        $current = static::checkInput($iterator->current());
        $iterator->next();
        if ($current instanceof Iterator) {
            $this->input[] = $current;
            return $this->getInputFromIterator();
        }
        return $current;
    }

    /**
     * 校验 input 合法性
     * @param Iterator|Traversable|resource|array|string|mixed $input
     * @return Iterator|resource|string
     */
    private static function checkInput($input)
    {
        if (is_string($input) || is_resource($input)) {
            return $input;
        }
        if (is_scalar($input)) {
            return (string) $input;
        }
        if ($input instanceof Iterator) {
            if ($input instanceof Process) {
                $input->setIteratorFlags(Process::ITER_SKIP_ERR);
            }
            return $input;
        }
        if ($input instanceof Traversable) {
            $input = new IteratorIterator($input);
            $input->rewind();
            return $input;
        }
        if (is_array($input)) {
            return new ArrayIterator($input);
        }
        if (is_object($input) && method_exists($input, '__toString')) {
            return $input->__toString();
        }
        throw new InvalidArgumentException(
            'Input only accepts strings, array, stream resources, traversable objects, given type:'.gettype($input)
        );
    }
}
