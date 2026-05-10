# Active Record Relations

## Declare Relations

```php
namespace Models;

use Simsoft\ActiveRecord\ActiveRecord;

use Models\Profile;
use Models\Post;

class User extends ActiveRecord
{
    protected string $connection = 'mysql';
    protected string $table = 'user';
    protected string|array $primaryKey = 'id';

    // user has one profile
    public function profile(): ActiveQuery
    {
        return $this->hasOne(Profile::class, 'user_id', 'id');
    }

    // user has many posts.
    public function posts(): ActiveQuery
    {
        return $this->hasMany(Post::class, 'user_id', 'id')
    }

    // user has many active posts.
    public function activePosts(): ActiveQuery
    {
        return $this->hasMany(Post::class, 'user_id', 'id')->where('status', 'active')
    }
}
```

## Accessing Relation Record

```php
$user = User::findByPk(1);

echo $user->profile->position;
echo $user->profile->title;

foreach($user->posts as $post) {
    echo $post->title;
    echo $post->content;
}
```

## Filter Relation Record

```php
$posts = $user->posts()->where('status', 'active')->get();
foreach($posts as $post) {
    echo $post->title;
    echo $post->content;
}
```
