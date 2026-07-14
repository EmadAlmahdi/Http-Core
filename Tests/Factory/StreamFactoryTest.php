<?php

declare(strict_types=1);

namespace Temant\HttpCore\Tests\Factory;

use Interop\Http\Factory\StreamFactoryTestCase;
use Temant\HttpCore\Factory\StreamFactory;

class StreamFactoryTest extends StreamFactoryTestCase
{
    protected function createStreamFactory(): StreamFactory
    {
        return new StreamFactory();
    }
}