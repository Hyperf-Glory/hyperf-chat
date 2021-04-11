<?php

declare(strict_types=1);
/**
 *
 * This is my open source code, please do not use it for commercial applications.
 *
 * For the full copyright and license information,
 * please view the LICENSE file that was distributed with this source code
 *
 * @author CodingHePing<847050412@qq.com>
 * @link   https://github.com/Hyperf-Glory/socket-io
 */
namespace App\Controller;

use App\Kernel\Http\Response;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

abstract class AbstractController
{
    /**
     * @Inject
     * @var ContainerInterface
     */
    protected ContainerInterface $container;

    /**
     * @Inject
     * @var RequestInterface
     */
    protected RequestInterface $request;

    /**
     * @Inject
     * @var EventDispatcherInterface
     */
    protected EventDispatcherInterface $eventDispatcher;

    /**
     * @Inject
     * @var Response
     */
    protected Response $response;

    /**
     * @return int|mixed
     */
    public function uid(): int
    {
        $user = $this->request->getAttribute('user');
        return $user['id'] ?? 0;
    }
}
