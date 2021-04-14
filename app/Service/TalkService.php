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
namespace App\Service;

use App\Cache\FriendRemarkCache;
use App\Cache\LastMsgCache;
use App\Component\MessageParser;
use App\Component\UnreadTalk;
use App\Model\ChatRecordsDelete;
use App\SocketIO\SocketIO;
use App\Model\ChatRecord;
use App\Model\ChatRecordsCode;
use App\Model\ChatRecordsFile;
use App\Model\ChatRecordsForward;
use App\Model\ChatRecordsInvite;
use App\Model\Group;
use App\Model\User;
use App\Model\UsersChatList;
use App\Model\UsersFriend;
use App\Service\Traits\PagingTrait;
use Carbon\Carbon;
use Exception;
use Hyperf\DbConnection\Db;
use Hyperf\Redis\RedisFactory;
use Hyperf\Utils\ApplicationContext;
use RuntimeException;

class TalkService
{
    use PagingTrait;

    /**
     * 获取用户的聊天列表.
     *
     * @param int $uid 用户ID
     */
    public function talks(int $uid) : array
    {
        $filed = [
            'list.id',
            'list.type',
            'list.friend_id',
            'list.group_id',
            'list.updated_at',
            'list.not_disturb',
            'list.is_top',
            'users.avatar as user_avatar',
            'users.nickname',
            'group.group_name',
            'group.avatar as group_avatar',
        ];

        $rows = UsersChatList::from('users_chat_list as list')
                             ->leftJoin('users', 'users.id', '=', 'list.friend_id')
                             ->leftJoin('group as group', 'group.id', '=', 'list.group_id')
                             ->where('list.uid', $uid)
                             ->where('list.status', 1)
                             ->orderBy('updated_at', 'desc')
                             ->get($filed)
                             ->toArray();

        if (!$rows) {
            return [];
        }

        return array_map(static function ($item) use ($uid)
        {
            $data['id']          = $item['id'];
            $data['type']        = $item['type'];
            $data['friend_id']   = $item['friend_id'];
            $data['group_id']    = $item['group_id'];
            $data['name']        = ''; //对方昵称/群名称
            $data['unread_num']  = 0; //未读消息数量
            $data['avatar']      = ''; //默认头像
            $data['remark_name'] = ''; //好友备注
            $data['msg_text']    = '......';
            $data['updated_at']  = $item['updated_at'];
            $data['online']      = 0;
            $data['not_disturb'] = $item['not_disturb'];
            $data['is_top']      = $item['is_top'];
            $redis               = ApplicationContext::getContainer()->get(RedisFactory::class)->get(env('CLOUD_REDIS'));
            if ($item['type'] === 1) {
                $data['name']       = $item['nickname'];
                $data['avatar']     = $item['user_avatar'];
                $data['unread_num'] = ApplicationContext::getContainer()->get(UnreadTalk::class)->get($uid, $item['friend_id']);
                $data['online']     = $redis->hGet(SocketIO::HASH_UID_TO_SID_PREFIX, (string)$item['friend_id']) ? 1 : 0;

                $remark = make(FriendRemarkCache::class)->get($uid, $item['friend_id']);
                if ($remark) {
                    $data['remark_name'] = $remark;
                } else {
                    /**
                     * @var \App\Model\UsersFriend $info
                     */
                    $info = UsersFriend::select(['user1', 'user2', 'user1_remark', 'user2_remark'])
                                       ->where('user1', ($uid < $item['friend_id']) ? $uid : $item['friend_id'])
                                       ->where('user2', ($uid < $item['friend_id']) ? $item['friend_id'] : $uid)->first();
                    if ($info) {
                        $data['remark_name'] = ($info->user1 === $item['friend_id']) ? $info->user2_remark : $info->user1_remark;

                        make(FriendRemarkCache::class)->set($uid, $item['friend_id'], $data['remark_name']);
                    }
                }
            } else {
                $data['name']   = $item['group_name'] ?? '';
                $data['avatar'] = $item['group_avatar'] ?? '';
            }

            $records = make(LastMsgCache::class)->get($item['type'] === 1 ? $item['friend_id'] : $item['group_id'], $item['type'] === 1 ? $uid : 0);

            if ($records) {
                $data['msg_text']   = $records['text'];
                $data['updated_at'] = $records['created_at'] instanceof Carbon ? $records['created_at']->toDateTimeString() : $item['updated_at'];
            }

            return $data;
        }, $rows);
    }

