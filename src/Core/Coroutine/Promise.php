<?php declare(strict_types=1);
/*
 * Copyright (c) 2023-2024.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * 特此免费授予任何获得本软件及相关文档文件（“软件”）副本的人，不受限制地处理
 * 本软件，包括但不限于使用、复制、修改、合并、出版、发行、再许可和/或销售
 * 软件副本的权利，并允许向其提供本软件的人做出上述行为，但须符合以下条件：
 *
 * 上述版权声明和本许可声明应包含在本软件的所有副本或主要部分中。
 *
 * 本软件按“原样”提供，不提供任何形式的保证，无论是明示或暗示的，
 * 包括但不限于适销性、特定目的的适用性和非侵权性的保证。在任何情况下，
 * 无论是合同诉讼、侵权行为还是其他方面，作者或版权持有人均不对
 * 由于软件或软件的使用或其他交易而引起的任何索赔、损害或其他责任承担责任。
 */

namespace Psc\Core\Coroutine;

use Closure;
use Psc\Core\Coroutine\Exception\Exception;
use Psc\Utils\Output;
use Throwable;

use function array_reverse;
use function call_user_func;
use function call_user_func_array;
use function Co\async;
use function Co\await;
use function Co\promise;
use function count;

/**
 * @Author cclilshy
 * @Date   2024/8/16 09:35
 *
 * Strictly follow the design philosophy of ES6-Promise/A+
 * @see    https://promisesaplus.com/
 */
class Promise
{
    public const PENDING   = 'pending';
    public const FULFILLED = 'fulfilled';
    public const REJECTED  = 'rejected';

    /*** @var mixed */
    public mixed $result;

    /*** @var string */
    protected string $status = Promise::PENDING;

    /*** @var Closure[] */
    protected array $onFulfilled = array();

    /*** @var Closure[] */
    protected array $onRejected = array();

    /*** @param Closure $closure */

    /**
     *
     * The created Promise instance will immediately execute the passed closure instead of pushing it to the next step in the queue.
     *
     * @param Closure $closure
     */
    public function __construct(Closure $closure)
    {
        $this->execute($closure);
    }

    /**
     * execute closure
     *
     * @param Closure $closure
     *
     * @return void
     */
    protected function execute(Closure $closure): void
    {
        try {
            call_user_func_array($closure, [
                fn (mixed $result = null) => $this->resolve($result),
                fn (mixed $result = null) => $this->reject($result),
                $this
            ]);
        } catch (Throwable $exception) {
            try {
                $this->reject($exception);
            } catch (Throwable $e) {
                Output::error($e->getMessage());
            }
        }
    }

    /**
     * Change the promise status to completed and pass the result to subsequent actions,
     * The completed status cannot be changed, the second call will be ignored
     *
     * @param mixed $value
     *
     * @return void
     */
    public function resolve(mixed $value): void
    {
        if ($value instanceof Promise) {
            try {
                $this->resolve(await($value));
            } catch (Throwable $e) {
                $this->reject($e);
            }
            return;
        }

        if ($this->status !== Promise::PENDING) {
            return;
        }

        $this->status = Promise::FULFILLED;
        $this->result = $value;

        foreach (array_reverse($this->onFulfilled) as $onFulfilled) {
            try {
                call_user_func($onFulfilled, $value);
            } catch (Throwable $exception) {
                Output::error($exception->getMessage());
            }
        }
    }

    /**
     * Change the promise status to rejected and pass the reason to subsequent actions,
     * Unable to change rejected status, second call will be ignored
     *
     * @param Throwable $reason
     *
     * @return void
     */
    public function reject(mixed $reason): void
    {
        if ($this->status !== Promise::PENDING) {
            return;
        }

        $this->status = Promise::REJECTED;
        $this->result = $reason;

        foreach (array_reverse($this->onRejected) as $onRejected) {
            try {
                call_user_func($onRejected, $reason);
            } catch (Throwable $reason) {
                Output::error($reason->getMessage());
            }
        }
    }

