<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class Post extends Model
{
    protected string $table = 'posts';
    protected array $fillable = ['title', 'slug', 'excerpt', 'content', 'image', 'status', 'featured', 'category_id', 'tags_json', 'author_name', 'published_at'];
}
