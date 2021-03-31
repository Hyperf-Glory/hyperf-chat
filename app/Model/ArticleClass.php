<?php

declare (strict_types=1);
namespace App\Model;

/**
 * @property int $id 
 * @property int $uid 
 * @property string $class_name 
 * @property int $sort 
 * @property int $is_default 
 * @property \Carbon\Carbon $created_at 
 */
class ArticleClass extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'article_class';
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['id', 'uid', 'class_name', 'sort', 'is_default', 'created_at'];
    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = ['id' => 'integer', 'uid' => 'integer', 'sort' => 'integer', 'is_default' => 'integer', 'created_at' => 'datetime'];
}