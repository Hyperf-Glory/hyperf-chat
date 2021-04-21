<?php
declare(strict_types = 1);

namespace App\Controller\Http;

use App\Controller\AbstractController;
use App\Model\Emoticon;
use App\Model\EmoticonDetail;
use App\Service\EmoticonService;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Hyperf\Validation\ValidationException;
use League\Flysystem\Filesystem;
use Psr\Http\Message\ResponseInterface;

/**
 * Class EmoticonController
 * @package App\Controller\Http
 */
class EmoticonController extends AbstractController
{

    protected EmoticonService $emoticon;
    protected ValidatorFactoryInterface $validationFactory;

    public function __construct(EmoticonService $service, ValidatorFactoryInterface $validationFactory)
    {
        $this->emoticon          = $service;
        $this->validationFactory = $validationFactory;
    }

    /**
     * 获取用户表情包列表
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function getUserEmoticon() : ResponseInterface
    {
        $emoticonList = [];
        $user_id      = $this->uid();

        if ($ids = $this->emoticon->getInstallIds($user_id)) {
            $items = Emoticon::whereIn('id', $ids)->get(['id', 'name', 'url']);
            foreach ($items as $item) {
                $emoticonList[] = [
                    'emoticon_id' => $item->id,
                    'url'         => get_media_url($item->url),
                    'name'        => $item->name,
                    'list'        => $this->emoticon->getDetailsAll([
                        ['emoticon_id', '=', $item->id],
                        ['user_id', '=', 0],
                    ]),
                ];
            }
        }

        return $this->response->success('success', [
            'sys_emoticon'     => $emoticonList,
            'collect_emoticon' => $this->emoticon->getDetailsAll([
                ['emoticon_id', '=', 0],
                ['user_id', '=', $user_id],
            ]),
        ]);
    }

    /**
     * 获取系统表情包
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function getSystemEmoticon() : ResponseInterface
    {
        $items = Emoticon::get(['id', 'name', 'url'])->toArray();
        if ($items) {
            $ids = $this->emoticon->getInstallIds($this->uid());

            array_walk($items, static function (&$item) use ($ids)
            {
                $item['status'] = in_array($item['id'], $ids, true) ? 1 : 0;
                $item['url']    = get_media_url($item['url']);
            });
        }

        return $this->response->success('success', $items);
    }

    /**
     * 安装或移除系统表情包
     *
     * @param \Hyperf\HttpServer\Contract\RequestInterface $request
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function setUserEmoticon(RequestInterface $request) : ResponseInterface
    {

        $validator = $this->validationFactory->make($request->all(), [
            'emoticon_id' => 'required|integer',
            'type'        => 'required|in:1,2'
        ]);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
        $data    = $validator->validated();
        $user_id = $this->uid();
        if ($data['type'] === 2) {//移除表情包
            $bool = $this->emoticon->removeSysEmoticon($user_id, $data['emoticon_id']);
            return $bool ? $this->response->success('移除表情包成功...') : $this->response->error('移除表情包失败...');
        }  //添加表情包
        /**
         * @var Emoticon $emoticonInfo
         */
        $emoticonInfo = Emoticon::where('id', $data['emoticon_id'])->first(['id', 'name', 'url']);
        if (!$emoticonInfo) {
            return $this->response->error('添加表情包失败...');
        }

        if (!$this->emoticon->installSysEmoticon($user_id, $data['emoticon_id'])) {
            return $this->response->error('添加表情包失败...');
        }

        return $this->response->success('添加表情包成功', [
            'emoticon_id' => $emoticonInfo->id,
            'url'         => get_media_url($emoticonInfo->url),
            'name'        => $emoticonInfo->name,
            'list'        => $this->emoticon->getDetailsAll([
                ['emoticon_id', '=', $emoticonInfo->id],
            ]),
        ]);
    }

    /**
     * 自定义上传表情包
     *
     * @param \League\Flysystem\Filesystem $filesystem
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function uploadEmoticon(Filesystem $filesystem) : ResponseInterface
    {
        $file = $this->request->file('emoticon');
        if (!$file->isValid()) {
            return $this->response->error('图片上传失败，请稍后再试...');
        }

        $ext = $file->getExtension();
        if (!in_array($ext, ['jpg', 'png', 'jpeg', 'gif', 'webp'])) {
            return $this->response->error('图片格式错误，目前仅支持jpg、png、jpeg、gif和webp');
        }

        $imgInfo   = getimagesize($file->getRealPath());
        $filename  = create_image_name($ext, $imgInfo[0], $imgInfo[1]);
        $save_path = 'media/images/emoticon/' . date('Ymd') . '/' . $filename;
        $stream    = fopen($file->getRealPath(), 'rb+');
        if (!$filesystem->put($save_path, $stream)) {
            fclose($stream);
            return $this->response->error('图片上传失败');
        }
        fclose($stream);
        $result = EmoticonDetail::create([
            'user_id'     => $this->uid(),
            'url'         => $save_path,
            'file_suffix' => $ext,
            'file_size'   => $file->getSize(),
            'created_at'  => time(),
        ]);

        return $result ? $this->response->success('success', [
            'media_id' => $result->id,
            'src'      => get_media_url($result->url),
        ]) : $this->response->error('表情包上传失败...');
    }

    /**
     * 收藏聊天图片的我的表情包.
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function collectEmoticon() : ResponseInterface
    {
        $id = (int)$this->request->post('record_id');

        [$bool, $data] = $this->emoticon->collect($this->uid(), $id);

        return $bool ? $this->response->success('success', [
            'emoticon' => $data,
        ]) : $this->response->error('添加表情失败');
    }

    /**
     * 移除收藏的表情包.
     * @return null|\Psr\Http\Message\ResponseInterface
     */
    public function delCollectEmoticon() : ?ResponseInterface
    {
        $ids = $this->request->post('ids');
        if (empty($ids)) {
            return $this->response->parameterError();
        }

        $ids = explode(',', trim($ids));
        if (empty($ids)) {
            return $this->response->parameterError();
        }

        try {
            return $this->emoticon->deleteCollect($this->uid(), $ids) ?
                $this->response->success('success') :
                $this->response->error('fail');
        } catch (\Exception $e) {
            return $this->response->fail($e->getMessage());
        }
    }
}