    /**
     * Define subsequent behavior. When the Promise state changes, it will be called in the order of the then method.
     * If the Promise has been completed, execute it immediately
     *
     * @param Closure|null $onFulfilled
     * @param Closure|null $onRejected
     *
     * @return $this
     */
    public function then(Closure|null $onFulfilled = null, Closure|null $onRejected = null): Promise
    {
        if ($onFulfilled) {
            if ($this->status === Promise::FULFILLED) {
                try {
                    call_user_func($onFulfilled, $this->result);
                } catch (Throwable $exception) {
                    Output::error($exception->getMessage());
                }
                return $this;
            } else {
                $this->onFulfilled[] = $onFulfilled;
            }
        }

        if ($onRejected) {
            $this->except($onRejected);
        }
        return $this;
    }

    /**
     * Define the behavior after rejection. When the Promise state changes, it will be called in the order of the except method.
     * If the Promise has been rejected, execute it immediately
     *
     * @param Closure $onRejected
     *
     * @return $this
     */
    public function except(Closure $onRejected): Promise
    {
        if ($this->status === Promise::REJECTED) {
            try {
                call_user_func($onRejected, $this->result);
            } catch (Throwable $exception) {
                Output::error($exception->getMessage());
            }
            return $this;
        } else {
            $this->onRejected[] = $onRejected;
        }
        return $this;
    }

    /**
     * Define subsequent behavior. When the Promise state changes, it will be called in the order of the then method.
     *
     * @param Closure $onFinally
     *
     * @return $this
     */
    public function finally(Closure $onFinally): Promise
    {
        if ($this->status !== Promise::PENDING) {
            try {
                call_user_func($onFinally, $this->result);
            } catch (Throwable $exception) {
                Output::error($exception->getMessage());
            }
            return $this;
        } else {
            $this->onFulfilled[] = $onFinally;
            $this->onRejected[]  = $onFinally;
        }

        return $this;
    }

    /**
     * Define the behavior after rejection. When the Promise state changes, it will be called in the order of the catch method.
     * If the Promise has been rejected, execute it immediately
     *
     * @param Closure $onRejected
     *
     * @return $this
     * @deprecated You should use the except method because this method is a reserved keyword
     */
    public function catch(Closure $onRejected): Promise
    {
        return $this->except($onRejected);
    }

    /**
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @return mixed
     */
    public function getResult(): mixed
    {
        return $this->result;
    }

    /**
     * @return string
     */
    public function getState(): string
    {
        return $this->status;
    }

    /**
     * @param bool $unwrap
     *
     * @return mixed
     * @throws Throwable
     */
    public function wait(bool $unwrap = true): mixed
    {
        return $this->await();
    }

    /**
     * @return mixed
     * @throws Throwable
     */
    public function await(): mixed
    {
        return await($this);
    }

    /**
     * @return void
     * @throws Exception
     */
    public function cancel(): void
    {
        // TODO: Implement cancel() method.
        throw new Exception('Method not implemented');
    }

    /**
     * @param Closure $onRejected
     *
     * @return Promise
     */
    public function otherwise(Closure $onRejected): Promise
    {
        return $this->except($onRejected);
    }


    /**
     * @param Promise[] $promises
     *
     * @return \Psc\Core\Coroutine\Promise
     */
    public static function all(array $promises): Promise
    {
        return promise(static function (Closure $resolve, Closure $reject) use ($promises) {
            $results = [];

            foreach ($promises as $promise) {
                try {
                    $results[] = $promise->await();
                } catch (Throwable $exception) {
                    $reject($exception);
                    return;
                }
            }

            $resolve($results);
        });
    }

    /**
     * @param Promise[] $promises
     *
     * @return \Psc\Core\Coroutine\Promise
     */
    public static function allSettled(array $promises): Promise
    {
        return async(static function (Closure $resolve) use ($promises) {
            $waitGroup = new WaitGroup(count($promises));

            foreach ($promises as $promise) {
                $promise->then(
                    static fn () => $waitGroup->done(),
                    static fn () => $waitGroup->done()
                );
            }

            $waitGroup->wait();

            $resolve($promises);
        });
    }

    /**
     * @param array $promises
     *
     * @return \Psc\Core\Coroutine\Futures
     */
    public static function futures(array $promises): Futures
    {
        return new Futures($promises);
    }
}
