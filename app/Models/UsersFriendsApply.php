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
namespace App\Models;

/**
 * @property int $id
 * @property int $user_id
 * @property int $friend_id
 * @property int $status
 * @property string $remarks
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class UsersFriendsApply extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'users_friends_apply';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'id',
        'user_id',
        'friend_id',
        'status',
        'remarks',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = ['id' => 'integer', 'user_id' => 'integer', 'friend_id' => 'integer', 'status' => 'integer', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
}
