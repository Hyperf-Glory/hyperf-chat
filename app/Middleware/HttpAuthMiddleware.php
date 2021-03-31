<?php

declare(strict_types = 1);
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
namespace App\Middleware;

use App\Component\MessageParser;
use App\JsonRpc\Contract\InterfaceUserService;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Phper666\JWTAuth\Exception\JWTException;
use Phper666\JWTAuth\Exception\TokenValidException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class HttpAuthMiddleware extends AbstractMiddleware implements MiddlewareInterface
{

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler) : ResponseInterface
    {
        $isValidToken = false;
        try {
            $token = $request->getHeader('Authorization')[0] ?? '';
            if (empty($token)) {
                $token = $this->prefix . ' ' . ($request->getQueryParams()['token'] ?? '');
            }
            $token = ucfirst($token);
            $arr   = explode($this->prefix . ' ', $token);
            $token = $arr[1] ?? '';

            if (($token !== '') && di(InterfaceUserService::class)->checkToken($token)) {
                $request = $this->setRequestContext($token);
                return $handler->handle($request);
            }
            if (!$isValidToken) {
                throw new TokenValidException('Token authentication does not pass', 401);
            }
        } catch (TokenValidException | JWTException $throwable) {
            return $this->httpResponse->response()->withHeader('Server', 'SocketIO')->withStatus(401)->withBody(new SwooleStream('Token authentication does not pass'));
        } catch (\Throwable $exception) {
            if (env('APP_ENV') === 'dev') {
                return $this->httpResponse->response()->withHeader('Server', 'SocketIO')->withStatus(500)->withBody(new SwooleStream(MessageParser::encode([
                    'msg'   => $exception->getMessage(),
                    'trace' => $exception->getTrace(),
                    'line'  => $exception->getLine(),
                    'file'  => $exception->getFile(),
                ])));
            }
            return $this->httpResponse->response()->withHeader('Server', 'SocketIO')->withStatus(500)->withBody(new SwooleStream('服务端错误!'));
        }
    }

}
