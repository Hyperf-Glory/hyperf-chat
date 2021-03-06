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
namespace App\JsonRpc;

use App\Component\Sms;
use App\Constants\User;
use App\Helper\ValidateHelper;
use App\JsonRpc\Contract\InterfaceUserService;
use App\Model\Users as UserModel;
use App\Service\UserService as UserSer;
use Hyperf\Logger\LoggerFactory;
use Hyperf\RpcServer\Annotation\RpcService;
use Phper666\JWTAuth\JWT;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Class Cloud.
 * @RpcService(name="UserService", protocol="jsonrpc-tcp-length-check", server="jsonrpc", publishTo="consul")
 */
class UserService implements InterfaceUserService
{
    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @var \Phper666\JWTAuth\JWT
     */
    protected $jwt;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @var \App\Service\UserService
     */
    private $userService;

    public function __construct(ContainerInterface $container, UserSer $userService, JWT $jwt)
    {
        $this->container = $container;
        $this->logger = $container->get(LoggerFactory::class)->get();
        $this->userService = $userService;
        $this->jwt = $jwt;
    }

    public function register(string $mobile, string $password, string $smsCode, string $nickname): array
    {
        if (! ValidateHelper::isPhone($mobile)) {
            return ['code' => 0, 'msg' => '手机号格式不正确...'];
        }
        if (! di(Sms::class)->check('user_register', $mobile, $smsCode)) {
            return ['code' => 0, 'msg' => '验证码填写错误...'];
        }
        $bool = $this->userService->register($mobile, $password, $nickname);
        if ($bool) {
            di(Sms::class)->delCode('user_register', $mobile);
            return ['code' => 1, 'msg' => '账号注册成功...'];
        }
        return ['code' => 0, 'msg' => '账号注册失败,手机号已被其他(她)人使用...'];
    }

    /**
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function login(string $mobile, string $password): array
    {
        /**
         * @var UserModel $user
         */
        if (! ($user = UserModel::query()->where('mobile', $mobile)->first())) {
            return ['code' => 0, 'msg' => '登录账号不存在...'];
        }
        if (! $this->userService->checkPassword($password, $user->password)) {
            return ['code' => 0, 'msg' => '登录密码错误...'];
        }
        $token = $this->jwt->setScene('cloud')->getToken([
            'cloud_uid' => $user->id,
            'nickname' => $user->nickname,
        ]);
        return [
            'code' => 1,
            'authorize' => [
                'access_token' => $token,
                'expires_in' => $this->jwt->getTTL(),
            ],
            'user_info' => [
                'uid' => $user->id,
                'nickname' => $user->nickname,
                'avatar' => $user->avatar,
                'motto' => $user->motto,
                'gender' => $user->gender,
            ],
        ];
    }

    /**
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function logout(string $token): void
    {
        try {
            $this->jwt->logout($token);
        } catch (Throwable $throwable) {
            $this->logger->error(sprintf('json-rpc logout Error [%s] [%s]', $token, $throwable->getMessage()));
        }
    }

    public function sendVerifyCode(string $mobile, string $type = User::REGISTER): array
    {
        if (! di(Sms::class)->isUsages($type)) {
            return ['code' => 0, 'msg' => '验证码发送失败...'];
        }
        if (! ValidateHelper::isPhone($mobile)) {
            return ['code' => 0, 'msg' => '手机号格式不正确...'];
        }
        if ($type === 'forget_password') {
            if (! UserModel::query()->where('mobile', $mobile)->value('id')) {
                return ['code' => 0, 'msg' => '手机号未被注册使用...'];
            }
        } elseif ($type === 'change_mobile' || $type === 'user_register') {
            if (UserModel::query()->where('mobile', $mobile)->value('id')) {
                return ['code' => 0, 'msg' => '手机号已被他(她)人注册...'];
            }
        }
        $data['code'] = 1;
        [$isTrue, $result] = di(Sms::class)->send($type, $mobile);
        if ($isTrue) {
            $data['sms_code'] = $result['data']['code'];
        } else {
            $data['code'] = 0;
            // ... 处理发送失败逻辑，当前默认发送成功
        }
        return $data;
    }

    public function forgetPassword(string $mobile, string $smsCode, string $password): array
    {
        if (empty($smsCode) || empty($password) || ! ValidateHelper::isPhone($mobile)) {
            return ['code' => 0, 'msg' => '参数错误...'];
        }
        if (! ValidateHelper::checkPassword($password)) {
            return ['code' => 0, 'msg' => '密码格式不正确...'];
        }
        if (! di(Sms::class)->check('forget_password', $mobile, $smsCode)) {
            return ['code' => 0, 'msg' => '验证码填写错误...'];
        }
        try {
            $bool = $this->userService->resetPassword($mobile, $password);
            if ($bool) {
                di(Sms::class)->delCode('forget_password', $mobile);
            }
            return $bool ? ['code' => 1, 'msg' => '重置密码成功...'] : ['code' => 0, 'msg' => '重置密码失败...'];
        } catch (Throwable $throwable) {
            $this->logger->error(sprintf('json-rpc forgetPassword fail [%s] [%s]', $mobile, $throwable->getMessage()));
            return ['code' => 0, 'msg' => '重置密码失败...'];
        }
    }

    public function get(int $uid): ?array
    {
        try {
            $user = $this->userService->get($uid);
            if ($user) {
                return [
                    'id' => $user->id,
                    'nickname' => $user->nickname,
                    'avatar' => $user->avatar,
                ];
            }
            return null;
        } catch (Throwable $throwable) {
            $this->logger->error(sprintf('json-rpc UserService Error getting user[%s] information', $uid));
        }
        return null;
    }

    /**
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Throwable
     */
    public function checkToken(string $token): bool
    {
        try {
            return $this->jwt->checkToken($token);
        } catch (\Throwable $throwable) {
            $this->logger->error(sprintf('json-rpc CheckToken Fail [%s]  [%s]', $token, $throwable->getMessage()));
            return false;
        }
    }

    public function decodeToken(string $token): array
    {
        return $this->jwt->getParserData($token);
    }
}