    /**
     * 同步未读的消息到数据库中.
     *
     * @param int $uid
     * @param     $data
     */
    public function updateUnreadTalkList(int $uid, $data) : void
    {
        foreach ($data as $friend_id => $num) {
            UsersChatList::updateOrCreate([
                'uid'       => $uid,
                'friend_id' => (int)($friend_id),
                'type'      => 1,
            ], [
                'status'     => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * 处理聊天记录信息.
     *
     * @param array $rows 聊天记录
     */
    public function handleChatRecords(array $rows) : array
    {
        if (empty($rows)) {
            return [];
        }

        $files = $codes = $forwards = $invites = [];
        foreach ($rows as $value) {
            switch ($value['msg_type']) {
                case 2:
                    $files[] = $value['id'];
                    break;
                case 3:
                    $invites[] = $value['id'];
                    break;
                case 4:
                    $forwards[] = $value['id'];
                    break;
                case 5:
                    $codes[] = $value['id'];
                    break;
            }
        }

        // 查询聊天文件信息
        if ($files) {
            $files = ChatRecordsFile::whereIn('record_id', $files)->get(['id', 'record_id', 'user_id', 'file_source', 'file_type', 'save_type', 'original_name', 'file_suffix', 'file_size', 'save_dir'])->keyBy('record_id')->toArray();
        }

        // 查询群聊邀请信息
        if ($invites) {
            $invites = ChatRecordsInvite::whereIn('record_id', $invites)->get(['record_id', 'type', 'operate_user_id', 'user_ids'])->keyBy('record_id')->toArray();
        }

        // 查询代码块消息
        if ($codes) {
            $codes = ChatRecordsCode::whereIn('record_id', $codes)->get(['record_id', 'code_lang', 'code'])->keyBy('record_id')->toArray();
        }

        // 查询消息转发信息
        if ($forwards) {
            $forwards = ChatRecordsForward::whereIn('record_id', $forwards)->get(['record_id', 'records_id', 'text'])->keyBy('record_id')->toArray();
        }

        foreach ($rows as $k => $row) {
            $rows[$k]['file']       = [];
            $rows[$k]['code_block'] = [];
            $rows[$k]['forward']    = [];
            $rows[$k]['invite']     = [];

            switch ($row['msg_type']) {
                case 2://2:文件消息
                    $rows[$k]['file'] = $files[$row['id']] ?? [];
                    if ($rows[$k]['file']) {
                        $rows[$k]['file']['file_url'] = config('image_url') . '/' . $rows[$k]['file']['save_dir'];
                    }
                    break;
                case 3://3:入群消息/退群消息
                    if (isset($invites[$row['id']])) {
                        $rows[$k]['invite'] = [
                            'type'         => $invites[$row['id']]['type'],
                            'operate_user' => [
                                'id'       => $invites[$row['id']]['operate_user_id'],
                                'nickname' => User::where('id', $invites[$row['id']]['operate_user_id'])->value('nickname')
                            ],
                            'users'        => []
                        ];

                        if ($rows[$k]['invite']['type'] === 1 || $rows[$k]['invite']['type'] === 3) {
                            $rows[$k]['invite']['users'] = User::select('id', 'nickname')->whereIn('id', explode(',', $invites[$row['id']]['user_ids']))->get()->toArray();
                        } else {
                            $rows[$k]['invite']['users'] = $rows[$k]['invite']['operate_user'];
                        }
                    }
                    break;
                case 4://4:会话记录消息
                    if (isset($forwards[$row['id']])) {
                        $rows[$k]['forward'] = [
                            'num'  => substr_count($forwards[$row['id']]['records_id'], ',') + 1,
                            'list' => MessageParser::decode($forwards[$row['id']]['text']) ?? []
                        ];
                    }
                    break;
                case 5://5:代码块消息
                    $rows[$k]['code_block'] = $codes[$row['id']] ?? [];
                    if ($rows[$k]['code_block']) {
                        $rows[$k]['code_block']['code'] = htmlspecialchars_decode($rows[$k]['code_block']['code']);
                        unset($rows[$k]['code_block']['record_id']);
                    }
                    break;
            }
        }

        unset($files, $codes, $forwards, $invites);
        return $rows;
    }

    /**
     * 查询对话页面的历史聊天记录.
     *
     * @param int   $uid        用户ID
     * @param int   $receive_id 接收者ID（好友ID或群ID）
     * @param int   $source     消息来源  1:好友消息 2:群聊消息
     * @param int   $record_id  上一次查询的聊天记录ID
     * @param int   $limit      查询数据长度
     * @param array $msg_type   消息类型
     *
     * @return mixed
     */
    public function getChatRecords(int $uid, int $receive_id, int $source, int $record_id, $limit = 30, $msg_type = []) : array
    {
        $fields = [
            'chat_records.id',
            'chat_records.source',
            'chat_records.msg_type',
            'chat_records.user_id',
            'chat_records.receive_id',
            'chat_records.content',
            'chat_records.is_revoke',
            'chat_records.created_at',
            'users.nickname',
            'users.avatar as avatar',
        ];

        $rowsSqlObj = ChatRecord::select($fields);

        $rowsSqlObj->leftJoin(User::newModelInstance()->getTable(), 'users.id', '=', 'chat_records.user_id');
        if ($record_id) {
            $rowsSqlObj->where('chat_records.id', '<', $record_id);
        }

        if ($source === 1) {
            $rowsSqlObj->where(function ($query) use ($uid, $receive_id)
            {
                $query->where([
                    ['chat_records.user_id', '=', $uid],
                    ['chat_records.receive_id', '=', $receive_id],
                ])->orWhere([
                    ['chat_records.user_id', '=', $receive_id],
                    ['chat_records.receive_id', '=', $uid],
                ]);
            });
        } else {
            $rowsSqlObj->where('chat_records.receive_id', $receive_id);
            $rowsSqlObj->where('chat_records.source', $source);
        }

        if ($msg_type) {
            $rowsSqlObj->whereIn('chat_records.msg_type', $msg_type);
        }

        //过滤用户删除记录
        $rowsSqlObj->whereNotExists(function ($query) use ($uid)
        {
            $query->select(Db::raw(1))->from(ChatRecordsDelete::newModelInstance()->getTable());
            $query->whereRaw("im_chat_records_delete.record_id = im_chat_records.id and im_chat_records_delete.user_id = $uid");
            $query->limit(1);
        });

        $rows = $rowsSqlObj->orderBy('chat_records.id', 'desc')->limit($limit)->get()->toArray();
        return $this->handleChatRecords($rows);
    }

    /**
     * 获取转发会话记录信息.
     *
     * @param int $uid       用户ID
     * @param int $record_id 聊天记录ID
     */
    public function getForwardRecords(int $uid, int $record_id) : array
    {
        /**
         * @var ChatRecord $result
         */
        $result = ChatRecord::where('id', $record_id)->first([
            'id',
            'source',
            'msg_type',
            'user_id',
            'receive_id',
            'content',
            'is_revoke',
            'created_at',
        ]);

        //判断是否有权限查看
        if ($result->source === 1 && ($result->user_id !== $uid && $result->receive_id !== $uid)) {
            return [];
        }
        if ($result->source === 2 && !Group::isMember($result->receive_id, $uid)) {
            return [];
        }

        /**
         * @var ChatRecordsForward $forward
         */
        $forward = ChatRecordsForward::where('record_id', $record_id)->first();

        $fields = [
            'chat_records.id',
            'chat_records.source',
            'chat_records.msg_type',
            'chat_records.user_id',
            'chat_records.receive_id',
            'chat_records.content',
            'chat_records.is_revoke',
            'chat_records.created_at',
            'users.nickname',
            'users.avatar as avatar',
        ];

        $rowsSqlObj = ChatRecord::select($fields);
        $rowsSqlObj->leftJoin(User::newModelInstance()->getTable(), 'users.id', '=', 'chat_records.user_id');
        $rowsSqlObj->whereIn('chat_records.id', explode(',', $forward->records_id));

        return $this->handleChatRecords($rowsSqlObj->get()->toArray());
    }

    /**
     * 批量删除聊天消息.
     *
     * @param int   $uid        用户ID
     * @param int   $source     消息来源  1:好友消息 2:群聊消息
     * @param int   $receive_id 好友ID或者群聊ID
     * @param array $record_ids 聊天记录ID
     */
    public function removeRecords(int $uid, int $source, int $receive_id, array $record_ids) : bool
    {
        if ($source === 1) {//私聊信息
            $ids = ChatRecord::whereIn('id', $record_ids)->where(function ($query) use ($uid, $receive_id)
            {
                $query->where([['user_id', '=', $uid], ['receive_id', '=', $receive_id]])->orWhere([['user_id', '=', $receive_id], ['receive_id', '=', $uid]]);
            })->where('source', 1)->pluck('id');
        } else {//群聊信息
            $ids = ChatRecord::whereIn('id', $record_ids)->where('source', 2)->pluck('id');
        }

        // 判断要删除的消息在数据库中是否存在
        if (count($ids) !== count($record_ids)) {
            return false;
        }

        // 判读是否属于群消息并且判断是否是群成员
        if ($source === 2 && !Group::isMember($receive_id, $uid)) {
            return false;
        }

        $data = array_map(static function ($record_id) use ($uid)
        {
            return [
                'record_id'  => $record_id,
                'user_id'    => $uid,
                'created_at' => date('Y-m-d H:i:s'),
            ];
        }, $ids->toArray());

        return Db::table('chat_records_delete')->insert($data);
    }

    /**
     * 撤回单条聊天消息.
     *
     * @param int $uid       用户ID
     * @param int $record_id 聊天记录ID
     */
    public function revokeRecord(int $uid, int $record_id) : array
    {
        /**
         * @var ChatRecord $result
         */
        $result = ChatRecord::where('id', $record_id)->first(['id', 'source', 'user_id', 'receive_id', 'created_at']);
        if (!$result) {
            return [false, '消息记录不存在'];
        }

        //判断是否在两分钟之内撤回消息，超过2分钟不能撤回消息
        if ((time() - strtotime((string)$result->created_at) > 120)) {
            return [false, '已超过有效的撤回时间', []];
        }

        if ($result->source === 1) {
            if ($result->user_id !== $uid && $result->receive_id !== $uid) {
                return [false, '非法操作', []];
            }
        } elseif ($result->source === 2) {
            if (!Group::isMember($result->receive_id, $uid)) {
                return [false, '非法操作', []];
            }
        }

        $result->is_revoke = 1;
        $result->save();

        return [true, '消息已撤回', $result->toArray()];
    }

    /**
     * 转发消息（单条转发）.
     *
     * @param int   $uid         转发的用户ID
     * @param int   $record_id   转发消息的记录ID
     * @param array $receive_ids 接受者数组  例如:[['source' => 1,'id' => 3045],['source' => 1,'id' => 3046],['source' => 1,'id' => 1658]] 二维数组
     *
     * @throws \Exception
     */
    public function forwardRecords(int $uid, int $record_id, array $receive_ids) : array
    {
        /**
         * @var ChatRecord $result
         */
        $result = ChatRecord::where('id', $record_id)->whereIn('msg_type', [1, 2, 5])->first();
        if (!$result) {
            return [];
        }

        // 根据消息类型判断用户是否有转发权限
        if ($result->source === 1) {
            if ($result->user_id !== $uid && $result->receive_id !== $uid) {
                return [];
            }
        } elseif ($result->source === 2) {
            if (!Group::isMember($result->receive_id, $uid)) {
                return [];
            }
        }

        $fileInfo  = null;
        $codeBlock = null;
        if ($result->msg_type === 2) {
            $fileInfo = ChatRecordsFile::where('record_id', $record_id)->first();
        } elseif ($result->msg_type === 5) {
            $codeBlock = ChatRecordsCode::where('record_id', $record_id)->first();
        }

        $insRecordIds = [];
        Db::beginTransaction();
        try {
            foreach ($receive_ids as $item) {
                $res = ChatRecord::create([
                    'source'     => $item['source'],
                    'msg_type'   => $result->msg_type,
                    'user_id'    => $uid,
                    'receive_id' => $item['id'],
                    'content'    => $result->content,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);

                if (!$res) {
                    throw new RuntimeException('插入消息记录失败');
                }

                $insRecordIds[] = $res->id;

                if ($result->msg_type === 2) {
                    if (!ChatRecordsFile::create([
                        'record_id'     => $res->id,
                        'user_id'       => $fileInfo->user_id,
                        'file_source'   => $fileInfo->file_source,
                        'file_type'     => $fileInfo->file_type,
                        'save_type'     => $fileInfo->save_type,
                        'original_name' => $fileInfo->original_name,
                        'file_suffix'   => $fileInfo->file_suffix,
                        'file_size'     => $fileInfo->file_size,
                        'save_dir'      => $fileInfo->save_dir,
                        'created_at'    => date('Y-m-d H:i:s'),
                    ])) {
                        throw new RuntimeException('插入文件消息记录失败');
                    }
                } elseif ($result->msg_type === 5) {
                    if (!ChatRecordsCode::create([
                        'record_id'  => $res->id,
                        'user_id'    => $uid,
                        'code_lang'  => $codeBlock->code_lang,
                        'code'       => $codeBlock->code,
                        'created_at' => date('Y-m-d H:i:s'),
                    ])) {
                        throw new RuntimeException('插入代码消息记录失败');
                    }
                }
            }

            Db::commit();
        } catch (Exception $e) {
            Db::rollBack();
            return [];
        }

        return $insRecordIds;
    }

    /**
     * 转发消息（多条合并转发）.
     *
     * @param int   $uid         转发的用户ID
     * @param int   $receive_id  当前转发消息的所属者(好友ID或者群聊ID)
     * @param int   $source      消息来源  1:好友消息 2:群聊消息
     * @param array $records_ids 转发消息的记录ID
     * @param array $receive_ids 接受者数组  例如:[['source' => 1,'id' => 3045],['source' => 1,'id' => 3046],['source' => 1,'id' => 1658]] 二维数组
     */
    public function mergeForwardRecords(int $uid, int $receive_id, int $source, array $records_ids, array $receive_ids) : array
    {
        // 支持转发的消息类型
        $msg_type = [1, 2, 5];

        $sqlObj = ChatRecord::whereIn('id', $records_ids);

        //验证是否有权限转发
        if ($source === 2) {//群聊消息
            //判断是否是群聊成员
            if (!Group::isMember($receive_id, $uid)) {
                return [];
            }

            $sqlObj = $sqlObj->where('receive_id', $receive_id)->whereIn('msg_type', $msg_type)->where('source', 2)->where('is_revoke', 0);
        } else {//私聊消息
            //判断是否存在好友关系
            if (!UsersFriend::isFriend($uid, $receive_id)) {
                return [];
            }

            $sqlObj = $sqlObj->where(function ($query) use ($uid, $receive_id)
            {
                $query->where([
                    ['user_id', '=', $uid],
                    ['receive_id', '=', $receive_id],
                ])->orWhere([
                    ['user_id', '=', $receive_id],
                    ['receive_id', '=', $uid],
                ]);
            })->whereIn('msg_type', $msg_type)->where('source', 1)->where('is_revoke', 0);
        }

        $result = $sqlObj->get();

        //判断消息记录是否存在
        if (count($result) !== count($records_ids)) {
            return [];
        }

        $rows = ChatRecord::leftJoin('users', 'users.id', '=', 'chat_records.user_id')
                          ->whereIn('chat_records.id', array_slice($records_ids, 0, 3))
                          ->get(['chat_records.msg_type', 'chat_records.content', 'users.nickname']);

        $jsonText = [];
        foreach ($rows as $row) {
            if ($row->msg_type === 1) {
                $jsonText[] = [
                    'nickname' => $row->nickname,
                    'text'     => mb_substr(str_replace(PHP_EOL, '', $row->content), 0, 30),
                ];
            } elseif ($row->msg_type === 2) {
                $jsonText[] = [
                    'nickname' => $row->nickname,
                    'text'     => '【文件消息】',
                ];
            } elseif ($row->msg_type === 5) {
                $jsonText[] = [
                    'nickname' => $row->nickname,
                    'text'     => '【代码消息】',
                ];
            }
        }

        $insRecordIds = [];
        DB::beginTransaction();
        try {
            $jsonText = json_encode($jsonText, JSON_THROW_ON_ERROR);
            foreach ($receive_ids as $item) {
                $res = ChatRecord::create([
                    'source'     => $item['source'],
                    'msg_type'   => 4,
                    'user_id'    => $uid,
                    'receive_id' => $item['id'],
                    'created_at' => date('Y-m-d H:i:s'),
                ]);

                if (!$res) {
                    throw new Exception('插入消息失败');
                }

                $insRecordIds[] = $res->id;

                if (!ChatRecordsForward::create([
                    'record_id'  => $res->id,
                    'user_id'    => $uid,
                    'records_id' => implode(',', $records_ids),
                    'text'       => $jsonText,
                    'created_at' => date('Y-m-d H:i:s'),
                ])) {
                    throw new RuntimeException('插入转发消息失败');
                }
            }

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            return [];
        }

        return $insRecordIds;
    }

    /**
     * 关键词搜索聊天记录.
     *
     * @param int   $uid        用户ID
     * @param int   $receive_id 接收者ID(用户ID或群聊接收ID)
     * @param int   $source     聊天来源（1:私信 2:群聊）
     * @param int   $page       当前查询分页
     * @param int   $page_size  分页大小
     * @param array $params     查询参数
     *
     * @return array
     */
    public function searchRecords(int $uid, int $receive_id, int $source, int $page, int $page_size, array $params) : array
    {
        $fields = [
            'chat_records.id',
            'chat_records.source',
            'chat_records.msg_type',
            'chat_records.user_id',
            'chat_records.receive_id',
            'chat_records.content',
            'chat_records.is_revoke',
            'chat_records.created_at',

            'users.nickname',
            'users.avatar as avatar',
        ];

        $rowsSqlObj = ChatRecord::select($fields)->leftJoin('users', 'users.id', '=', 'chat_records.user_id');
        if ($source === 1) {
            $rowsSqlObj->where(function ($query) use ($uid, $receive_id)
            {
                $query->where([
                    ['chat_records.user_id', '=', $uid],
                    ['chat_records.receive_id', '=', $receive_id],
                ])->orWhere([
                    ['chat_records.user_id', '=', $receive_id],
                    ['chat_records.receive_id', '=', $uid],
                ]);
            });
        } else {
            $rowsSqlObj->where('chat_records.receive_id', $receive_id);
            $rowsSqlObj->where('chat_records.source', $source);
        }

        if (isset($params['keywords'])) {
            $rowsSqlObj->where('chat_records.content', 'like', "%{$params['keywords']}%");
        }

        if (isset($params['date'])) {
            $rowsSqlObj->whereDate('chat_records.created_at', $params['date']);
        }

        $count = $rowsSqlObj->count();
        if ($count === 0) {
            return $this->getPagingRows([], 0, $page, $page_size);
        }

        $rows = $rowsSqlObj->orderBy('chat_records.id', 'desc')->forPage($page, $page_size)->get()->toArray();
        return $this->getPagingRows($this->handleChatRecords($rows), $count, $page, $page_size);
    }

    /**
     * 创建图片消息
     *
     * @param $message
     * @param $fileInfo
     *
     * @return bool|int
     */
    public function createImgMessage($message, $fileInfo)
    {
        Db::beginTransaction();
        try {
            $message['created_at'] = date('Y-m-d H:i:s');
            $insert                = ChatRecord::create($message);

            if (!$insert) {
                throw new Exception('插入聊天记录失败...');
            }

            $fileInfo['record_id']  = $insert->id;
            $fileInfo['created_at'] = date('Y-m-d H:i:s');
            if (!ChatRecordsFile::create($fileInfo)) {
                throw new Exception('插入聊天记录(文件消息)失败...');
            }

            Db::commit();
            return $insert->id;
        } catch (Exception $e) {
            Db::rollBack();
            return false;
        }
    }

    /**
     * 创建代码块消息
     *
     * @param array $message
     * @param array $codeBlock
     *
     * @return bool|int
     */
    public function createCodeMessage(array $message, array $codeBlock)
    {
        Db::beginTransaction();
        try {
            $message['created_at'] = date('Y-m-d H:i:s');
            $insert                = ChatRecord::create($message);
            if (!$insert) {
                throw new Exception('插入聊天记录失败...');
            }

            $codeBlock['record_id']  = $insert->id;
            $codeBlock['created_at'] = date('Y-m-d H:i:s');
            if (!ChatRecordsCode::create($codeBlock)) {
                throw new Exception('插入聊天记录(代码消息)失败...');
            }

            Db::commit();
            return $insert->id;
        } catch (Exception $e) {
            Db::rollBack();
            return false;
        }
    }

    /**
     * 创建表情包消息
     *
     * @param array $message
     * @param array $emoticon
     *
     * @return bool|int
     */
    public function createEmoticonMessage(array $message, array $emoticon)
    {
        Db::beginTransaction();
        try {
            $message['created_at'] = date('Y-m-d H:i:s');
            $insert                = ChatRecord::create($message);
            if (!$insert) {
                throw new Exception('插入聊天记录失败...');
            }

            $emoticon['record_id']  = $insert->id;
            $emoticon['created_at'] = date('Y-m-d H:i:s');
            if (!ChatRecordsFile::create($emoticon)) {
                throw new Exception('插入聊天记录(代码消息)失败...');
            }

            Db::commit();
            return $insert->id;
        } catch (Exception $e) {
            Db::rollBack();
            return false;
        }
    }

    /**
     * 创建文件消息
     *
     * @param array $message
     * @param array $emoticon
     *
     * @return bool|int
     */
    public function createFileMessage(array $message, array $emoticon)
    {
        Db::beginTransaction();
        try {
            $message['created_at'] = date('Y-m-d H:i:s');
            $insert                = ChatRecord::create($message);
            if (!$insert) {
                throw new Exception('插入聊天记录失败...');
            }

            $emoticon['record_id']  = $insert->id;
            $emoticon['created_at'] = date('Y-m-d H:i:s');
            if (!ChatRecordsFile::create($emoticon)) {
                throw new Exception('插入聊天记录(代码消息)失败...');
            }

            Db::commit();
            return $insert->id;
        } catch (Exception $e) {
            Db::rollBack();
            return false;
        }
    }
}
