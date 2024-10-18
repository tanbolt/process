<?php
namespace Tanbolt\Process;

use Iterator;
use Traversable;
use RuntimeException;

/**
 * Class IteratorInput: 单向数据流, 数据流出后不可重复读取
 * @package Tanbolt\Process
 */
class IteratorInput implements Iterator
{
    /**
     * @var array
     */
    private $input = [];

    /**
     * @var int
     */
    private $key = 0;

    /**
     * @var Traversable|resource|array|string|null
     */
    private $current = '';

    /**
     * @var bool
     */
    private $closed = false;

    /**
     * IterInput constructor.
     * @param null $input
     */
    public function __construct($input = null)
    {
        $this->write($input);
    }

    /**
     * 添加一条数据源
     * @param Traversable|resource|array|string|null $input
     * @return $this
     */
    public function write($input)
    {
        if (null === $input) {
            return $this;
        }
        if ($this->closed) {
            throw new RuntimeException(sprintf('%s is closed', get_class($this)));
        }
        $this->input[] = $input;
        return $this;
    }

    /**
     * 关闭对象
     * @return $this
     */
    public function close()
    {
        if (!$this->closed) {
            $this->closed = true;
        }
        return $this;
    }

    /**
     * 是否已关闭
     * @return bool
     */
    public function isClosed()
    {
        return $this->closed;
    }

    /**
     * @return $this
     */
    public function rewind()
    {
        $this->key = 0;
        $this->current = $this->input ? array_shift($this->input) : '';
        return $this;
    }

    /**
     * @return bool
     */
    public function valid()
    {
        return !$this->closed;
    }

    /**
     * @return string
     */
    public function current()
    {
        return $this->current;
    }

    /**
     * @return int
     */
    public function key()
    {
        return $this->key;
    }

    /**
     * @return $this
     */
    public function next()
    {
        $this->key++;
        $this->current = $this->input ? array_shift($this->input) : '';
        return $this;
    }
}
