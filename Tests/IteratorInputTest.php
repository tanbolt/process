<?php

use PHPUnit\Framework\TestCase;
use Tanbolt\Process\IteratorInput;

class IteratorInputTest extends TestCase
{
    public function testWriteMethod()
    {
        $input = new IteratorInput();
        $arr = [
            'a', 'b',
            ['foo', 'bar'],
            'c'
        ];
        static::assertSame($input, $input->write('first'));
        static::assertSame($input, $input->write(null));
        foreach ($arr as $v) {
            $input->write($v);
        }
        array_unshift($arr, 'first');
        $arr2 = [];
        foreach ($input as $v) {
            if (!$v) {
                break;
            }
            $arr2[] = $v;
        }
        static::assertEquals($arr, $arr2);
    }

    public function testCloseMethod()
    {
        $input = new IteratorInput();
        static::assertFalse($input->isClosed());
        static::assertTrue($input->valid());
        static::assertEquals(0, $input->key());
        static::assertEquals('', $input->current());

        $input->write('a');
        static::assertFalse($input->isClosed());
        static::assertTrue($input->valid());
        static::assertEquals(0, $input->key());
        static::assertEquals('', $input->current());

        $input->next();
        static::assertFalse($input->isClosed());
        static::assertTrue($input->valid());
        static::assertEquals(1, $input->key());
        static::assertEquals('a', $input->current());

        $input->next();
        static::assertFalse($input->isClosed());
        static::assertTrue($input->valid());
        static::assertEquals(2, $input->key());
        static::assertEquals('', $input->current());

        $input->write('b')->close();
        static::assertTrue($input->isClosed());
        static::assertFalse($input->valid());
        static::assertEquals(2, $input->key());
        static::assertEquals('', $input->current());
    }
}

